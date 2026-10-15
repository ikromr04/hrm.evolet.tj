<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\Position;
use App\Models\RegulationSection;
use App\Models\User;
use App\Support\Directories;
use App\Support\Document;
use App\Support\EmployeeFields;
use App\Support\PositionProfile;
use App\Support\Regulation;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The company structure as every employee sees it: only public data and only
 * people who still work here. Editing lives in the directories.
 */
class DepartmentController extends Controller
{
    public function index(): Response
    {
        $departments = $this->tree();
        $totals = Department::staffTotals($departments);

        return Inertia::render('departments/index', [
            'departments' => $departments->map(fn (Department $d) => $this->chartNode($d, $totals))->values(),
            'employees_count' => User::active()->count(),
        ]);
    }

    public function show(Department $department): Response
    {
        $departments = $this->tree();
        $totals = Department::staffTotals($departments);
        $current = $departments->firstWhere('id', $department->id);

        $members = $current->users()
            ->active()
            ->wherePivot('is_head', false)
            ->with('positions:id,name')
            ->orderBy('surname')
            ->orderBy('name')
            ->get(['users.id', 'name', 'surname', 'patronymic', 'avatar', 'email']);

        $parents = [];
        for ($parent = $departments->firstWhere('id', $current->parent_id); $parent && ! isset($parents[$parent->id]); $parent = $departments->firstWhere('id', $parent->parent_id)) {
            $parents[$parent->id] = ['id' => $parent->id, 'name' => $parent->display_name, 'full_name' => $parent->name];
        }

        return Inertia::render('departments/show', [
            'department' => [
                'id' => $current->id,
                // Both names: a department's own page has room to say what its
                // abbreviation stands for, and everywhere it is short the full
                // one is what the hover and the screen reader are given.
                'name' => $current->display_name,
                'full_name' => $current->name,
                'parents' => array_reverse(array_values($parents)),
                'total_count' => $totals[$current->id],
                'heads' => $this->heads($current)->load('positions:id,name')->map(fn (User $u) => $this->person($u, details: true))->values(),
                'children' => $departments->where('parent_id', $current->id)->map(fn (Department $d) => [
                    'id' => $d->id,
                    'name' => $d->display_name,
                    'full_name' => $d->name,
                    'total_count' => $totals[$d->id],
                    'heads' => $this->heads($d)->map(fn (User $u) => $this->person($u))->values(),
                ])->values(),
                'members' => $members->map(fn (User $u) => $this->person($u, details: true)),
            ],
            // The row as it came from the address, not the one out of the tree:
            // the tree reads the few columns a chart draws with, and the
            // document needs the heading this department typed as well.
            'regulation' => $this->regulation($department),
            // The other document of this department's people: one profile per
            // job rather than one sheet for the whole unit, so the page gets
            // the list and each position's own page gets its document.
            'profiles' => $this->profiles($department),
        ]);
    }

    /**
     * «Положение о работе» as this department reads it: the standard sections of
     * the shared template, each replaced by this department's wording where it
     * wrote one — in one order, with the placeholders answered, under the
     * heading this department prints it with.
     *
     * The body comes twice: as it is read, with this department's name in the
     * place of the placeholders, and as it was written, braces and all, which is
     * what the editor has to put back in front of whoever edits it. A section
     * says which of the three it is, so the page can tell «this is the company's
     * text» from «this department rewrote it» without working it out. And its
     * number is the place it is read in, counted here, because one document is
     * assembled out of two scopes and a number means nothing in either of them
     * alone.
     *
     * @return array<string, mixed>
     */
    private function regulation(Department $department): array
    {
        $values = Regulation::values($department);

        return [
            // Keeping the regulation is keeping the department: the right that
            // writes the structure down in «Подразделения» writes this down too.
            // Reading it takes nothing — this page is open to everybody.
            'can_edit' => Gate::allows('directories.manage.departments'),
            // The heading of the sheet, resolved: what this department typed,
            // or the name of the document and its own name while nobody has
            // typed anything. One string, ready to print.
            'title' => Regulation::title($department),
            // The one name the document needs: its sentences are written so
            // that the nominative reads correctly in every one of them.
            'names' => ['nominative' => $department->name],
            // What may be typed into the text, and what each one reads as here.
            'placeholders' => Regulation::offer($department),
            'sections' => RegulationSection::forDepartment($department)->map(fn (RegulationSection $section, int $place) => [
                'id' => $section->id,
                // "template" — the company's own text, read as it is written;
                // "override" — this department's wording of a standard section;
                // "own" — a section no template has.
                'origin' => $section->origin(),
                'template_section_id' => $section->template_section_id,
                // Worked out here and stored nowhere: the number is the place
                // the section is read in this department's document, and one
                // section of the template is read at a different place in every
                // department's.
                'number' => RegulationSection::number($place),
                'title' => $section->title,
                'order' => $section->order,
                // Sanitized where it was written, so it is already safe to put
                // on the page as the markup it is.
                'body' => Document::resolve($section->body, $values),
                // The same body as somebody typed it, for the editor.
                'body_written' => $section->body,
            ])->values(),
        ];
    }

