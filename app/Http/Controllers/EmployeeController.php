<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreEmployeeRequest;
use App\Models\Citizenship;
use App\Models\Department;
use App\Models\Equipment;
use App\Models\EquipmentEvent;
use App\Models\EquipmentField;
use App\Models\EquipmentType;
use App\Models\Language;
use App\Models\Position;
use App\Models\User;
use App\Models\UserChild;
use App\Models\UserDetail;
use App\Models\UserEducation;
use App\Models\UserWorkExperience;
use App\Notifications\AccountCreated;
use App\Notifications\EmployeeAdded;
use App\Support\Access;
use App\Support\Directories;
use App\Support\EmployeeFields;
use App\Support\EquipmentAccess;
use App\Support\EquipmentHistory;
use App\Support\Recipients;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

class EmployeeController extends Controller
{
    public const PER_PAGE_OPTIONS = [10, 25, 50, 100];

    /**
     * Public columns. Private details (user_details, user_children) are loaded
     * separately, and only for rows the viewer is allowed to see.
     */
    private const PUBLIC_COLUMNS = ['id', 'name', 'surname', 'patronymic', 'avatar', 'sex', 'email', 'status', 'status_changed_at', 'status_note'];

    public const STATUSES = ['active', 'transferred', 'fired'];

    private const PUBLIC_SORTS = ['created', 'name', 'role', 'department', 'position', 'sex'];

    /** @var Collection<int, Department>|null All departments keyed by id; the tree is small. */
    private ?Collection $departments = null;

    /** @var list<int> Units on the card being built that the viewer may open. */
    private array $openUnits = [];

    /**
     * Sorting or filtering by these reveals how colleagues compare on private
     * data even without showing it, so only viewers who may see everyone's
     * private details can use them.
     */
    /**
     * Sorts named after something other than the field they read, so a sort can
     * be matched against what the viewer may see.
     *
     * @var array<string, string>
     */
    private const SORT_FIELDS = ['role' => 'roles', 'position' => 'positions', 'department' => 'departments'];

    private const PRIVATE_SORTS = [
        'birth_date', 'nationality', 'citizenship', 'home_address', 'phone', 'marital_status', 'children', 'hired_at',
    ];

    public function index(Request $request): Response
    {
        $viewer = $request->user();
        // Which fields of a card this viewer reads at all. Everything below hangs
        // off it: a column nobody may see is not offered, and neither is a filter
        // or a sort by it — otherwise a hidden field leaks through the question
        // "show me everybody living at this address".
        $visible = EmployeeFields::visibleTo($viewer);
        $shows = fn (string $field) => in_array($field, $visible, true);
        // Who was transferred or fired belongs to whoever moves people about.
        $canStatus = $viewer->can('employees.fire');
        $sortable = array_values(array_filter(
            [...self::PUBLIC_SORTS, ...self::PRIVATE_SORTS],
            fn (string $sort) => $shows(self::SORT_FIELDS[$sort] ?? $sort) || in_array($sort, ['created', 'name'], true),
        ));
        // A filter on a field that is not shown is not merely ignored: sending it
        // is refused, so nobody narrows a list by what they cannot read.
        $private = fn (string $field, array $rules) => $shows($field) ? $rules : ['prohibited'];

        $input = $request->validate([
            'per_page' => ['nullable', 'integer', Rule::in(self::PER_PAGE_OPTIONS)],
            'status' => ['nullable', Rule::in($canStatus ? self::STATUSES : ['active'])],
            'sort' => ['nullable', Rule::in($sortable)],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],

            'q' => ['nullable', 'string', 'max:100'],
            'search' => ['nullable', 'string', 'max:100'],
            'position' => $private('positions', ['nullable', 'array']),
            'position.*' => ['integer', Rule::exists('positions', 'id')],
            'role' => $private('roles', ['nullable', 'array']),
            'role.*' => ['string', Rule::exists('roles', 'name')],
            'department' => $private('departments', ['nullable', 'array']),
            'department.*' => ['integer', Rule::exists('departments', 'id')],
            'language' => $private('languages', ['nullable', 'array']),
            'language.*' => ['integer', Rule::exists('languages', 'id')],
            'sex' => $private('sex', ['nullable', Rule::in(['male', 'female'])]),

            'birth_from' => $private('birth_date', ['nullable', 'date']),
            'birth_to' => $private('birth_date', ['nullable', 'date']),
            'nationality' => $private('nationality', ['nullable', 'array']),
            'nationality.*' => ['string', 'max:100'],
            'citizenship' => $private('citizenship', ['nullable', 'array']),
            'citizenship.*' => ['string', 'max:100'],
            'address' => $private('home_address', ['nullable', 'string', 'max:100']),
            'phone' => $private('phone', ['nullable', 'string', 'max:32']),
            'marital_status' => $private('marital_status', ['nullable', Rule::in(['single', 'married'])]),
            'children' => $private('children', ['nullable', 'array']),
            'children.*' => ['integer', 'between:0,3'],
            'hired_from' => $private('hired_at', ['nullable', 'date']),
            'hired_to' => $private('hired_at', ['nullable', 'date']),
        ]);

