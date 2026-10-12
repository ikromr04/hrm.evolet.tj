<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\Position;
use App\Models\PositionDuty;
use App\Models\User;
use App\Support\Directories;
use App\Support\EmployeeFields;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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
 *
 * A duty may name a department, because an analyst in one department does not
 * answer for what an analyst in another answers for. The pages read the duties of
 * a position in groups: the common ones, which everybody holding the position
 * carries, and then one group per department that adds something of its own.
 */
class PositionController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $departments = $this->departments();
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
                    'duties' => $this->commonDuties($position)->pluck('name')->all(),
                    'department_duties' => $this->departmentDuties(
                        $position,
                        $departments,
                        fn (PositionDuty $duty) => $duty->name,
                    ),
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
            // The editor writes the duties here, so it needs the departments one
            // may be pinned to. Naming a department on a duty is part of keeping
            // the position and asks no right of its own; the picker is sent to
            // everybody, and the form behind it is what is gated.
            'options' => [
                'departments' => $this->departmentOptions($departments),
            ],
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
                'duties' => $this->commonDuties($position)->map(fn (PositionDuty $duty) => [
                    'id' => $duty->id,
                    'name' => $duty->name,
                ])->values(),
                'department_duties' => $this->departmentDuties(
                    $position,
                    $this->departments(),
                    fn (PositionDuty $duty) => ['id' => $duty->id, 'name' => $duty->name],
                ),
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
     * The duties the position carries everywhere — the ones that name no
     * department, which is every duty written before departments came into it.
     *
     * @return Collection<int, PositionDuty>
     */
    private function commonDuties(Position $position): Collection
    {
        return $position->duties->filter(fn (PositionDuty $duty) => $duty->department_id === null)->values();
    }

    /**
     * What the position answers for in one department and not in another,
     * grouped by that department and ordered by the name it is shown under.
     *
     * Only the departments that add something of their own: the pages head each
     * group with a department, and a heading over nothing is not a group. A duty
     * whose department is gone is gone with it, so a group here always has a
     * department to be headed with.
     *
     * @param  Collection<int, Department>  $departments  every department, by id
     * @param  callable(PositionDuty): mixed  $read  how this page reads one duty
     * @return list<array{department: array<string, mixed>, duties: list<mixed>}>
     */
    private function departmentDuties(Position $position, Collection $departments, callable $read): array
    {
        return $position->duties
            ->filter(fn (PositionDuty $duty) => $departments->has($duty->department_id))
            ->groupBy('department_id')
            ->map(fn (Collection $duties, int $departmentId) => [
                'department' => $this->departmentRef($departments->get($departmentId)),
                'duties' => $duties->map($read)->values()->all(),
            ])
            ->sortBy(fn (array $group) => $group['department']['name'])
            ->values()
            ->all();
    }

    /**
     * A department as every screen but the directory names it: the short form it
     * is known by, with the full name along for the hover that says what those
     * letters stand for.
     *
     * @return array{id: int, name: string, full_name: string}
     */
    private function departmentRef(Department $department): array
    {
        return [
            'id' => $department->id,
            'name' => $department->display_name,
            'full_name' => $department->name,
        ];
    }

    /**
     * The tree flattened for the picker: parents first, children indented by the
     * depth, the way the employee list's department filter is already written.
     *
     * @param  Collection<int, Department>  $departments  every department, by id
     * @return list<array{id: int, name: string, full_name: string, depth: int}>
     */
    private function departmentOptions(Collection $departments, ?int $parentId = null, int $depth = 0): array
    {
        return $departments
            ->where('parent_id', $parentId)
            ->flatMap(fn (Department $department) => [
                [...$this->departmentRef($department), 'depth' => $depth],
                ...$this->departmentOptions($departments, $department->id, $depth + 1),
            ])
            ->values()
            ->all();
    }

    /**
     * Every department by id, in the order the directory lists them.
     *
     * A page says a department's name in two places — over a group of duties,
     * and in the picker the editor chooses from — so it reads them once and
     * hands the lot down rather than asking for a row at a time. Handed down and
     * not kept on the controller: the router gives one and the same controller
     * to every request it serves, and a list kept there would be yesterday's.
     *
     * @return Collection<int, Department>
     */
    private function departments(): Collection
    {
        return Department::query()
            ->orderBy('name')
            ->get(['id', 'name', 'abbreviation', 'parent_id'])
            ->keyBy('id');
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
            // Everything the page reads about the people in a few queries rather
            // than in a few per row. Which departments somebody is in decides
            // which duties of their positions are theirs, so they are read with
            // the rest and not asked for a person at a time.
            ->when($showsPositions, fn (Builder $query) => $query->with(['positions.duties', 'departments']))
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
            'duties' => $showsPositions ? $this->carries($employee) : [],
        ];
    }

    /**
     * What this person answers for: for every position they hold, its common
     * duties plus the ones it has in a department they are in. A duty of a
     * department somebody is not in is not theirs, and a duty naming a
     * department where nobody holds the position is nobody's.
     *
     * Which department a duty came from travels with it, because the page shows
     * the people of a department together and says where a duty comes from.
     *
     * @return list<array{id: int, name: string, position_id: int, department_id: int|null}>
     */
    private function carries(User $employee): array
    {
        $departments = $employee->departments->pluck('id');

        return $employee->positions
            ->flatMap(fn (Position $held) => $held->duties
                ->filter(fn (PositionDuty $duty) => $duty->department_id === null
                    || $departments->contains($duty->department_id))
                ->map(fn (PositionDuty $duty) => [
                    'id' => $duty->id,
                    'name' => $duty->name,
                    'position_id' => $held->id,
                    'department_id' => $duty->department_id,
                ]))
            ->values()
            ->all();
    }

    /**
     * The duties as the form left them, in the order it sent them. The rows a
     * position had are written afresh: nothing points at a duty, and a list that
     * is read back in the order it was entered is simplest kept in that order.
     *
     * `order` is a duty's place within its own group — the common duties are a
     * group and each department is a group — so each of them is numbered from
     * the top and reads as the list somebody typed.
     *
     * @param  list<array{name: string, department_id?: int|string|null}>  $duties
     */
    private function writeDuties(Position $position, array $duties): void
    {
        $position->duties()->delete();

        $orders = [];

        $position->duties()->createMany(array_map(function (array $duty) use (&$orders) {
            $department = ($duty['department_id'] ?? null) === null ? null : (int) $duty['department_id'];
            $group = $department ?? 'common';

            return [
                'name' => $duty['name'],
                'department_id' => $department,
                'order' => $orders[$group] = ($orders[$group] ?? -1) + 1,
            ];
        }, array_values($duties)));
    }

    /**
     * @return array{name: string, duties?: list<array{name: string, department_id: int|null}>}
     */
    private function validated(Request $request, ?Position $position = null): array
    {
        $this->trimDuties($request);

        return $request->validate([
            'name' => ['required', 'string', 'max:150', Rule::unique('positions', 'name')->ignore($position)],
            // A position nobody has written the duties of yet is a position all
            // the same, so saying nothing about them is not an omission.
            'duties' => ['sometimes', 'array'],
            'duties.*' => ['required', 'array'],
            'duties.*.name' => ['required', 'string', 'max:300'],
            // Naming no department is the usual case: the duty then belongs to
            // the position wherever it is held.
            'duties.*.department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')],
        ], attributes: [
            'name' => 'название',
            'duties' => 'обязанности',
            'duties.*' => 'обязанность',
            'duties.*.name' => 'обязанность',
            'duties.*.department_id' => 'отдел',
        ]);
    }

    /**
     * The duties trimmed and the blank ones dropped before any rule sees them: a
     * line left empty in the form is not something somebody asked to save, so it
     * is nothing to put an error in front of them for. An entry that is not a
     * duty at all is left as it came, for the rules to refuse.
     */
    private function trimDuties(Request $request): void
    {
        if (! is_array($sent = $request->input('duties'))) {
            return;
        }

        $duties = [];

        foreach ($sent as $duty) {
            if (! is_array($duty)) {
                $duties[] = $duty;

                continue;
            }

            $name = is_string($duty['name'] ?? null) ? trim($duty['name']) : ($duty['name'] ?? null);

            if ($name === '' || $name === null) {
                continue;
            }

            $department = $duty['department_id'] ?? null;

            $duties[] = [
                'name' => $name,
                // A duty of the position everywhere arrives with nothing here;
                // an empty string counts as the same answer, since that is what
                // an unanswered picker sends.
                'department_id' => $department === '' ? null : $department,
            ];
        }

        $request->merge(['duties' => $duties]);
    }
}