    /**
     * «СФД» on a department's page: the positions of this department, each with
     * the heading of the sheet it is read under.
     *
     * The list and not the documents. «Профиль должности» belongs to a position
     * and is read, and written, on that position's own page — the way the
     * regulation is read on the department's — and a department of thirty jobs
     * would otherwise hand this page thirty documents of nine sections each,
     * every time somebody opens it to look at the staff. So the tab lists the
     * jobs and each of them is one step away.
     *
     * Its own positions only: a sub-department's jobs are listed on the
     * sub-department's page, which is also where its own regulation is.
     *
     * The list is a page of the job titles, so it takes the right that opens
     * that page. Everybody signed in reads a department, not everybody reads the
     * directory of positions, and the tab is told outright rather than left to
     * work it out from an empty list — "nobody holds a position here" and "the
     * positions are not yours to read" are not the same thing.
     *
     * @return array<string, mixed>
     */
    private function profiles(Department $department): array
    {
        $reads = Gate::allows(Directories::viewPermission('positions'));

        return [
            'can_read' => $reads,
            // Keeping a profile is keeping the position it belongs to, which is
            // a right of the job titles and not of the structure.
            'can_edit' => Gate::allows('directories.manage.positions'),
            'positions' => $reads
                ? $department->positions()->get(['id', 'name', 'profile_title'])->map(fn (Position $position) => [
                    'id' => $position->id,
                    // The plain name: the department is the page this is read
                    // on, so there is nothing to tell apart in brackets.
                    'name' => $position->name,
                    // What the sheet is headed, so the tab names the document
                    // rather than guessing at it.
                    'title' => PositionProfile::title($position),
                ])->values()
                : [],
        ];
    }

    /**
     * One box of a chart: public data of working people only.
     *
     * @param  array<int, int>  $totals
     * @return array<string, mixed>
     */
    private function chartNode(Department $department, array $totals): array
    {
        return [
            'id' => $department->id,
            'name' => $department->display_name,
            'full_name' => $department->name,
            'parent_id' => $department->parent_id,
            'total_count' => $totals[$department->id],
            'heads' => $this->heads($department)->map(fn (User $u) => $this->person($u))->values(),
            // Everyone else in this department itself, by surname; sub-departments list their own.
            'members' => $department->users->reject(fn (User $u) => $u->pivot->is_head)->map(fn (User $u) => $this->person($u))->values(),
        ];
    }

    /**
     * Every department in name order, with its working members and their
     * head flag; the tree is small enough to handle in memory.
     *
     * @return Collection<int, Department>
     */
    private function tree(): Collection
    {
        return Department::query()
            ->with(['users' => fn ($q) => $q->active()->select('users.id', 'users.name', 'users.surname', 'users.avatar', 'users.email')->orderBy('surname')->orderBy('name')])
            ->orderBy('name')
            ->get(['id', 'name', 'abbreviation', 'parent_id']);
    }

    /**
     * @return Collection<int, User>
     */
    private function heads(Department $department): Collection
    {
        return $department->users->filter(fn (User $u) => $u->pivot->is_head)->values();
    }

    /**
     * @return array<string, mixed>
     */
    private function person(User $user, bool $details = false): array
    {
        return [
            'id' => $user->id,
            'name' => "{$user->surname} {$user->name}",
            // A name is nobody's secret; a face is a line of a card like any other.
            'avatar' => EmployeeFields::showsAvatar(request()->user(), $user) ? $user->avatar : null,
            ...($details ? [
                'email' => $user->email,
                'positions' => $user->positions->pluck('name')->sort()->values(),
            ] : []),
        ];
    }
}