        $filters = [
            'q' => trim($input['q'] ?? ''),
            'search' => trim($input['search'] ?? ''),
            'position' => array_map('intval', $input['position'] ?? []),
            'role' => array_values($input['role'] ?? []),
            'department' => array_map('intval', $input['department'] ?? []),
            'language' => array_map('intval', $input['language'] ?? []),
            'sex' => $input['sex'] ?? null,
            'birth_from' => $input['birth_from'] ?? null,
            'birth_to' => $input['birth_to'] ?? null,
            'nationality' => array_values($input['nationality'] ?? []),
            'citizenship' => array_values($input['citizenship'] ?? []),
            'address' => trim($input['address'] ?? ''),
            'phone' => trim($input['phone'] ?? ''),
            'marital_status' => $input['marital_status'] ?? null,
            'children' => array_map('intval', $input['children'] ?? []),
            'hired_from' => $input['hired_from'] ?? null,
            'hired_to' => $input['hired_to'] ?? null,
        ];

        // Newest first: the people just added are the ones being looked for. A
        // column asked for by name reads from the top, as any column does.
        $sort = $input['sort'] ?? 'created';
        $direction = $input['direction'] ?? (isset($input['sort']) ? 'asc' : 'desc');
        $perPage = (int) ($input['per_page'] ?? self::PER_PAGE_OPTIONS[0]);
        $status = $input['status'] ?? 'active';

        $query = User::query()->select(self::PUBLIC_COLUMNS)->with(['roles:id,name,title', 'positions:id,name', 'departments:id,name,parent_id', 'languages:id,name'])
            ->where('status', $status);
        $this->applySearch($query, $filters['q'], $visible);
        $this->applyFilters($query, $filters);
        $this->applySort($query, $sort, $direction);

        $employees = $query->paginate($perPage)->withQueryString();

        // Private data is loaded only for the rows the viewer may see, so it
        // never reaches the browser for anyone else.
        // Whose details are worth loading at all: the viewer's own row always,
        // and everyone else's when at least one such field is open to them.
        $loaded = $employees->getCollection()->filter(fn (User $user) => $viewer->can('viewPrivateDetails', $user));
        $loaded->load(['details', 'children', 'citizenships:id,name']);

        $employees->through(fn (User $user) => [
            'id' => $user->id,
            // The surname and the name are never hidden: a list of blank rows
            // would be no list at all.
            'name' => $user->name,
            'surname' => $user->surname,
            // The photograph is a line like any other: closed, the row shows the
            // initials instead, which is what a row without a photograph shows.
            'avatar' => in_array('avatar', $visible, true) ? $user->avatar : null,
            ...$this->only($visible, [
                'patronymic' => fn () => $user->patronymic,
                'sex' => fn () => $user->sex,
                'email' => fn () => $user->email,
                'roles' => fn () => $this->roleTitles($user),
                'positions' => fn () => $this->positionNames($user),
                'departments' => fn () => $this->departmentList($user),
                'languages' => fn () => $this->languageList($user),
            ]),
            'status' => $user->status,
            'status_changed_at' => $user->status_changed_at?->toDateString(),
            'status_note' => $canStatus ? $user->status_note : null,
            // Their own row reads the way their own card does, which is not the
            // same set of lines as a colleague's.
            'private' => $loaded->contains($user)
                ? $this->privateDetails($user, $user->is($viewer) ? EmployeeFields::visibleTo($viewer, $user) : $visible)
                : null,
        ]);

