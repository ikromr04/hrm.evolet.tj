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
 * A position belongs to one department, and its duties are one flat list: an
 * analyst in one department does not answer for what an analyst in another
 * answers for, and now the two are two positions rather than one position with
 * two sets of duties. Which of that list a holder actually carries is kept on the
 * holder, so the page of a position names its people and, beside each of them,
 * the duties they were given.
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
                // The department is read with the rows and not per row: every
                // position names one, and twenty of the names on this list
                // repeat, so the department is what tells two rows apart.
                ->with(['department:id,name,abbreviation', 'duties'])
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
                ->get(['id', 'department_id', 'name', 'created_at'])
                ->map(fn (Position $position) => [
                    'id' => $position->id,
                    'name' => $position->name,
                    // «Аналитик (ОПР)», for the places with room for one line and
                    // no column beside it to put the department in.
                    'display_name' => $position->display_name,
                    // Null for the few the data could not place, which the list
                    // says out loud rather than leaving a blank cell.
                    'department' => $position->department === null ? null : $this->departmentRef($position->department),
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
            // The whole tree, for the column that narrows the list to one
            // department and for the picker that places a new position in one.
            // Which department a position belongs to is part of keeping the
            // position and asks no right of its own; the picker is sent to
            // everybody, and the form behind it is what is gated.
            'options' => [
                'departments' => $this->departmentOptions($this->departments()),
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

        $position->load(['department', 'duties']);

        return Inertia::render('positions/show', [
            'position' => [
                'id' => $position->id,
                'name' => $position->name,
                // The heading spells the department out below the name, but the
                // breadcrumb and the tab have one line between them.
                'display_name' => $position->display_name,
                'department' => $position->department === null ? null : $this->departmentRef($position->department),
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

        $position = Position::create([
            'department_id' => $data['department_id'],
            'name' => $data['name'],
        ]);
        $this->writeDuties($position, $data['duties'] ?? []);

        return back();
    }

    public function update(Request $request, Position $position): RedirectResponse
    {
        $data = $this->validated($request, $position);

        $position->update([
            'department_id' => $data['department_id'],
            'name' => $data['name'],
        ]);

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
     * The tree is flattened into the picker and into the column's filter, both
     * from this one read rather than from a row at a time. Handed down and not
     * kept on the controller: the router gives one and the same controller to
     * every request it serves, and a list kept there would be yesterday's.
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
     * Each of them comes with the duties they carry rather than with the whole
     * list of the position: six analysts share one list out between them, and a
     * page about a position is the one place where who has which part of it is
     * plain. The positions come along so a duty from elsewhere can name where it
     * comes from.
     *
     * @return list<array<string, mixed>>
     */
    private function holders(Position $position, User $viewer, bool $showsPositions): array
    {
        return User::query()
            ->active()
            ->whereHas('positions', fn (Builder $query) => $query->whereKey($position->id))
            // Everything the page reads about the people in a few queries rather
            // than in a few per row.
            ->when($showsPositions, fn (Builder $query) => $query->with(['positions.department', 'duties']))
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
            // position answers for and nothing about who answers for what. Each
            // of the others carries its own department, because two of them can
            // share a name.
            'positions' => $showsPositions
                ? $employee->positions->map(fn (Position $held) => [
                    'id' => $held->id,
                    'name' => $held->name,
                    'display_name' => $held->display_name,
                ])->values()
                : [],
            'duties' => $showsPositions ? $this->carries($employee) : [],
        ];
    }

    /**
     * What this person answers for: the duties somebody picked out for them, from
     * the lists of the positions they hold. Holding a position hands nobody its
     * whole list, so a holder with nothing picked out carries nothing — which the
     * page says in as many words, since it is a list waiting to be shared out and
     * not a position without duties.
     *
     * Which position a duty came from travels with it: the page of a position
     * shows what each holder carries here and, apart from it, what they carry
     * elsewhere.
     *
     * @return list<array{id: int, name: string, position_id: int}>
     */
    private function carries(User $employee): array
    {
        return $employee->duties
            ->map(fn (PositionDuty $duty) => [
                'id' => $duty->id,
                'name' => $duty->name,
                'position_id' => $duty->position_id,
            ])
            ->values()
            ->all();
    }

    /**
     * The duties as the form left them, in the order it sent them. The rows a
     * position had are written afresh: nothing points at a duty, and a list that
     * is read back in the order it was entered is simplest kept in that order.
     *
     * One list and one numbering: the position belongs to a department of its
     * own, so there is nothing left inside the list to group and number apart.
     *
     * @param  list<array{name: string}>  $duties
     */
    private function writeDuties(Position $position, array $duties): void
    {
        $position->duties()->delete();

        $position->duties()->createMany(
            collect($duties)
                ->values()
                ->map(fn (array $duty, int $order) => ['name' => $duty['name'], 'order' => $order])
                ->all(),
        );
    }

    /**
     * @return array{department_id: int, name: string, duties?: list<array{name: string}>}
     */
    private function validated(Request $request, ?Position $position = null): array
    {
        $this->trimDuties($request);

        return $request->validate([
            // Where the position sits. The column allows nothing there, because
            // the data could not place a handful of the positions already on
            // file; a position somebody is adding by hand is one they know the
            // department of, and the ones left unplaced are there to be filed
            // rather than added to.
            'department_id' => ['required', 'integer', Rule::exists('departments', 'id')],
            'name' => [
                'required',
                'string',
                'max:150',
                // A name belongs to a department and not to the company:
                // «Аналитик» in two departments is two jobs, and twice in one
                // department is one job written down twice. Read from the
                // request rather than from what was just validated, since the
                // rules of a field say nothing about the order they run in; a
                // department that is missing or made up matches no row, and
                // answers for itself in its own message above.
                Rule::unique('positions', 'name')
                    ->where('department_id', $request->integer('department_id'))
                    ->ignore($position),
            ],
            // A position nobody has written the duties of yet is a position all
            // the same, so saying nothing about them is not an omission.
            'duties' => ['sometimes', 'array'],
            'duties.*' => ['required', 'array'],
            'duties.*.name' => ['required', 'string', 'max:300'],
        ], messages: [
            // "Такое значение поля название уже существует" would be read as a
            // name taken across the company, which is exactly what it no longer
            // is: the refusal has to name the department it is about.
            'name.unique' => 'В этом отделе уже есть должность с таким названием.',
        ], attributes: [
            'department_id' => 'отдел',
            'name' => 'название',
            'duties' => 'обязанности',
            'duties.*' => 'обязанность',
            'duties.*.name' => 'обязанность',
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

            $duties[] = ['name' => $name];
        }

        $request->merge(['duties' => $duties]);
    }
}
