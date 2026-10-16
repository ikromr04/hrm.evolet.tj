<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\Position;
use App\Models\PositionDuty;
use App\Models\User;
use App\Support\Directories;
use App\Support\EmployeeFields;
use App\Support\Profile;
use App\Support\Regulation;
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

        return Inertia::render('positions/index', [
            'positions' => Position::query()
                // The department is read with the rows and not per row, and the
                // department above it along with it: every position names one,
                // twenty of the names on this list repeat, and the list reads a
                // position under both steps of the structure at once.
                ->with(['department:id,name,abbreviation,parent_id', 'department.parent:id,name,abbreviation', 'duties'])
                // Working staff only, as the staff list counts them. The table
                // stopped naming the holders — that is a position's own page —
                // and the number is what the warning before a deletion counts.
                ->withCount(['users' => fn (Builder $query) => $query->active()])
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
                    'department' => $position->department === null ? null : $this->filing($position->department),
                    // What "newest first" is read from — the order the list opens in.
                    'created_at' => $position->created_at?->toIso8601String(),
                    'duties' => $position->duties->pluck('name')->all(),
                    'employees_count' => $position->users_count,
                ]),
            // Reading a list and keeping it are two rights, so the page says
            // which one it is looking at.
            'canEdit' => Directories::canEdit($user, 'positions'),
            // The whole tree, for the two columns that narrow the list — to a
            // department, or to one unit inside it — and for the picker that
            // places a new position. Which department a position belongs to is
            // part of keeping the position and asks no right of its own; the
            // picker is sent to everybody, and the form behind it is what is
            // gated.
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
        // A list of colleagues is not open to everybody, and this is the page
        // that names them.
        $showsEmployees = $this->showsEmployees($user);

        // The department above this one travels too: «01 ОБЩАЯ ИНФОРМАЦИЯ» of
        // the profile names both steps of the structure.
        $position->load(['department.parent', 'duties']);

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
            // The document this position carries, read on this page because it
            // is this position's: the department's page lists the positions and
            // sends nobody's profile with the list.
            'profile' => $this->profile($position, $user),
            // The regulation of the department this position belongs to, read
            // here and written on the department's page: a position has no
            // regulation of its own, and two copies of one text would be two
            // texts the day somebody edited either.
            'regulation' => $this->regulation($position),
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
     * «Профиль должности» as this position reads it: the nine standard sections
     * of the shared template, each replaced by this position's wording where
     * somebody wrote one — in one order, under the heading the sheet is printed
     * with.
     *
     * The document itself is put together in Profile, which a department's page
     * asks the same way for the sheet it keeps: one document, one shape of it,
     * and nothing here with an opinion of its own about what a profile is. What
     * belongs to the page is the one line above it — whether this viewer may
     * write the thing.
     *
     * @return array<string, mixed>
     */
    private function profile(Position $position, User $viewer): array
    {
        return [
            // Keeping the profile is keeping the position: the right that writes
            // the list of job titles writes this down too. Reading it is reading
            // the position's page, which asks for its own right already.
            'can_edit' => Directories::canEdit($viewer, 'positions'),
            // The heading, the table of «01 ОБЩАЯ ИНФОРМАЦИЯ» and the sections,
            // put together where a department's page puts its own: the two
            // pages read one document and one shape of it.
            ...Profile::paper($position),
        ];
    }

    /**
     * Whether a list of colleagues is this viewer's to read at all.
     *
     * A position's own page is where the holders are named — the list of
     * positions is about the positions — and the page is told outright rather
     * than working it out from the rights itself, or "nobody holds this
     * position" and "the holders are not yours to read" would be the same empty
     * list.
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
    /**
     * The department's «Положение о работе» as a position reads it: the
     * document, and the department it belongs to so the page can say whose it
     * is and link there. A position filed in no department reads nothing —
     * there is no document to show, which the page says in its own words.
     *
     * @return array<string, mixed>|null
     */
    private function regulation(Position $position): ?array
    {
        $department = $position->department;

        if ($department === null) {
            return null;
        }

        return [
            'department' => $this->departmentRef($department),
            // No «can_edit» and no placeholders: this page only reads, and the
            // page that writes is one link away.
            'paper' => Regulation::paper($department),
        ];
    }

    private function departmentRef(Department $department): array
    {
        return [
            'id' => $department->id,
            'name' => $department->display_name,
            'full_name' => $department->name,
        ];
    }

    /**
     * Where a position sits, as the list reads it: the department it belongs to,
     * with the department above that one where there is one.
     *
     * Both travel because the page has no tree to walk: the table reads a row
     * under the department in one column and under the unit inside it in the
     * next, and the structure is exactly two deep, so a department and its
     * parent are all there ever is to send.
     *
     * @return array{id: int, name: string, full_name: string, parent: array{id: int, name: string, full_name: string}|null}
     */
    private function filing(Department $department): array
    {
        return [
            ...$this->departmentRef($department),
            'parent' => $department->parent === null ? null : $this->departmentRef($department->parent),
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

        $data = $request->validate([
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

        return $data;
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