        return Inertia::render('employees/index', [
            'employees' => $employees,
            'filters' => $filters,
            'sort' => ['key' => $sort, 'direction' => $direction],
            'perPage' => $perPage,
            'perPageOptions' => self::PER_PAGE_OPTIONS,
            // What the page may draw: the columns, the filters and the detail
            // rows all read this one list.
            'visibleFields' => $visible,
            'sortable' => $sortable,
            'options' => [
                'roles' => Access::offeredRoles()->get(['name', 'title']),
                'positions' => Position::query()->orderBy('name')->get(['id', 'name']),
                'departments' => $this->departmentOptions(),
                'languages' => Language::query()->orderBy('name')->get(['id', 'name']),
                'nationalities' => $shows('nationality') ? $this->distinctDetail('nationality') : [],
                'citizenships' => $shows('citizenship') ? $this->citizenships() : [],
            ],
            'status' => $status,
            'statusCounts' => $canStatus ? $this->statusCounts() : null,
            // Adding a colleague is a right of its own, not a consequence of being
            // allowed to change some line of somebody's card.
            'canCreate' => $viewer->can('employees.create'),
            'total' => User::count(),
        ]);
    }

    /**
     * @return array<string, int> Every status, with zero where nobody has it.
     */
    private function statusCounts(): array
    {
        $counts = User::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return collect(self::STATUSES)->mapWithKeys(fn (string $s) => [$s => (int) ($counts[$s] ?? 0)])->all();
    }

    /**
     * The form for a new colleague: a page of its own rather than a dialog,
     * because it runs over several steps and half-filled work should survive a
     * stray key. Everything its steps offer travels with it.
     */
    public function create(Request $request): Response
    {
        // The last step hands hardware out, which is an operation on the fleet
        // rather than a line of the card: it stays with the right to issue units,
        // and without it the step says so instead of offering what it cannot do.
        $canIssue = $request->user()->can('equipment.issue');

        return Inertia::render('employees/create', [
            'canIssue' => $canIssue,
            'options' => [
                'roles' => Access::offeredRoles()->get(['name', 'title']),
                'positions' => Position::query()->orderBy('name')->get(['id', 'name']),
                'departments' => $this->departmentOptions(),
                'languages' => Language::query()->orderBy('name')->get(['id', 'name']),
                'nationalities' => $this->distinctDetail('nationality'),
                'citizenships' => $this->citizenships(),
                // The last step hands out hardware, so what is free travels too.
                'stock' => $canIssue ? $this->freeUnits() : [],
            ],
        ]);
    }

    /**
     * A new colleague on the books. The form asks for the account they sign in
     * with and the little that files them under a role, a position and a
     * department; the rest of the profile is filled in step by step afterwards,
     * which is why this lands on their page rather than back on the list.
     */
    public function store(StoreEmployeeRequest $request): RedirectResponse
    {
        $data = $request->validated();

        // Nobody types a password for somebody else: one is picked here, sent
        // to them, and never seen again — not even by whoever added them.
        $password = Str::password(random_int(8, 12), symbols: false);

        $employee = DB::transaction(function () use ($data, $password) {
            $employee = User::create([
                ...Arr::only($data, ['surname', 'name', 'patronymic', 'sex', 'email']),
                'password' => Hash::make($password),
                'status' => 'active',
            ]);

            // Everything the first step asks that is not on the user itself.
            $employee->details()->create(Arr::only($data, [
                'hired_at', 'birth_date', 'birth_place', 'nationality', 'home_address',
            ]));
            $employee->citizenships()->sync(Citizenship::idsFor($data['citizenship'] ?? []));

            $employee->syncRoles($data['roles']);
            $employee->positions()->sync($data['positions']);
            $employee->departments()->sync($data['departments']);

            return $employee;
        });

        $employee->notify(new AccountCreated($password));

        // The others who add people hear that this one is on the books now.
        // Whoever added them was there and needs no telling, and neither does
        // the newcomer, should their position be one that adds people too.
        Notification::send(
            Recipients::holding('employees.create')->reject(fn (User $user) => $user->is($request->user()) || $user->is($employee)),
            new EmployeeAdded($employee),
        );

        // Whoever added them fills the rest of their card in, every line of it,
        // through the ordinary forms of the card: those ask EmployeeFields, which
        // reads this. It lasts until the card is opened as a card.
        EmployeeFields::startCreating($employee);

        // The wizard goes on filling the profile in, step by step, and needs
        // to know whom it is filling in; on its own the form is done here.
        return $request->boolean('continue')
            ? back()->with('employee', [
                'id' => $employee->id,
                'name' => "{$employee->surname} {$employee->name}",
                // The contacts step edits the address as well, so it needs it.
                'email' => $employee->email,
            ])
            : to_route('employees.show', $employee);
    }

    /**
     * One's own card, at an address of its own.
     *
     * Everybody has a card and nobody needs a right to read their own, so it is
     * not a page one arrives at by knowing an id. Whoever may read the staff
     * list pages on from here to the colleagues beside them; without that right
     * the arrows would only lead to cards they may not open.
     */
    public function profile(Request $request): Response
    {
        return $this->card($request, $request->user(), neighbours: $request->user()->can('employees.view'));
    }

    public function show(Request $request, User $employee): Response|RedirectResponse
    {
        // Their own card lives at /profile; an id that happens to be theirs leads
        // there rather than drawing the same page twice.
        if ($request->user()->is($employee)) {
            return to_route('profile');
        }

        // Opening the card is where adding somebody ends — the wizard leads here
        // when it is done or put off, and there is no way back into it for a
        // person already on the books. From here on the card is edited under the
        // ordinary rights to its lines, so it is drawn under them too.
        EmployeeFields::finishCreating($employee);

        return $this->card($request, $employee, neighbours: true);
    }

    /**
     * The card itself, as both addresses render it.
     */
    private function card(Request $request, User $employee, bool $neighbours): Response
    {
        $employee->load(['roles:id,name,title', 'positions:id,name', 'departments:id,name,parent_id', 'languages:id,name']);
        // Which fields of this card the viewer reads. Their own card is whole;
        // anybody else's is what their position and their exceptions allow.
        $visible = EmployeeFields::visibleTo($request->user(), $employee);
        $shows = fn (string $field) => in_array($field, $visible, true);
        $canSeePrivate = $request->user()->can('viewPrivateDetails', $employee);
        // Which lines of this card the viewer may change: the pencil over a block
        // shows when anything inside it is theirs to change.
        $editable = EmployeeFields::editableBy($request->user(), $employee);
        $canEdit = $editable !== [];
        // Handing a unit over from this card takes the pair of rights the route
        // behind the form takes: the equipment block of the card, because that is
        // the line being written, and the right to hand units over at all. And
        // nobody issues to themselves, as nobody transfers or fires themselves.
        $canIssue = ! $request->user()->is($employee)
            && $request->user()->can(EmployeeFields::blockGate('equipment'), $employee)
            && $request->user()->can('equipment.issue');

        if ($canSeePrivate) {
            // The values come with the field behind each of them: a unit's name
            // and its number are two of those fields, read by the role they
            // carry, so the block cannot print either without them.
            $employee->load(['details', 'children', 'citizenships:id,name', 'educations', 'workExperiences', 'equipment.type.fields', 'equipment.fieldValues.field:id,role']);
        }

        return Inertia::render('employees/show', [
            'employee' => [
                'id' => $employee->id,
                // Never hidden: a card with no name on it answers nothing.
                'name' => $employee->name,
                'surname' => $employee->surname,
                // A line of the card, so it travels only when it is open.
                'avatar' => $shows('avatar') ? $employee->avatar : null,
                // The upload itself, for opening the photo at full size.
                'avatar_original' => $shows('avatar') ? $employee->avatar_original : null,
                'status' => $employee->status,
                'status_changed_at' => $employee->status_changed_at?->toDateString(),
                // Why somebody was let go is for whoever handles that side of things.
                'status_note' => $request->user()->can('employees.fire') ? $employee->status_note : null,
                ...$this->only($visible, [
                    'patronymic' => fn () => $employee->patronymic,
                    'sex' => fn () => $employee->sex,
                    'email' => fn () => $employee->email,
                    'roles' => fn () => $this->roleTitles($employee),
                    'positions' => fn () => $this->positionNames($employee),
                    'departments' => fn () => $this->departmentList($employee),
                    'languages' => fn () => $this->languageList($employee),
                ]),
                'private' => $canSeePrivate ? [
                    ...$this->privateDetails($employee, $visible),
                    ...$this->only($visible, [
                        'birth_place' => fn () => $employee->details?->birth_place,
                        'educations' => fn () => $employee->educations->map(fn (UserEducation $e) => $this->education($e))->all(),
                        'work_experiences' => fn () => $employee->workExperiences->map(fn (UserWorkExperience $w) => $this->workExperience($w))->all(),
                    ]),
                    // What they hold and what happened to it while they held it:
                    // one field, two things to read.
                    ...($shows('equipment') && $this->openUnitsFor($request->user(), $employee) ? [
                        'equipment' => $employee->equipment->map(fn (Equipment $e) => $this->equipment($e, $request->user()))->all(),
                        'equipment_history' => $this->equipmentHistory($employee),
                    ] : []),
                    // The passport reads as three lines, each its own field, so a
                    // line nobody may read comes through empty rather than the
                    // block changing shape under the page.
                    'passport' => [
                        'series' => $shows('passport_number') ? $employee->details?->passport_series : null,
                        'number' => $shows('passport_number') ? $employee->details?->passport_number : null,
                        'issued_at' => $shows('passport_issued_at') ? $employee->details?->passport_issued_at?->toDateString() : null,
                        'issued_by' => $shows('passport_issued_by') ? $employee->details?->passport_issued_by : null,
                    ],
                ] : null,
            ],
            // What the page may draw, block by block and line by line.
            'visibleFields' => $visible,
            // And which of those lines are theirs to change.
            'editableFields' => $editable,
            'neighbours' => $neighbours ? $this->neighbours($employee) : null,
            // Each card is edited in place, so the suggestions its dialog needs
            // travel with the page — and only for viewers who may edit.
            'canEdit' => $canEdit,
            // Nobody transfers, fires or deletes themselves.
            'isSelf' => $request->user()->is($employee),
            // Whether the equipment section of this card may hand a unit over.
            'canIssue' => $canIssue,
            // What there is to hand over, as the intake wizard's last step is
            // given it: only a viewer who may issue is told, since to anybody
            // else it is a list of the fleet they never asked for.
            'stock' => $canIssue ? $this->freeUnits() : [],
            // A unit that is not on the books yet is entered from the card as
            // well, in the same window, so the categories and what each of them
            // asks about travel with it — and only for somebody who may both
            // enter a unit and hand it over.
            'equipmentTypes' => $canIssue && $request->user()->can('equipment.create') ? EquipmentType::formOptions() : [],
            // Why the positions of this card are not this viewer's to change, if
            // they are not: the form shows the sentence beside the locked field
            // instead of letting somebody find out by saving.
            'rolesLocked' => Access::rolesLockedReason($request->user(), $employee),
            'options' => $canEdit ? [
                'nationalities' => $this->distinctDetail('nationality'),
                'citizenships' => $this->citizenships(),
                // What a card may be given, plus whatever it already carries: the
                // single system administrator is offered to nobody, and their own
                // card must still show the role it holds rather than lose it on
                // the next save.
                'roles' => Access::offeredRoles()
                    ->orWhereIn('name', $employee->roles->pluck('name'))
                    ->get(['name', 'title']),
                'positions' => Position::query()->orderBy('name')->get(['id', 'name']),
                'departments' => $this->departmentOptions(),
                'languages' => Language::query()->orderBy('name')->get(['id', 'name']),
                // Countries already on file, as suggestions for a previous job.
                'countries' => UserWorkExperience::query()->distinct()->orderBy('country')->pluck('country'),
            ] : null,
            // The dialog edits these by id or name, not by the labels shown above.
            'assigned' => $canEdit ? [
                'roles' => $employee->roles->pluck('name'),
                'positions' => $employee->positions->pluck('id'),
                'departments' => $employee->departments->pluck('id'),
            ] : null,
            // What this colleague may do, and why. Only a system administrator
            // is shown it, because only they can change any of it.
            'access' => Directories::canEdit($request->user(), 'access') ? $this->access($employee) : null,
        ]);
    }

    /**
     * Every right, what the colleague's positions give them, and the exceptions
     * made for this person in particular.
     *
     * @return array{everything: bool, sections: list<array<string, mixed>>, rights: list<array{key: string, position: bool, override: bool|null}>}
     */
    private function access(User $employee): array
    {
        $viaPositions = $employee->getPermissionsViaRoles()->pluck('name')->all();
        $overrides = $employee->permissionOverrides->pluck('allowed', 'permission');

        return [
            // The one account passes every check through Gate::before, so the
            // rights below would only be telling half the story.
            'everything' => $employee->hasRole(Access::SOLE_ROLE),
            'sections' => Access::tree(),
            'rights' => collect(Access::keys())->map(fn (string $key) => [
                'key' => $key,
                'position' => in_array($key, $viaPositions, true),
                'override' => $overrides[$key] ?? null,
            ])->all(),
        ];
    }

    /**
     * The previous and next person in the same list (working, transferred or
     * fired), in the default order of the employee table: by surname and name.
     *
     * @return array{prev: array{id: int, name: string}|null, next: array{id: int, name: string}|null}
     */
    private function neighbours(User $employee): array
    {
        $order = fn (string $direction) => User::query()
            ->where('status', $employee->status)
            ->whereKeyNot($employee->id)
            ->orderBy('surname', $direction)
            ->orderBy('name', $direction)
            ->orderBy('id', $direction);

        // "Before" means earlier in (surname, name, id) order; ties on the name fall back to the id.
        $before = fn (Builder $q) => $q->where(fn (Builder $q) => $q
            ->where('surname', '<', $employee->surname)
            ->orWhere(fn (Builder $q) => $q->where('surname', $employee->surname)->where('name', '<', $employee->name))
            ->orWhere(fn (Builder $q) => $q->where('surname', $employee->surname)->where('name', $employee->name)->where('id', '<', $employee->id)));
        $after = fn (Builder $q) => $q->where(fn (Builder $q) => $q
            ->where('surname', '>', $employee->surname)
            ->orWhere(fn (Builder $q) => $q->where('surname', $employee->surname)->where('name', '>', $employee->name))
            ->orWhere(fn (Builder $q) => $q->where('surname', $employee->surname)->where('name', $employee->name)->where('id', '>', $employee->id)));

        $person = fn (?User $u) => $u ? ['id' => $u->id, 'name' => "{$u->surname} {$u->name}"] : null;

        return [
            'prev' => $person($order('desc')->tap($before)->first(['id', 'name', 'surname'])),
            'next' => $person($order('asc')->tap($after)->first(['id', 'name', 'surname'])),
        ];
    }

    /**
     * Toolbar search across every column. Each word must match some field, so
     * "Назарова Дилноза" finds a person whose surname and name hold the words.
     *
     * Only the fields the viewer may read are searched: a word that matched a
     * hidden telephone number would tell them whose it is without ever showing
     * it, which is the same leak by a longer road.
     *
     * @param  list<string>  $visible
     */
    private function applySearch(Builder $query, string $term, array $visible): void
    {
        $shows = fn (string $field) => in_array($field, $visible, true);
        // "90 555 44 33" is one phone number, not four words.
        $words = preg_match('/^[\d\s+()\-]+$/', $term)
            ? [preg_replace('/\D/', '', $term)]
            : preg_split('/\s+/u', $term, -1, PREG_SPLIT_NO_EMPTY);

        foreach ($words as $word) {
            $like = "%{$word}%";
            $lower = mb_strtolower($word);

            $query->where(function (Builder $q) use ($like, $lower, $word, $shows) {
                // The name is always readable, the rest by the same rules as the
                // columns of the table.
                $q->where('surname', 'like', $like)->orWhere('name', 'like', $like);

                foreach (['patronymic' => 'patronymic', 'email' => 'email'] as $field => $column) {
                    if ($shows($field)) {
                        $q->orWhere($column, 'like', $like);
                    }
                }

                foreach (['roles' => ['roles', 'title'], 'positions' => ['positions', 'name'], 'languages' => ['languages', 'name'], 'departments' => ['departments', 'name'], 'citizenship' => ['citizenships', 'name']] as $field => [$relation, $column]) {
                    if ($shows($field)) {
                        $q->orWhereHas($relation, fn (Builder $q) => $q->where($column, 'like', $like));
                    }
                }

                if ($shows('sex')) {
                    foreach (['male' => 'мужской', 'female' => 'женский'] as $sex => $label) {
                        if (str_starts_with($label, $lower)) {
                            $q->orWhere('sex', $sex);
                        }
                    }
                }

                // Grouped, so the OR conditions stay inside the "belongs to this employee" constraint.
                $q->orWhereHas('details', fn (Builder $q) => $q->where(function (Builder $q) use ($like, $lower, $word, $shows) {
                    // Nothing matches by default here: every line is a field.
                    $q->whereRaw('1 = 0');

                    foreach (['home_address' => 'home_address', 'nationality' => 'nationality', 'birth_place' => 'birth_place', 'sos_phone' => 'sos_contact'] as $field => $column) {
                        if ($shows($field)) {
                            $q->orWhere($column, 'like', $like);
                        }
                    }

                    $digits = preg_replace('/\D/', '', $word);
                    if (strlen($digits) >= 3) {
                        if ($shows('phone')) {
                            $q->orWhere('phone', 'like', "%{$digits}%");
                        }
                        if ($shows('sos_phone')) {
                            $q->orWhere('sos_phone', 'like', "%{$digits}%");
                        }
                    }

                    // "14.05.1992" or a year such as "1992" matches the dates shown in the table.
                    $dates = array_filter(['birth_date' => $shows('birth_date'), 'hired_at' => $shows('hired_at')]);

                    if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $word, $m)) {
                        foreach (array_keys($dates) as $column) {
                            $q->orWhereDate($column, "{$m[3]}-{$m[2]}-{$m[1]}");
                        }
                    } elseif (preg_match('/^(19|20)\d{2}$/', $word)) {
                        foreach (array_keys($dates) as $column) {
                            $q->orWhereYear($column, (int) $word);
                        }
                    }

                    if ($shows('marital_status')) {
                        $marital = ['married' => ['женат', 'замужем'], 'single' => ['не женат', 'не замужем', 'холост']];
                        foreach ($marital as $status => $labels) {
                            if (collect($labels)->contains(fn ($l) => str_starts_with($l, $lower))) {
                                $q->orWhere('marital_status', $status);
                            }
                        }
                    }
                }));

                if ($shows('children')) {
                    $q->orWhereHas('children', fn (Builder $q) => $q->where('full_name', 'like', $like));
                }
            });
        }
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        $query
            ->when($filters['search'] !== '', fn (Builder $q) => $q->where(function (Builder $q) use ($filters) {
                foreach (['surname', 'name', 'patronymic', 'email'] as $column) {
                    $q->orWhere($column, 'like', "%{$filters['search']}%");
                }
            }))
            ->when($filters['role'], fn (Builder $q, array $roles) => $q->role($roles))
            ->when($filters['position'], fn (Builder $q, array $ids) => $q->whereHas('positions', fn (Builder $q) => $q->whereIn('positions.id', $ids)))
            // Whoever speaks every one of the picked languages, at any level:
            // picking Russian and Tajik asks for people who know both.
            ->when($filters['language'], function (Builder $q, array $ids) {
                foreach ($ids as $id) {
                    $q->whereHas('languages', fn (Builder $q) => $q->where('languages.id', $id));
                }
            })
            // Picking a department also matches everyone in its sub-departments.
            ->when($filters['department'], fn (Builder $q, array $ids) => $q->whereHas(
                'departments',
                fn (Builder $q) => $q->whereIn('departments.id', $this->withDescendants($ids)),
            ))
            ->when($filters['sex'], fn (Builder $q, string $sex) => $q->where('sex', $sex))
            // Whoever holds every one of the chosen citizenships, picked by name, as with languages.
            ->when($filters['citizenship'], function (Builder $q, array $names) {
                foreach ($names as $name) {
                    $q->whereHas('citizenships', fn (Builder $q) => $q->where('citizenships.name', $name));
                }
            })
            ->when($filters['children'], fn (Builder $q, array $counts) => $q->where(function (Builder $q) use ($counts) {
                $count = UserChild::selectRaw('count(*)')->whereColumn('user_children.user_id', 'users.id');
                foreach ($counts as $n) {
                    $q->orWhere($count->clone(), $n >= 3 ? '>=' : '=', $n);
                }
            }));

        $details = array_filter([
            'birth_from' => $filters['birth_from'],
            'birth_to' => $filters['birth_to'],
            'nationality' => $filters['nationality'],
            'address' => $filters['address'],
            'phone' => $filters['phone'],
            'marital_status' => $filters['marital_status'],
            'hired_from' => $filters['hired_from'],
            'hired_to' => $filters['hired_to'],
        ]);

        if ($details === []) {
            return;
        }

        $query->whereHas('details', function (Builder $q) use ($details) {
            $q->when($details['birth_from'] ?? null, fn (Builder $q, string $d) => $q->whereDate('birth_date', '>=', $d))
                ->when($details['birth_to'] ?? null, fn (Builder $q, string $d) => $q->whereDate('birth_date', '<=', $d))
                ->when($details['hired_from'] ?? null, fn (Builder $q, string $d) => $q->whereDate('hired_at', '>=', $d))
                ->when($details['hired_to'] ?? null, fn (Builder $q, string $d) => $q->whereDate('hired_at', '<=', $d))
                ->when($details['nationality'] ?? null, fn (Builder $q, array $v) => $q->whereIn('nationality', $v))
                ->when($details['address'] ?? null, fn (Builder $q, string $v) => $q->where('home_address', 'like', "%{$v}%"))
                ->when($details['marital_status'] ?? null, fn (Builder $q, string $v) => $q->where('marital_status', $v))
                ->when($details['phone'] ?? null, function (Builder $q, string $v) {
                    $digits = preg_replace('/\D/', '', $v);
                    $q->where(fn (Builder $q) => $q->where('phone', 'like', "%{$digits}%")->orWhere('sos_phone', 'like', "%{$digits}%"));
                });
        });
    }

    private function applySort(Builder $query, string $sort, string $direction): void
    {
        $detail = fn (string $column) => UserDetail::select($column)->whereColumn('user_details.user_id', 'users.id');

        match ($sort) {
            // When the record was added, not when the person was hired: that date is private.
            'created' => $query->orderBy('users.created_at', $direction)->orderBy('users.id', $direction),
            'name' => $query->orderBy('surname', $direction)->orderBy('name', $direction),
            'sex' => $query->orderBy('sex', $direction),
            'department' => $query->orderBy(
                DB::table('department_user')
                    ->join('departments', 'departments.id', '=', 'department_user.department_id')
                    ->whereColumn('department_user.user_id', 'users.id')
                    ->selectRaw('min(departments.name)'),
                $direction,
            ),
            'role' => $query->orderBy(
                Role::select('roles.title')
                    ->join('model_has_roles', 'model_has_roles.role_id', '=', 'roles.id')
                    ->whereColumn('model_has_roles.model_id', 'users.id')
                    ->where('model_has_roles.model_type', User::class)
                    ->orderBy('roles.title')
                    ->limit(1),
                $direction,
            ),
            'position' => $query->orderBy(
                DB::table('position_user')
                    ->join('positions', 'positions.id', '=', 'position_user.position_id')
                    ->whereColumn('position_user.user_id', 'users.id')
                    ->selectRaw('min(positions.name)'),
                $direction,
            ),
            'children' => $query->orderBy(
                UserChild::selectRaw('count(*)')->whereColumn('user_children.user_id', 'users.id'),
                $direction,
            ),
            default => $query->orderBy($detail($sort), $direction),
        };

        // Stable order within equal values, so pages never shuffle.
        $query->orderBy('surname')->orderBy('name')->orderBy('users.id');
    }

    /**
     * @return list<array{id: int, name: string, path: string, is_head: bool}>
     */
    private function departmentList(User $user): array
    {
        return $user->departments->map(fn (Department $d) => [
            'id' => $d->id,
            'name' => $d->name,
            'path' => $this->departmentPath($d->id),
            'is_head' => (bool) $d->pivot->is_head,
        ])->all();
    }

    /**
     * @return Collection<int, Department>
     */
    private function allDepartments(): Collection
    {
        return $this->departments ??= Department::query()->orderBy('name')->get(['id', 'name', 'parent_id'])->keyBy('id');
    }

    /**
     * "Департамент маркетинга › Отдел Дизайна", resolved in memory.
     */
    private function departmentPath(int $id): string
    {
        $names = [];

        for ($d = $this->allDepartments()->get($id); $d && ! isset($names[$d->id]); $d = $this->allDepartments()->get($d->parent_id)) {
            $names[$d->id] = $d->name;
        }

        return implode(' › ', array_reverse($names));
    }

    /**
     * The tree flattened for the filter: parents first, children indented.
     *
     * @return list<array{id: int, name: string, depth: int}>
     */
    private function departmentOptions(?int $parentId = null, int $depth = 0): array
    {
        return $this->allDepartments()
            ->where('parent_id', $parentId)
            ->flatMap(fn (Department $d) => [
                ['id' => $d->id, 'name' => $d->name, 'depth' => $depth],
                ...$this->departmentOptions($d->id, $depth + 1),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function withDescendants(array $ids): array
    {
        $result = $ids;

        for ($level = $ids; $level !== [];) {
            $level = $this->allDepartments()->whereIn('parent_id', $level)->pluck('id')->diff($result)->values()->all();
            $result = [...$result, ...$level];
        }

        return $result;
    }

    /**
     * Access roles shown as "Позиция"; titles in alphabetical order.
     *
     * @return list<string>
     */
    private function roleTitles(User $user): array
    {
        return $user->roles->pluck('title')->sort()->values()->all();
    }

    /**
     * An employee can hold several positions; names in alphabetical order.
     *
     * @return list<string>
     */
    private function positionNames(User $user): array
    {
        return $user->positions->pluck('name')->sort()->values()->all();
    }

    /**
     * Languages with the level, the best known first.
     *
     * @return list<array{id: int, name: string, level: string}>
     */
    private function languageList(User $user): array
    {
        return $user->languages
            ->sortBy([fn (Language $a, Language $b) => array_search($b->pivot->level, Language::LEVELS, true) <=> array_search($a->pivot->level, Language::LEVELS, true), ['name', 'asc']])
            ->map(fn (Language $l) => ['id' => $l->id, 'name' => $l->name, 'level' => $l->pivot->level])
            ->values()
            ->all();
    }

    /**
     * Everything on the balance sheet that nobody holds, for the forms that hand
     * a unit over. What a unit is called and the number on its sticker are fields
     * of its category now, so the list loads them and reads them off the unit
     * rather than off the row.
     *
     * @return list<array{id: int, name: string, inventory_number: string}>
     */
    private function freeUnits(): array
    {
        return Equipment::query()
            ->where('status', 'stock')
            ->withIdentity()
            ->orderBy(Equipment::roleValueQuery('title'))
            ->get(['id'])
            ->map(fn (Equipment $unit) => [
                'id' => $unit->id,
                'name' => $unit->name,
                'inventory_number' => $unit->inventory_number,
            ])
            ->all();
    }

    /**
     * What the person holds right now, and what may be done about it from here:
     * a unit can be taken back onto the balance sheet or struck off it without
     * the detour through the fleet, since this card is where somebody notices
     * that the thing is back on the desk.
     *
     * @return array<string, mixed>
     */
    private function equipment(Equipment $unit, User $viewer): array
    {
        return [
            'id' => $unit->id,
            'name' => $unit->name,
            // What its category says it is, as far as the line under the name
            // needs: the first couple of answers the unit has on file.
            'details' => $this->unitDetails($unit),
            'inventory_number' => $unit->inventory_number,
            'type' => $unit->type?->name,
            'issued_at' => $unit->issued_at?->toDateString(),
            // What the card says about it now, which the write-off form opens on.
            'condition' => $unit->condition,
            // Whether the unit's own card is this viewer's to open: holding the
            // line of a person's card is not the same as seeing the fleet.
            'open' => in_array($unit->id, $this->openUnits, true),
            // And whether these two moves are theirs to make on this very unit.
            // Asked of the unit, as the routes behind the forms ask it: the right
            // alone is not enough on a unit one cannot see.
            'can_take' => $viewer->can('take', $unit),
            'can_write_off' => $viewer->can('writeOff', $unit),
        ];
    }

    /**
     * The first couple of things a unit's category asks about, as this unit
     * answered them: "Dell · Latitude 5440" under the name of a laptop.
     *
     * The two fields a unit is named by are left out: they are what the line
     * sits under, and the line is here to say something the name does not.
     */
    private function unitDetails(Equipment $unit): ?string
    {
        $held = $unit->fieldValues->keyBy('equipment_field_id');

        $lines = collect($unit->type?->fields ?? [])
            ->reject(fn (EquipmentField $field) => $field->isRole())
            ->map(fn (EquipmentField $field) => $field->read($held->get($field->id)?->value))
            ->filter()
            ->take(2);

        return $lines->isEmpty() ? null : $lines->join(' · ');
    }

    /**
     * Everything that has passed through their hands, and what happened to it
     * while they had it: the journal of the equipment section, narrowed to the
     * spells when this colleague was the holder.
     *
     * @return array<string, mixed>
     */
    private function equipmentHistory(User $employee): array
    {
        $events = EquipmentHistory::of($employee)->events();

        return [
            'events' => $events->map(fn (EquipmentEvent $event) => [
                'id' => $event->id,
                'unit' => $this->historyUnit($event),
                'kind' => $event->kind,
                'changes' => $event->diff ?? [],
                'note' => $event->note,
                'at' => $event->created_at?->toIso8601String(),
                'actor' => $event->user === null ? null : [
                    'id' => $event->user->id,
                    'name' => "{$event->user->surname} {$event->user->name}",
                    'avatar' => EmployeeFields::showsAvatar(request()->user(), $event->user) ? $event->user->avatar : null,
                ],
                'photos' => $event->photos->map(fn ($photo) => [
                    'id' => $photo->id,
                    'url' => $photo->url,
                    'preview' => $photo->preview_url,
                ])->all(),
            ])->all(),
            // The ids the entries kept, read back as the names behind them.
            'names' => EquipmentEvent::namesFor($events),
        ];
    }

    /**
     * The unit an entry is about, as the history lists it. Null when the unit
     * itself has been struck off the books since.
     *
     * @return array<string, mixed>|null
     */
    private function historyUnit(EquipmentEvent $event): ?array
    {
        return $event->equipment === null ? null : [
            'id' => $event->equipment->id,
            'name' => $event->equipment->name,
            'inventory_number' => $event->equipment->inventory_number,
            'type' => $event->equipment->type?->name,
            'open' => in_array($event->equipment->id, $this->openUnits, true),
        ];
    }

    /**
     * Which of the units on this card — held now or passed through their hands —
     * the viewer may open, asked once for the lot rather than unit by unit.
     * Always true, so it can sit in the condition that builds the block.
     */
    private function openUnitsFor(User $viewer, User $employee): bool
    {
        $ids = $employee->equipment->pluck('id')
            ->merge(EquipmentHistory::of($employee)->events()->pluck('equipment_id'))
            ->filter()->unique()->values()->all();

        $this->openUnits = $ids === [] ? [] : Equipment::query()
            ->whereIn('id', $ids)
            ->tap(fn (Builder $q) => EquipmentAccess::narrow($q, $viewer))
            ->pluck('id')->all();

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    private function workExperience(UserWorkExperience $job): array
    {
        return [
            'id' => $job->id,
            'organization' => $job->organization,
            'position' => $job->position,
            'country' => $job->country,
            'started_month' => $job->started_month,
            'started_year' => $job->started_year,
            'ended_month' => $job->ended_month,
            'ended_year' => $job->ended_year,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function education(UserEducation $education): array
    {
        return [
            'id' => $education->id,
            'institution' => $education->institution,
            'faculty' => $education->faculty,
            'specialty' => $education->specialty,
            'started_year' => $education->started_year,
            'graduated_year' => $education->graduated_year,
            'diploma_number' => $education->diploma_number,
        ];
    }

    /**
     * @return list<string>
     */
    private function distinctDetail(string $column): array
    {
        return UserDetail::query()->whereNotNull($column)->distinct()->orderBy($column)->pluck($column)->all();
    }

    /**
     * The countries of the directory, by name: the filter and the form pick by name.
     *
     * @return list<string>
     */
    private function citizenships(): array
    {
        return Citizenship::query()->orderBy('name')->pluck('name')->all();
    }

    /**
     * The values whose fields are visible, computed only for those: a closure per
     * field so a hidden one costs neither a query nor a lookup.
     *
     * @param  list<string>  $visible
     * @param  array<string, callable>  $values
     * @return array<string, mixed>
     */
    private function only(array $visible, array $values): array
    {
        $shown = [];

        foreach ($values as $field => $read) {
            if (in_array($field, $visible, true)) {
                $shown[$field] = $read();
            }
        }

        return $shown;
    }

    /**
     * @return array<string, mixed>
     */
    private function privateDetails(User $user, array $visible): array
    {
        $details = $user->details;

        $shows = fn (string $field) => in_array($field, $visible, true);

        return [
            ...$this->only($visible, [
                'birth_date' => fn () => $details?->birth_date?->toDateString(),
                'nationality' => fn () => $details?->nationality,
                'citizenship' => fn () => $user->citizenships->pluck('name')->all() ?: null,
                'home_address' => fn () => $details?->home_address,
                'phone' => fn () => $details?->phone,
                'marital_status' => fn () => $details?->marital_status,
                'hired_at' => fn () => $details?->hired_at?->toDateString(),
            ]),
            // Three fields that read as one line each on the card but are kept as
            // two columns: the number and whom to call, the spouse and their
            // birthday, whether there are children and who they are.
            ...($shows('sos_phone') ? [
                'sos_phone' => $details?->sos_phone,
                'sos_contact' => $details?->sos_contact,
            ] : []),
            ...($shows('spouse') ? [
                'spouse_name' => $details?->spouse_name,
                'spouse_birth_date' => $details?->spouse_birth_date?->toDateString(),
            ] : []),
            ...($shows('children') ? [
                'has_children' => $details?->has_children,
                'children' => $user->children->map(fn ($child) => [
                    'full_name' => $child->full_name,
                    'birth_date' => $child->birth_date?->toDateString(),
                ])->all(),
            ] : []),
        ];
    }
}
