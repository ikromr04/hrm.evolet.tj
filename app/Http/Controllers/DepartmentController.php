<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\Equipment;
use App\Models\Position;
use App\Models\User;
use App\Support\Directories;
use App\Support\EmployeeFields;
use App\Support\EquipmentAccess;
use App\Support\EquipmentName;
use App\Support\Profile;
use App\Support\Regulation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
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

    /**
     * One department: who works there, its documents and what it has.
     *
     * Every prop but the first is written as a closure, so that a visit asking
     * for one of them by name — which is what the equipment tab does when it is
     * opened — reads that one and nothing else. A full visit resolves them all,
     * exactly as before.
     */
    public function show(Request $request, Department $department): Response
    {
        return Inertia::render('departments/show', [
            'department' => fn () => $this->people($department),
            // The row as it came from the address, not the one out of the tree:
            // the tree reads the few columns a chart draws with, and the
            // document is read for this department by name.
            'regulation' => fn () => $this->regulation($department),
            // And the other document the department keeps: «СФД», the same nine
            // sections a position's profile is made of, written about the unit.
            // The tab held the list of this department's jobs until the company
            // said what it wanted there — the sheet itself — and a position's
            // own profile is read on the position's page, as it always was.
            'profile' => fn () => $this->profile($department),
            // Whether the third tab has anything to offer this viewer, which
            // is all it needs to know before it is opened: a right, not a
            // query. The equipment itself costs a page of reads and is asked
            // for only by somebody who presses the tab.
            'equipment' => ['can_read' => EquipmentAccess::viewScopes($request->user()) !== []],
            // Whether the column beside the document offers the way into
            // «Изменить отдел», which is all the page needs before somebody
            // presses it: the right, and not the list of everybody who could
            // lead this department.
            'editor' => ['can_edit' => Gate::allows('directories.manage.departments')],
            // And that list, left out of every visit that does not ask for it
            // by name — the same way the equipment below is. The window is
            // opened by the few who keep the structure; the page is opened by
            // everybody signed in, mostly to read a document, and every
            // employee in the company is a heavy thing to send them.
            'editorOptions' => Inertia::optional(fn () => $this->editor($department)),
            // And the list behind it, left out of every visit that does not
            // ask for it by name. This page is opened by everybody signed in,
            // mostly to read the regulation, and a department with a hundred
            // units would make that page heavy for all of them.
            //
            // A prop of its own rather than a line inside the one above,
            // because Inertia leaves a prop out of the first load only where
            // the prop itself is the one being skipped — nested inside an
            // array it would be resolved like anything else, and the saving
            // would be nothing.
            'equipmentUnits' => Inertia::optional(fn () => $this->equipment($department, $request->user())),
            // The two departments this page stands between, so the structure can
            // be read through without going back to the list for every unit.
            'neighbours' => fn () => $this->neighbours($department),
        ]);
    }

    /**
     * The jobs of a department and of every unit under it, filed by the unit
     * they belong to: this department's own first, then each unit's in the order
     * the structure reads them. Inside a unit the job that leads it comes first
     * — it is what the rest of that run works under — and the others follow by
     * name. Each is named the way the staff table names a job — the
     * title with its department in brackets — because the list is one and a
     * reader has to know whose job it is.
     *
     * How many people hold a job is counted with it: a position nobody holds is
     * a different thing from one three people share, and that is the only fact
     * about it this column has room for.
     *
     * @param  Collection<int, Department>  $departments
     * @return list<array<string, mixed>>
     */
    private function positions(Collection $departments, Department $current): array
    {
        $branch = $this->branch($departments, $current->id);
        $rank = array_flip($branch);

        return Position::query()
            ->whereIn('department_id', $branch)
            ->with('department:id,name,abbreviation')
            ->withCount(['users' => fn ($query) => $query->active()])
            ->orderBy('name')
            ->get(['id', 'name', 'department_id', 'is_head'])
            ->sortBy(fn (Position $position) => [$rank[$position->department_id] ?? PHP_INT_MAX, $position->is_head ? 0 : 1, $position->name])
            ->map(fn (Position $position) => [
                'id' => $position->id,
                'name' => $position->name,
                'unit' => $position->unit(),
                'users_count' => $position->users_count,
            ])
            ->values()
            ->all();
    }

    /**
     * A department and everything filed under it, by id: the branch read whole,
     * which is what a count «вместе с подотделами» is taken over.
     *
     * @param  Collection<int, Department>  $departments
     * @return list<int>
     */
    private function branch(Collection $departments, int $id): array
    {
        $children = $departments->groupBy('parent_id');

        /** @var list<int> $ids */
        $ids = [];
        // Guarded against a department filed under itself, which the data should
        // not hold and which would otherwise walk for ever.
        $walk = function (int $at) use (&$walk, &$ids, $children): void {
            if (in_array($at, $ids, true)) {
                return;
            }

            $ids[] = $at;

            foreach ($children->get($at, collect()) as $child) {
                $walk($child->id);
            }
        };
        $walk($id);

        return $ids;
    }

    /**
     * The department before and after this one, in the order «Подразделения»
     * writes them down: the tree read down the page — a department, then the
     * units filed under it, each level by name. The buttons walk that order,
     * so pressing «Следующее» goes where the eye would go in the list.
     *
     * @return array{prev: array{id: int, name: string}|null, next: array{id: int, name: string}|null}
     */
    private function neighbours(Department $department): array
    {
        $all = Department::query()->orderBy('name')->get(['id', 'name', 'parent_id']);
        $ids = $all->pluck('id')->all();

        /** @var list<Department> $flat */
        $flat = [];
        // A unit whose department is not in the list — there is none today, and
        // one orphaned by a delete would otherwise fall out of the order
        // altogether — is read as a department of its own, as the list reads it.
        $walk = function (?int $parent) use (&$walk, &$flat, $all, $ids): void {
            foreach ($all as $item) {
                $root = $item->parent_id === null || ! in_array($item->parent_id, $ids, true);
                $belongs = $parent === null ? $root : $item->parent_id === $parent;

                if (! $belongs) {
                    continue;
                }

                $flat[] = $item;
                $walk($item->id);
            }
        };
        $walk(null);

        $at = array_search($department->id, array_column($flat, 'id'), true);
        $card = fn (?Department $unit) => $unit ? ['id' => $unit->id, 'name' => $unit->name] : null;

        return [
            'prev' => $card($at !== false && $at > 0 ? $flat[$at - 1] : null),
            'next' => $card($at !== false ? ($flat[$at + 1] ?? null) : null),
        ];
    }

    /**
     * «Положение о работе» as this department reads it: the one text of the
     * shared template, or this department's own wording of it, with the
     * placeholders answered — the name of the sheet among them, because that is
     * the first line of the text and not a heading kept anywhere else.
     *
     * The body comes twice: as it is read, with this department's name in the
     * place of the placeholders, and as it was written, braces and all, which is
     * what the editor has to put back in front of whoever edits it. A section
     * says which of the three it is, so the page can tell «this is the company's
     * text» from «this department rewrote it» without working it out.
     *
     * @return array<string, mixed>
     */
    private function regulation(Department $department): array
    {
        return [
            // Keeping the regulation is keeping the department: the right that
            // writes the structure down in «Подразделения» writes this down too.
            // Reading it takes nothing — this page is open to everybody.
            'can_edit' => Gate::allows('directories.manage.departments'),
            // The one name the document needs: its sentences are written so
            // that the nominative reads correctly in every one of them.
            'names' => ['nominative' => $department->name],
            // What may be typed into the text, and what each one reads as here.
            'placeholders' => Regulation::offer($department),
            // The document itself, as a position's page reads it too.
            ...Regulation::paper($department),
        ];
    }

    /**
     * «СФД» as this department keeps it: the one text of the shared template,
     * or this department's own wording of it, opening with the name of the
     * sheet — and beside it what the application knows about the unit, which
     * the column at the side of the page reads.
     *
     * The document and not a list of the jobs under it. The tab listed this
     * department's positions until the company said that a unit's СФД is the
     * same sheet a job has; a position's own profile is still read, and
     * written, on that position's page, and nothing here sends it.
     *
     * The same shape a position's page sends its profile in, because it is the
     * same document — Profile puts both together — and the same shape the
     * regulation above travels in, so one component on the page reads any of
     * the three.
     *
     * @return array<string, mixed>
     */
    private function profile(Department $department): array
    {
        return [
            // Keeping this sheet is keeping the department, the way its
            // «Положение о работе» is: the right that writes the structure down
            // in «Подразделения» writes this down too, and not the right that
            // keeps the list of job titles — the sheet is the department's own.
            // Reading it takes nothing; this page is open to everybody.
            'can_edit' => Gate::allows('directories.manage.departments'),
            ...Profile::paper($department),
        ];
    }

    /**
     * The department itself: where it sits, who leads it, what is under it and
     * who works there.
     *
     * Read through the tree rather than off the row, because the page says how
     * many people each sub-department has, and a count of a branch is a walk
     * down it.
     *
     * @return array<string, mixed>
     */
    private function people(Department $department): array
    {
        $departments = $this->tree();
        $totals = Department::staffTotals($departments);
        $current = $departments->firstWhere('id', $department->id);

        // Everybody working in this department and in the units under it, filed
        // by the unit they work in — the department's own first, then each unit
        // in the order the structure reads — and whoever leads a unit at the head
        // of its run, the way the jobs beside them are read.
        //
        // Somebody filed in two of these departments at once is one row, under
        // the first of them the structure reaches.
        $branch = $this->branch($departments, $current->id);
        $rank = array_flip($branch);

        $members = User::query()
            ->active()
            ->whereHas('departments', fn (Builder $query) => $query->whereIn('departments.id', $branch))
            ->with(['positions:id,name,department_id', 'positions.department:id,name,abbreviation', 'departments:id,name,abbreviation'])
            ->orderBy('surname')
            ->orderBy('name')
            ->get(['users.id', 'name', 'surname', 'patronymic', 'avatar', 'avatar_original', 'email'])
            ->map(function (User $user) use ($rank, $current) {
                $unit = $user->departments
                    ->filter(fn (Department $d) => isset($rank[$d->id]))
                    ->sortBy(fn (Department $d) => $rank[$d->id])
                    ->first();

                $user->setAttribute('branch_rank', $rank[$unit?->id] ?? PHP_INT_MAX);
                $user->setAttribute('branch_head', (bool) $unit?->pivot?->is_head);
                // Null on this department's own people: the page is already its.
                $user->setAttribute('branch_unit', $unit && $unit->id !== $current->id ? $unit->display_name : null);

                return $user;
            })
            ->sortBy(fn (User $user) => [$user->branch_rank, $user->branch_head ? 0 : 1, $user->surname, $user->name])
            ->values();

        $parents = [];
        for ($parent = $departments->firstWhere('id', $current->parent_id); $parent && ! isset($parents[$parent->id]); $parent = $departments->firstWhere('id', $parent->parent_id)) {
            $parents[$parent->id] = ['id' => $parent->id, 'name' => $parent->display_name, 'full_name' => $parent->name];
        }

        return [
            'id' => $current->id,
            // Both names: a department's own page has room to say what its
            // abbreviation stands for, and everywhere it is short the full
            // one is what the hover and the screen reader are given.
            'name' => $current->display_name,
            'full_name' => $current->name,
            'parents' => array_reverse(array_values($parents)),
            'total_count' => $totals[$current->id],
            // The jobs of this department and of every unit under it, read the
            // way the staff above is: a department is read with what hangs off
            // it. The ones of a unit say whose they are, since the list is one
            // and the department it is read on is not the only one in it.
            'positions' => $this->positions($departments, $current),
            'heads' => $this->heads($current)
                ->load(['positions:id,name,department_id', 'positions.department:id,name,abbreviation'])
                ->map(fn (User $u) => $this->person($u, details: true))
                ->values(),
            'children' => $departments->where('parent_id', $current->id)->map(fn (Department $d) => [
                'id' => $d->id,
                'name' => $d->display_name,
                'full_name' => $d->name,
                'total_count' => $totals[$d->id],
                'heads' => $this->heads($d)->map(fn (User $u) => $this->person($u))->values(),
            ])->values(),
            'members' => $members->map(fn (User $u) => [
                ...$this->person($u, details: true),
                'unit' => $u->branch_unit,
                // Whoever leads the unit they are filed under, which is how the
                // phone tells its own «Руководитель» from the staff below it.
                'is_head' => $u->branch_head,
            ]),
        ];
    }

    /**
     * What «Изменить отдел» needs and the page itself does not: this department
     * as that window reads a record, with the positions filed in it, and the
     * departments it could be put under.
     *
     * The window is the one «Подразделения» opens, and it saves through that
     * same address, so it is given the same shape the list gives it — the full
     * name among it, because the window is where the short name is written.
     *
     * Refused outright to anybody who may not keep the structure. The prop is
     * asked for by name and a request can be written by hand, so the right is
     * checked here and not only where the control is drawn: the answer names
     * every position of the department and says which of them lead it.
     *
     * @return array<string, mixed>|null
     */
    private function editor(Department $department): ?array
    {
        if (! Gate::allows('directories.manage.departments')) {
            return null;
        }

        return [
            'department' => [
                'id' => $department->id,
                'name' => $department->name,
                'abbreviation' => $department->abbreviation,
                'parent_id' => $department->parent_id,
            ],
            // Where it could sit. The whole tree rather than a list of
            // candidates: the window works out for itself which placements the
            // two levels allow, and it is the same window the list opens.
            'departments' => Department::query()
                ->orderBy('name')
                ->get(['id', 'name', 'abbreviation', 'parent_id'])
                ->map(fn (Department $d) => [
                    'id' => $d->id,
                    'name' => $d->name,
                    'abbreviation' => $d->abbreviation,
                    'parent_id' => $d->parent_id,
                ]),
        ];
    }

    /**
     * The equipment of this department, in the two halves it is actually in:
     * the units signed out to the department itself, and the units of the
     * people who work there, each under the name of whoever holds it.
     *
     * Only this department, not the branch under it. A unit of the structure
     * has its own page and its own people, and a department that showed its
     * units' equipment would answer for rows nobody on this page is named in
     * — the same reason «СФД» lists this department's own positions and not
     * its sub-departments'.
     *
     * What the equipment section would show this viewer and no more: the same
     * `EquipmentAccess` that narrows the fleet narrows this, so somebody who
     * may see only their own equipment sees their own here, and the page can
     * neither widen a right nor leak a unit the section would keep back.
     *
     * A unit nobody holds is in neither half: it is on the balance sheet or
     * struck off, and a thing in no one's hands is no department's either.
     * That is why nothing here asks about a status — the question is answered
     * by who holds the thing.
     *
     * @return array{own: list<array<string, mixed>>, people: list<array<string, mixed>>}
     */
    private function equipment(Department $department, User $viewer): array
    {
        $units = Equipment::query()
            ->tap(fn (Builder $query) => EquipmentAccess::narrow($query, $viewer))
            ->filedIn([$department->id])
            // The two a unit is named by, and the holder behind the second half.
            ->withIdentity()
            ->with(['type:id,name,icon', 'holder:id,name,surname'])
            ->orderByRole('title')
            ->orderBy('id')
            ->get(['id', 'equipment_type_id', 'holder_user_id', 'holder_department_id'])
            ->map(fn (Equipment $unit) => [
                'id' => $unit->id,
                // Named as every list of equipment names a unit, nameless
                // ones included: one unit reads the same wherever it is read.
                'name' => EquipmentName::read($unit->name),
                'inventory_number' => EquipmentName::readNumber($unit->inventory_number),
                'type' => $unit->type?->name,
                'type_icon' => $unit->type?->icon,
                // Only in the second half, where it is the point of the line.
                'holder' => $unit->holder === null ? null : [
                    'id' => $unit->holder->id,
                    'name' => "{$unit->holder->surname} {$unit->holder->name}",
                ],
            ]);

        return [
            'own' => $units->whereNull('holder')->values()->all(),
            // By the person first, so one colleague's things are read together
            // rather than scattered through the list by what they are called.
            'people' => $units->whereNotNull('holder')
                ->sortBy(fn (array $unit) => $unit['holder']['name'])
                ->values()
                ->all(),
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
            ->with(['users' => fn ($q) => $q->active()->select('users.id', 'users.name', 'users.surname', 'users.avatar', 'users.avatar_original', 'users.email')->orderBy('surname')->orderBy('name')])
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
            // The upload behind it, which is what pressing the face opens.
            'avatar_original' => EmployeeFields::showsAvatar(request()->user(), $user) ? $user->avatar_original : null,
            ...($details ? [
                'email' => $user->email,
                // Named and, for whoever may open one, linked: the page
                // that listed this department's positions is gone, and a job
                // title in a colleague's card is the way back to it. The id is
                // withheld from somebody who may not read the list, so the
                // badge stays a word rather than becoming a link into a 403.
                'positions' => $user->positions
                    ->sortBy('name')
                    ->map(fn (Position $position) => [
                        'id' => Gate::allows(Directories::viewPermission('positions')) ? $position->id : null,
                        'name' => $position->name,
                        // Which unit the job belongs to, named both ways: the
                        // column reads a department with its units at once, so a
                        // job says whose it is, and three letters say nothing
                        // without the name behind them.
                        'unit' => $position->unit(),
                    ])
                    ->values(),
            ] : []),
        ];
    }
}
