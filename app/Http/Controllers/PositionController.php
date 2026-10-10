<?php

namespace App\Http\Controllers;

use App\Models\Position;
use App\Models\PositionDuty;
use App\Models\User;
use App\Support\Directories;
use App\Support\EmployeeFields;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The job titles people hold, shown in the UI as "Должности", with what each one
 * answers for.
 *
 * A section of its own rather than a tab of "Справочники": a position is read far
 * more often than it is changed — who holds it, and what they are responsible for
 * — so it has a list like the employees and the equipment, and a page of its own
 * per entry. The two rights it is read and kept by stay the ones the access table
 * has always named it with.
 */
class PositionController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        // The holders named on a row are the staff list read a position at a
        // time, so a row asks the same right a position's own page asks. The
        // page is told outright, or "nobody holds this position" and "the
        // holders are not yours to read" would be the same empty list; told, it
        // falls back to the count.
        $showsEmployees = $this->showsEmployees($user);

        return Inertia::render('positions/index', [
            'positions' => Position::query()
                ->with('duties')
                // Counts match the employee list the number links to: working
                // staff only.
                ->withCount(['users' => fn (Builder $query) => $query->active()])
                // The holders of every position in one query rather than one per
                // row, and in the order a position's own page lists them, so the
                // two pages never disagree about who holds what.
                ->when($showsEmployees, fn (Builder $query) => $query->with([
                    'users' => fn ($holders) => $holders
                        ->active()
                        ->orderBy('surname')
                        ->orderBy('name')
                        ->select(['users.id', 'users.name', 'users.surname']),
                ]))
                // The page opens the list newest first and reorders it there
                // itself, so this is only a settled order to hand it over in: a
                // list the server leaves alone comes out differently run to run.
                ->orderBy('name')
                ->get(['id', 'name', 'created_at'])
                ->map(fn (Position $position) => [
                    'id' => $position->id,
                    'name' => $position->name,
                    // What "newest first" is read from — the order the list opens in.
                    'created_at' => $position->created_at?->toIso8601String(),
                    'duties' => $position->duties->pluck('name')->all(),
                    'employees_count' => $position->users_count,
                    'employees' => $showsEmployees
                        ? $position->users->map(fn (User $employee) => [
                            'id' => $employee->id,
                            // "Фамилия Имя", as every other list spells it.
                            'name' => "{$employee->surname} {$employee->name}",
                        ])->values()
                        : [],
                ]),
            // Reading a list and keeping it are two rights, so the page says
            // which one it is looking at.
            'canEdit' => Directories::canEdit($user, 'positions'),
            // The count leads to the staff list narrowed by this position, so it
            // takes both the list and the line it is narrowed by: that list is
            // not open to everybody, and it refuses to be narrowed by a line the
            // viewer may not read. A link into a refusal is worse than no link.
            'canSeeEmployees' => $user->can('employees.view') && $user->can(EmployeeFields::permission('positions')),
            'showsEmployees' => $showsEmployees,
        ]);
    }

    public function show(Request $request, Position $position): Response
    {
        $user = $request->user();
        // Which positions somebody holds is a line of their card, and that line
        // has a right of its own: without it the page names the holders and says
        // nothing about what each of them is carrying besides.
        $showsPositions = $user->can(EmployeeFields::permission('positions'));
        // A list of colleagues is not open to everybody. The page is told so
        // rather than working it out from the rights itself, or "nobody holds
        // this position" and "the holders are not yours to read" would be the
        // same empty list.
        $showsEmployees = $this->showsEmployees($user);

        return Inertia::render('positions/show', [
            'position' => [
                'id' => $position->id,
                'name' => $position->name,
                'duties' => $position->duties->map(fn (PositionDuty $duty) => [
                    'id' => $duty->id,
                    'name' => $duty->name,
                ])->values(),
            ],
            'employees' => $showsEmployees ? $this->holders($position, $user, $showsPositions) : [],
            'canEdit' => Directories::canEdit($user, 'positions'),
            'canOpenCards' => $showsEmployees,
            'showsEmployees' => $showsEmployees,
            'showsPositions' => $showsPositions,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $position = Position::create(['name' => $data['name']]);
        $this->writeDuties($position, $data['duties'] ?? []);

        return back();
    }

    public function update(Request $request, Position $position): RedirectResponse
    {
        $data = $this->validated($request, $position);

        $position->update(['name' => $data['name']]);

        // Saying nothing about the duties leaves them alone; sending a list —
        // even an empty one — is what replaces them, because the form hands back
        // the whole list and a duty it no longer shows is one somebody deleted.
        if (array_key_exists('duties', $data)) {
            $this->writeDuties($position, $data['duties']);
        }

        return back();
    }

    public function destroy(Position $position): RedirectResponse
    {
        // Employees keep their other positions; only this link goes, and the
        // duties of the position go with it.
        $position->delete();

        return back();
    }

    /**
     * Whether a list of colleagues is this viewer's to read at all.
     *
     * The list names the holders of every position and a position's own page
     * names its own, so both ask the question here: written out twice, one of
     * the two would sooner or later name somebody the other hides.
     */
    private function showsEmployees(User $viewer): bool
    {
        return $viewer->can('employees.view');
    }

    /**
     * Everybody who still works here and holds this position, by surname.
     *
     * Each of them comes with the duties of all their positions rather than of
     * this one alone: a page about a position is also the one place where it is
     * plain what else the people in it are carrying, and the page tells the two
     * apart by the position a duty belongs to.
     *
     * @return list<array<string, mixed>>
     */
    private function holders(Position $position, User $viewer, bool $showsPositions): array
    {
        return User::query()
            ->active()
            ->whereHas('positions', fn (Builder $query) => $query->whereKey($position->id))
            // Everything the page reads about the people in two queries rather
            // than in two per row.
            ->when($showsPositions, fn (Builder $query) => $query->with('positions.duties'))
            ->orderBy('surname')
            ->orderBy('name')
            ->get(['id', 'name', 'surname', 'avatar', 'sex', 'status'])
            ->map(fn (User $employee) => $this->person($employee, $viewer, $showsPositions))
            ->all();
    }

    /**
     * One holder as the page reads them.
     *
     * @return array<string, mixed>
     */
    private function person(User $employee, User $viewer, bool $showsPositions): array
    {
        // A name is nobody's secret; the lines around it are each a right, and a
        // line this viewer may not read travels as nothing at all.
        $visible = EmployeeFields::visibleTo($viewer, $employee);

        return [
            'id' => $employee->id,
            // "Фамилия Имя", as every other list in the project spells it.
            'name' => "{$employee->surname} {$employee->name}",
            'avatar' => in_array('avatar', $visible, true) ? $employee->avatar : null,
            // What the page writes the status in: «Уволена» or «Уволен», the way
            // the rest of the project writes it.
            'sex' => in_array('sex', $visible, true) ? $employee->sex : null,
            'status' => $employee->status,
            // Which positions somebody holds is a line of the card too, and the
            // duties hang off the positions: closed, the page says what the
            // position answers for and nothing about who answers for what.
            'positions' => $showsPositions
                ? $employee->positions->map(fn (Position $held) => ['id' => $held->id, 'name' => $held->name])->values()
                : [],
            'duties' => $showsPositions
                ? $employee->positions->flatMap(fn (Position $held) => $held->duties->map(fn (PositionDuty $duty) => [
                    'id' => $duty->id,
                    'name' => $duty->name,
                    'position_id' => $held->id,
                ]))->values()
                : [],
        ];
    }

    /**
     * The duties as the form left them, in the order it sent them. The rows a
     * position had are written afresh: nothing points at a duty, and a list that
     * is read back in the order it was entered is simplest kept in that order.
     *
     * @param  list<string>  $duties
     */
    private function writeDuties(Position $position, array $duties): void
    {
        $position->duties()->delete();

        $duties = array_values($duties);

        $position->duties()->createMany(array_map(
            fn (string $name, int $order) => ['name' => $name, 'order' => $order],
            $duties,
            array_keys($duties),
        ));
    }

    /**
     * @return array{name: string, duties?: list<string>}
     */
    private function validated(Request $request, ?Position $position = null): array
    {
        $this->trimDuties($request);

        return $request->validate([
            'name' => ['required', 'string', 'max:150', Rule::unique('positions', 'name')->ignore($position)],
            // A position nobody has written the duties of yet is a position all
            // the same, so saying nothing about them is not an omission.
            'duties' => ['sometimes', 'array'],
            'duties.*' => ['required', 'string', 'max:300'],
        ], attributes: [
            'name' => 'название',
            'duties' => 'обязанности',
            'duties.*' => 'обязанность',
        ]);
    }

    /**
     * The duties trimmed and the blank ones dropped before any rule sees them: a
     * line left empty in the form is not something somebody asked to save, so it
     * is nothing to put an error in front of them for. Anything that is not a
     * list of strings is left as it came, for the rules to refuse.
     */
    private function trimDuties(Request $request): void
    {
        if (! is_array($sent = $request->input('duties'))) {
            return;
        }

        $duties = array_map(fn (mixed $duty) => is_string($duty) ? trim($duty) : $duty, $sent);

        $request->merge(['duties' => array_values(array_filter(
            $duties,
            fn (mixed $duty) => $duty !== '' && $duty !== null,
        ))]);
    }
}
