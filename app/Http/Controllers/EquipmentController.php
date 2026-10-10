<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\KeepsEquipmentPhotos;
use App\Http\Controllers\Concerns\SavesEquipmentFields;
use App\Models\Equipment;
use App\Models\EquipmentEvent;
use App\Models\EquipmentField;
use App\Models\EquipmentPhoto;
use App\Models\EquipmentType;
use App\Models\User;
use App\Observers\EquipmentObserver;
use App\Support\EmployeeFields;
use App\Support\EquipmentAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The company's hardware, all of it: what there is, what state it is in and who
 * holds it. Handing a unit out and taking it back happen here, so a unit's
 * status changes in one place rather than from whichever profile.
 */
class EquipmentController extends Controller
{
    use KeepsEquipmentPhotos, SavesEquipmentFields;

    public const PER_PAGE_OPTIONS = [25, 50, 100];

    /** Columns the list can be ordered by, named as the table names them. */
    private const SORTS = ['created', 'name', 'inventory_number', 'type', 'status', 'holder', 'issued_at'];

    /**
     * The unit entered a minute ago is the one being looked for, so the list
     * opens on what was added last. It is an order of its own rather than a
     * column, which is what lets a header be pressed up, down and back to it.
     */
    private const DEFAULT_SORT = 'created';

    /**
     * The second table is read by colleague, so the colleague's name is the one
     * thing that orders it; the other columns are categories, and a category
     * holds a list rather than a value. Its own opening order is "issued":
     * whoever was handed something last reads first.
     */
    private const HOLDER_SORTS = ['holder', 'issued'];

    private const DEFAULT_HOLDER_SORT = 'issued';

    public function index(Request $request): Response
    {
        // Two readings of the same fleet on the same page: one row per
        // colleague with what each of them holds, or the fleet unit by unit.
        // Which one is settled before anything else is read, because the two
        // are not ordered by the same things.
        $byHolder = self::opensByHolder($request);

        $input = $request->validate([
            'view' => ['nullable', Rule::in(['list', 'holders'])],
            'per_page' => ['nullable', 'integer', Rule::in(self::PER_PAGE_OPTIONS)],
            'sort' => ['nullable', Rule::in($byHolder ? self::HOLDER_SORTS : self::SORTS)],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],

            // The toolbar search, across everything printed on a unit.
            'q' => ['nullable', 'string', 'max:100'],
            // One per column.
            'name' => ['nullable', 'string', 'max:100'],
            'inventory_number' => ['nullable', 'string', 'max:50'],
            'type' => ['nullable', 'array'],
            'type.*' => ['integer', Rule::exists('equipment_types', 'id')],
            'status' => ['nullable', 'array'],
            'status.*' => [Rule::in(Equipment::STATUSES)],
            'holder' => ['nullable', 'string', 'max:100'],
            'issued_from' => ['nullable', 'date'],
            'issued_to' => ['nullable', 'date'],

            // The tab above the table; it is not a column filter. Besides the
            // statuses it takes "service", which is not one: a unit is being
            // looked after when it has a record that has not ended, whoever
            // holds it meanwhile.
            'tab' => ['nullable', Rule::in([...Equipment::STATUSES, 'service', 'all'])],
        ]);

        $filters = [
            'q' => trim($input['q'] ?? ''),
            'name' => trim($input['name'] ?? ''),
            'inventory_number' => trim($input['inventory_number'] ?? ''),
            'type' => array_map('intval', $input['type'] ?? []),
            'status' => array_values($input['status'] ?? []),
            'holder' => trim($input['holder'] ?? ''),
            'issued_from' => $input['issued_from'] ?? null,
            'issued_to' => $input['issued_to'] ?? null,
        ];

        // The list opens on what is out with people, which is what it is asked
        // for most of the time. "Все" has to say so in the query: no tab at all
        // means the default, not everything.
        $tab = $input['tab'] ?? 'issued';
        $sort = $input['sort'] ?? ($byHolder ? self::DEFAULT_HOLDER_SORT : self::DEFAULT_SORT);
        // Newest first while nothing has been asked for; a column asked for by
        // name reads from the top, as any column does.
        $direction = $input['direction'] ?? (isset($input['sort']) ? 'asc' : 'desc');
        $perPage = (int) ($input['per_page'] ?? self::PER_PAGE_OPTIONS[0]);

        if ($byHolder) {
            return $this->byHolder($request, $filters, $sort, $direction, $perPage);
        }

        // The values come with the fields they belong to: the name and the
        // number are read off the two that carry a role, and a page of units
        // should cost one query for the lot rather than one apiece.
        $query = Equipment::query()->with(['type:id,name,icon', 'type.fields', 'fieldValues.field:id,role,name', 'holder:id,name,surname,avatar']);
        // Whatever the tabs and filters then do, the list starts from the part of
        // the fleet this person may see at all.
        EquipmentAccess::narrow($query, $request->user());
        $query->withExists(['repairs as repairs_exists' => fn (Builder $q) => $q->whereNull('ended_at')]);
        $query->when($tab === 'service', fn (Builder $q) => $q->underService())
            ->when(! in_array($tab, ['all', 'service'], true), fn (Builder $q) => $q->where('status', $tab));
        $this->applyFilters($query, $filters);
        $this->applySort($query, $sort, $direction);

        $equipment = $query
            ->paginate($perPage)
            ->withQueryString()
            ->through(fn (Equipment $unit) => [
                'id' => $unit->id,
                'name' => $unit->name,
                // "Dell · S/N 7K2L9P3" under the name.
                'details' => self::details($unit),
                'inventory_number' => $unit->inventory_number,
                'type' => $unit->type?->name,
                'type_icon' => $unit->type?->icon,
                'status' => $unit->status,
                // The write-off form opens on it, so the list carries it too.
                'condition' => $unit->condition,
                'holder' => $unit->holder ? [
                    'id' => $unit->holder->id,
                    'name' => "{$unit->holder->surname} {$unit->holder->name}",
                    // Who holds it is the point of the column; their face is a line
                    // of their card, and travels only when that line is open.
                    'avatar' => EmployeeFields::showsAvatar($request->user(), $unit->holder) ? $unit->holder->avatar : null,
                ] : null,
                // Marked in the list, because it cuts across the statuses.
                'in_service' => (bool) $unit->repairs_exists,
                'issued_at' => $unit->issued_at?->toDateString(),
                'written_off_at' => $unit->written_off_at?->toDateString(),
            ]);

        return Inertia::render('equipment/index', [
            'view' => 'list',
            'equipment' => $equipment,
            'tab' => $tab,
            'sortable' => self::SORTS,
            ...$this->shared($request, $filters, $sort, $direction, $perPage),
        ]);
    }

    /**
     * Which of the two tables answers this request.
     *
     * The page opens on "По сотрудникам": who has what is the question it is
     * opened with, and the fleet unit by unit is the other way of reading it.
     * But the page was linked to long before that view existed, and those links
     * ask things only the unit list can answer — the dashboard opens it on
     * "tab=stock", the search on a category of the whole fleet. A tab, a status
     * or either date of issue means nothing by colleague, so a query carrying
     * one of them lands on the list rather than quietly losing what it asked
     * for. Naming the view outright settles it either way.
     */
    private static function opensByHolder(Request $request): bool
    {
        $named = $request->input('view');

        if ($named !== null) {
            return $named === 'holders';
        }

        return ! $request->anyFilled(['tab', 'status', 'issued_from', 'issued_to']);
    }

    /**
     * The same fleet read the other way round: one row per colleague, a column
     * per category, and in each cell what that colleague holds of it. The
     * question is who has what, so a unit nobody holds has no place in this
     * table, a name is printed once, and neither the statuses nor the day a
     * unit changed hands narrows anything — which is why the tabs and the two
     * date filters are read past rather than refused.
     *
     * @param  array<string, mixed>  $filters
     */
    private function byHolder(Request $request, array $filters, string $sort, string $direction, int $perPage): Response
    {
        // Every unit this person may see that is in somebody's hands, narrowed
        // by what the toolbar asks about the units themselves. Built afresh on
        // each call: the colleagues are found with it, their order is taken
        // from it, and the units of the page are read with it again.
        $held = fn () => Equipment::query()
            ->tap(fn (Builder $q) => EquipmentAccess::narrow($q, $request->user()))
            ->whereNotNull('holder_user_id')
            ->tap(fn (Builder $q) => $this->applyFilters($q, [
                ...$filters,
                // This table says nothing about the state of a unit or the day
                // it was handed over, so it is not narrowed by either.
                'status' => [],
                'issued_from' => null,
                'issued_to' => null,
            ]));

        $holders = User::query()
            ->whereIn('id', $held()->select('holder_user_id'))
            ->tap(fn (Builder $q) => $this->sortHolders($q, $held, $sort, $direction))
            ->paginate($perPage, ['id', 'name', 'surname', 'avatar'])
            ->withQueryString();

        // The units of the whole page in one go rather than a query per
        // colleague, each category's in the order the list reads them: by name.
        $units = $held()
            ->withIdentity()
            ->whereIn('holder_user_id', $holders->getCollection()->modelKeys())
            ->orderBy(Equipment::roleValueQuery('title'))
            ->orderBy('id')
            ->get(['id', 'equipment_type_id', 'holder_user_id'])
            ->groupBy('holder_user_id');

        return Inertia::render('equipment/index', [
            'view' => 'holders',
            'holders' => $holders->through(fn (User $holder) => [
                'id' => $holder->id,
                // Named as every list here names somebody.
                'name' => "{$holder->surname} {$holder->name}",
                // Their face is a line of their card, and travels only when
                // that line is open — the same rule the list's column follows.
                'avatar' => EmployeeFields::showsAvatar($request->user(), $holder) ? $holder->avatar : null,
                // By category id, so the page can read a cell off the column it
                // is drawing; a category they hold nothing of is simply absent.
                'units' => $units->get($holder->id, collect())
                    ->groupBy('equipment_type_id')
                    ->map(fn ($ofCategory) => $ofCategory->map(fn (Equipment $unit) => [
                        'id' => $unit->id,
                        'name' => $unit->name,
                        'inventory_number' => $unit->inventory_number,
                    ])->values()),
            ]),
            'sortable' => ['holder'],
            ...$this->shared($request, $filters, $sort, $direction, $perPage),
        ]);
    }

    /**
     * The order of the colleagues: by their name, or — the order the table
     * opens in — by the last time any of the units counted here was handed to
     * them. A tie falls back to the name and then to the id, so paging never
     * shows the same person twice.
     *
     * @param  Builder<User>  $query
     * @param  callable(): Builder<Equipment>  $held
     */
    private function sortHolders(Builder $query, callable $held, string $sort, string $direction): void
    {
        if ($sort === 'holder') {
            $query->orderBy('surname', $direction)->orderBy('name', $direction)->orderBy('id');

            return;
        }

        $query->orderBy(
            $held()->selectRaw('max(equipment.issued_at)')->whereColumn('equipment.holder_user_id', 'users.id'),
            $direction,
        )->orderBy('surname')->orderBy('name')->orderBy('id');
    }

    /**
     * What both tables are shown with: the filters as they were typed, the
     * paging, what the columns offer and what this person may do. Only the rows
     * themselves differ between the two.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function shared(Request $request, array $filters, string $sort, string $direction, int $perPage): array
    {
        return [
            'filters' => $filters,
            'sort' => ['key' => $sort, 'direction' => $direction],
            'perPage' => $perPage,
            'perPageOptions' => self::PER_PAGE_OPTIONS,
            'counts' => $this->counts($request->user()),
            'options' => [
                // The icon travels with the category rather than on every unit
                // under it: by colleague the category is the column, and the
                // list reads the same value off the unit's own row.
                'types' => EquipmentType::query()->orderBy('name')->get(['id', 'name', 'icon']),
                'statuses' => collect(Equipment::STATUSES)->map(fn (string $s) => ['value' => $s, 'label' => self::STATUS_LABELS[$s]])->all(),
                // Whom a unit can be handed to: everyone still working here.
                'holders' => User::query()
                    ->active()
                    ->orderBy('surname')
                    ->orderBy('name')
                    ->get(['id', 'name', 'surname'])
                    ->map(fn (User $u) => ['id' => $u->id, 'name' => "{$u->surname} {$u->name}"]),
            ],
            // Which blocks and moves this person may make. Every row on the page
            // is a unit they already see, so the rights alone decide it here.
            'can' => EquipmentAccess::allowed($request->user()),
            // Which parts of the fleet are open, so the page can say what it is
            // showing and offer the journal only where it is readable.
            'scopes' => EquipmentAccess::viewScopes($request->user()),
            'journalScopes' => EquipmentAccess::journalScopes($request->user()),
        ];
    }

    /** The form for a new unit: a page of its own, because it takes photographs. */
    public function create(Request $request): Response
    {
        // Opened from a colleague's card: the unit is put on the books and
        // handed to them in one go, so the page knows whose it will be.
        $employee = self::openedFor($request);

        if ($employee !== null) {
            $this->mayIssueFromCard($request, $employee);
        }

        return Inertia::render('equipment/create', [
            'forEmployee' => $employee === null ? null : [
                'id' => $employee->id,
                // Named as the holder list names everybody else.
                'name' => "{$employee->surname} {$employee->name}",
            ],
            'options' => [
                'types' => EquipmentType::formOptions(),
                'holders' => User::query()
                    ->active()
                    ->orderBy('surname')
                    ->orderBy('name')
                    ->get(['id', 'name', 'surname'])
                    ->map(fn (User $u) => ['id' => $u->id, 'name' => "{$u->surname} {$u->name}"]),
            ],
        ]);
    }

    /**
     * A unit joins the fleet on the balance sheet. It can be handed to a
     * colleague at once — hardware is usually bought for somebody — and the
     * handover is still recorded as a move, not folded into the new row.
     */
    public function store(Request $request): RedirectResponse
    {
        // Entered from a colleague's card, which says whose it is: the form is
        // not asked who it goes to, and what it sent about that is not read.
        $employee = self::openedFor($request);

        if ($employee !== null) {
            $this->mayIssueFromCard($request, $employee);
        }

        // The fields the form sent by field id: the category's own, without the
        // two it asks for by themselves below.
        $type = self::ownFields($request->integer('equipment_type_id'));

        $data = $request->validate([
            'equipment_type_id' => ['required', 'integer', Rule::exists('equipment_types', 'id')],
            'name' => ['required', 'string', 'max:150'],
            // The number on the sticker: one unit, one number. The column that
            // used to see to that is gone with its unique index, so the
            // inventory field's own values are asked instead — all of them,
            // whatever category the units holding them are filed under.
            'inventory_number' => ['required', 'string', 'max:50', Rule::unique('equipment_field_values', 'value')
                ->whereIn('equipment_field_id', EquipmentField::query()->where('role', 'inventory')->pluck('id')->all())],

            // Whatever the chosen category asks about: a processor for a laptop,
            // a diagonal for a monitor, an IMEI for a phone.
            ...$this->fieldRules($type),

            'condition' => ['nullable', 'string', 'max:200'],
            'checked_at' => ['nullable', 'date', 'before_or_equal:today'],
            'next_inventory_at' => ['nullable', 'date'],
            'accessories' => ['nullable', 'array'],
            'accessories.*' => ['string', 'max:100'],

            // How it looked on arrival, kept with the entry that records it.
            ...$this->photoRules(),

            // A unit often arrives for somebody in particular, so it can be
            // handed over in the same breath as it is put on the books.
            'holder_user_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            // The form's second button: stay here and enter the next unit.
            'another' => ['nullable', 'boolean'],
            'issued_at' => ['nullable', 'required_with:holder_user_id', 'date', 'before_or_equal:today'],
        ], attributes: [
            'equipment_type_id' => 'категория',
            'name' => 'наименование',
            'inventory_number' => 'инвентарный номер',
            'condition' => 'состояние',
            'checked_at' => 'последняя проверка',
            'next_inventory_at' => 'следующая инвентаризация',
            'accessories' => 'комплектация',
            'photos' => 'фотографии',
            'holder_user_id' => 'сотрудник',
            'issued_at' => 'дата выдачи',
            ...$this->fieldAttributes($type),
        ]);

        // Whose card the form was opened from outranks whatever it posted about
        // the holder: a page opened for somebody hands the unit to them.
        $holder = $employee?->id ?? $data['holder_user_id'] ?? null;

        $equipment = Equipment::create([
            ...Arr::except($data, ['name', 'inventory_number', 'holder_user_id', 'issued_at', 'photos', 'another', 'fields']),
            'status' => 'stock',
        ]);

        // What the unit is called and the number on its sticker are fields of
        // its category, which can only be written once the row they hang on
        // exists — so they follow the row rather than going into it.
        $equipment->writeIdentity($data['name'], $data['inventory_number']);
        // The arrival entry was written by the save above, a moment before the
        // unit had a number; it names it as it always has.
        EquipmentObserver::named($equipment);

        // Part of the unit from the day it arrives, so the entry that records
        // the arrival says all there is to say; no separate change is written.
        // The two the form asked for by themselves are already written, and
        // stay out of this: the form never sent them among the fields.
        $equipment->setRelation('type', $type);
        $this->saveFields($equipment, $data['fields'] ?? []);

        $photos = $request->file('photos') ?? [];

        if ($photos !== []) {
            // They belong to the entry that records the arrival, so the journal
            // shows the shape the unit came in.
            $arrival = $equipment->events()->latest('id')->firstOrFail();

            foreach ($photos as $photo) {
                EquipmentPhoto::keep($equipment, $arrival, $photo);
            }
        }

        if (! $holder) {
            return $this->afterCreating($equipment, $data, $employee);
        }

        // Handed over as it arrives. The move is made as a move rather than
        // written into the new row, so the journal shows both the arrival and
        // the handover, in that order.
        $equipment->update([
            'status' => 'issued',
            'holder_user_id' => $holder,
            // The form asks for the day it changed hands; a card that named the
            // colleague itself need not have been answered, and then it is today,
            // as it is when a whole workplace is handed over at once.
            'issued_at' => $data['issued_at'] ?? Carbon::today()->toDateString(),
        ]);

        return $this->afterCreating($equipment, $data, $employee);
    }

    /**
     * Whom the form was opened for: a colleague's card asks for a unit to be
     * entered and handed over in one go, and names them in "for" — the same
     * value on the way back in. Anything that does not name somebody still
     * working here names nobody at all, and the page is then the plain form
     * for a new unit rather than a refusal.
     */
    private static function openedFor(Request $request): ?User
    {
        $for = $request->input('for');

        // An id and nothing else: the value comes off a link, and a stray one
        // is not read as a number it happens to cast to.
        if (! is_int($for) && ! (is_string($for) && ctype_digit($for))) {
            return null;
        }

        return User::query()->active()->find((int) $for, ['id', 'name', 'surname']);
    }

    /**
     * Entering a unit for a named colleague is two jobs at once, and takes the
     * rights of both: the card's equipment block, as handing a unit over from
     * that card does, and the right to hand units over at all. The right to
     * bring a unit in is asked by the route, of this request as of any other.
     */
    private function mayIssueFromCard(Request $request, User $employee): void
    {
        abort_unless(
            $request->user()->can(EmployeeFields::blockGate('equipment'), $employee)
                && $request->user()->can('equipment.issue'),
            403,
        );
    }

    /**
     * Whoever entered a unit either wants to see its card or has a box of ten
     * more beside them. In the second case the form stays where it is, and the
     * unit that was just filed rides back so the page can name it.
     *
     * A form opened from a colleague's card came from that card and goes back
     * to it, where the unit is now on their list; the box of ten keeps the form
     * open for the same colleague rather than for nobody.
     *
     * @param  array<string, mixed>  $data
     */
    private function afterCreating(Equipment $equipment, array $data, ?User $employee = null): RedirectResponse
    {
        if (! ($data['another'] ?? false)) {
            return $employee === null
                ? to_route('equipment.show', $equipment)
                : to_route('employees.show', $employee);
        }

        $form = $employee === null
            ? back()
            : to_route('equipment.create', ['for' => $employee->id]);

        return $form->with('equipment', [
            'id' => $equipment->id,
            'name' => $equipment->name,
            'inventory_number' => $equipment->inventory_number,
        ]);
    }

    /**
     * One unit's card: what it is, where it has been, what has been done to it
     * and the papers that came with it.
     */
    public function show(Request $request, Equipment $equipment): Response
    {
        $equipment->load([
            'type.fields',
            // With the fields they belong to: the name and the number on the
            // card are read off the two that carry a role.
            'fieldValues.field:id,role,name',
            'holder:id,name,surname,avatar',
            'repairs.photos',
            'events.user:id,name,surname,avatar',
            'events.photos',
        ]);

        $holderDepartment = $equipment->holder?->departments()->orderBy('name')->first();

        return Inertia::render('equipment/show', [
            'unit' => [
                'id' => $equipment->id,
                'name' => $equipment->name,
                'equipment_type_id' => $equipment->equipment_type_id,
                'type' => $equipment->type?->name,
                'type_icon' => $equipment->type?->icon,
                'inventory_number' => $equipment->inventory_number,
                // What this category asks about, and what this unit answers.
                'fields' => $equipment->type?->formFields($equipment) ?? [],
                'condition' => $equipment->condition,
                'checked_at' => $equipment->checked_at?->toDateString(),
                'next_inventory_at' => $equipment->next_inventory_at?->toDateString(),
                // A category whose units come with nothing has no list here,
                // and the card leaves the block out rather than showing a blank.
                'accessories' => $equipment->type?->has_accessories ? ($equipment->accessories ?? []) : null,
                'status' => $equipment->status,
                'issued_at' => $equipment->issued_at?->toDateString(),
                'written_off_at' => $equipment->written_off_at?->toDateString(),
                'holder' => $equipment->holder ? [
                    'id' => $equipment->holder->id,
                    'name' => "{$equipment->holder->surname} {$equipment->holder->name}",
                    'avatar' => EmployeeFields::showsAvatar($request->user(), $equipment->holder) ? $equipment->holder->avatar : null,
                    // Named as every screen but the directory names a department:
                    // by its abbreviation, or by its full name where it has none.
                    'department' => $holderDepartment?->display_name,
                ] : null,
            ],
            'repairs' => $equipment->repairs->map(fn ($repair) => [
                'id' => $repair->id,
                'kind' => $repair->kind,
                'started_at' => $repair->started_at->toDateString(),
                'ended_at' => $repair->ended_at?->toDateString(),
                'note' => $repair->note,
                'photos' => $repair->photos->map(fn ($photo) => [
                    'id' => $photo->id,
                    'url' => $photo->url,
                    'preview' => $photo->preview_url,
                ]),
            ]),
            // The ids the journal kept, read back as the names behind them.
            'names' => EquipmentEvent::namesFor($equipment->events),
            // Everything that has happened to this one unit, newest first.
            // The journal of this unit is a question of its own: one may hold a
            // laptop and not be shown who had it before.
            'events' => $request->user()->can('viewJournal', $equipment) ? $equipment->events->map(fn ($event) => [
                'id' => $event->id,
                'kind' => $event->kind,
                'changes' => $event->diff ?? [],
                'note' => $event->note,
                'at' => $event->created_at?->toIso8601String(),
                'actor' => $event->user === null ? null : [
                    'id' => $event->user->id,
                    'name' => "{$event->user->surname} {$event->user->name}",
                    'avatar' => EmployeeFields::showsAvatar($request->user(), $event->user) ? $event->user->avatar : null,
                ],
                'photos' => $event->photos->map(fn ($photo) => [
                    'id' => $photo->id,
                    'url' => $photo->url,
                    'preview' => $photo->preview_url,
                ]),
            ]) : [],
            'holders' => User::query()
                ->active()
                ->orderBy('surname')
                ->orderBy('name')
                ->get(['id', 'name', 'surname'])
                ->map(fn (User $u) => ['id' => $u->id, 'name' => "{$u->surname} {$u->name}"]),
            // What the card's forms offer; only an editor needs any of it.
            'types' => EquipmentAccess::edits($request->user()) ? EquipmentType::formOptions() : [],
            'neighbours' => $this->neighbours($equipment),
            // Block by block and move by move, asked of this very unit.
            'can' => EquipmentAccess::allowed($request->user()),
            // What happened to this unit is a question of its own, asked of this
            // very unit: one may keep a laptop and not be shown its history.
            'canReadJournal' => $request->user()->can('viewJournal', $equipment),
        ]);
    }

    /**
     * Struck off the books altogether — for a duplicate or a unit entered by
     * mistake. Only a written-off unit may go: while it is still part of the
     * fleet it is somebody's to account for, and a mistake is written off
     * first. Its history, repairs and journal go with it, so nothing is
     * left pointing at a unit that no longer exists.
     */
    public function destroy(Equipment $equipment): RedirectResponse
    {
        // The list was open while the unit went back into service. Back with a
        // sentence rather than a refusal: what asks is a dialog, and it closes on
        // a page of ours and stays open, silent, on anything else.
        if ($equipment->status !== 'written_off') {
            return back()->with('notice', 'Удалить можно только списанное оборудование.');
        }

        // The photographs are on disk; the rows go by themselves, files do not.
        Storage::disk('public')->delete($equipment->photos->flatMap(fn ($photo) => [$photo->path, $photo->preview])->all());

        $equipment->delete();

        // Back to the units, not to the table of who holds what: somebody who
        // has just struck a unit off is looking at the fleet, not at people.
        return to_route('equipment.index', ['view' => 'list']);
    }

    /**
     * One category with the fields it asks about by field id: its own, without
     * the two a unit cannot be without. A form sends those two as "name" and
     * "inventory_number" rather than among the fields — the page leaves them
     * out of that list the same way — so the rules and the saving ask about
     * what is left. The page is given every field, roles and all.
     */
    private static function ownFields(int $type): ?EquipmentType
    {
        return EquipmentType::query()
            ->with(['fields' => fn ($fields) => $fields->whereNull('role')])
            ->find($type);
    }

    /**
     * The line under a unit's name in the list: the first couple of things its
     * category asks about, as this unit answered them. For a laptop that reads
     * "Dell · Latitude 5440", for a phone the IMEI — whatever the category
     * happens to put first, which is the order the directory set.
     */
    private static function details(Equipment $unit): ?string
    {
        $held = $unit->fieldValues->keyBy('equipment_field_id');

        $lines = collect($unit->type?->fields ?? [])
            // What the unit is called and its number are fields of the category
            // now; the row prints both in their own right and does not repeat
            // either of them under the name.
            ->reject(fn (EquipmentField $field) => $field->isRole())
            ->map(fn (EquipmentField $field) => $field->read($held->get($field->id)?->value))
            ->filter()
            ->take(2);

        return $lines->isEmpty() ? null : $lines->join(' · ');
    }

    /**
     * The units either side of this one, by name — the order a card is walked
     * in, whichever way the list it was opened from happens to be sorted — the
     * id breaking a tie between two of a kind. Walking stays within
     * one status, as it does between colleagues — stepping off a unit that is
     * out and landing on one in stock compares nothing.
     *
     * @return array{prev: ?array<string, mixed>, next: ?array<string, mixed>}
     */
    private function neighbours(Equipment $equipment): array
    {
        // The name is a field of the unit's category now, so the order and the
        // comparisons ask it of the values rather than of a column. Each call
        // builds a subquery of its own: one query cannot be used twice.
        $name = fn () => Equipment::roleValueQuery('title');

        $order = fn (string $direction) => Equipment::query()
            ->withIdentity()
            ->where('status', $equipment->status)
            ->whereKeyNot($equipment->id)
            ->orderBy($name(), $direction)
            ->orderBy('id', $direction);

        // "Before" means earlier in (name, id) order; a tie on the name falls back to the id.
        $before = fn (Builder $q) => $q->where(fn (Builder $q) => $q
            ->where($name(), '<', $equipment->name)
            ->orWhere(fn (Builder $q) => $q->where($name(), $equipment->name)->where('id', '<', $equipment->id)));
        $after = fn (Builder $q) => $q->where(fn (Builder $q) => $q
            ->where($name(), '>', $equipment->name)
            ->orWhere(fn (Builder $q) => $q->where($name(), $equipment->name)->where('id', '>', $equipment->id)));

        $unit = fn (?Equipment $u) => $u ? ['id' => $u->id, 'name' => $u->name, 'inventory_number' => $u->inventory_number] : null;

        return [
            'prev' => $unit($order('desc')->tap($before)->first(['id', 'equipment_type_id'])),
            'next' => $unit($order('asc')->tap($after)->first(['id', 'equipment_type_id'])),
        ];
    }

    /** Spelled out for the status filter; the page has its own copy for badges. */
    private const STATUS_LABELS = [
        'issued' => 'Выдано',
        'stock' => 'На балансе',
        'written_off' => 'Списано',
    ];

    /**
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        // By what the two columns print, which are fields of the unit's
        // category now: each asks the value itself, one unit at a time.
        $query->when($filters['name'], fn (Builder $q, string $term) => $q->where(Equipment::roleValueQuery('title'), 'like', "%{$term}%"));
        $query->when($filters['inventory_number'], fn (Builder $q, string $term) => $q->where(Equipment::roleValueQuery('inventory'), 'like', "%{$term}%"));
        $query->when($filters['type'], fn (Builder $q, array $ids) => $q->whereIn('equipment_type_id', $ids));
        $query->when($filters['status'], fn (Builder $q, array $statuses) => $q->whereIn('status', $statuses));
        // By the name the column prints: the colleague, or the department a unit
        // is signed out to. Each word has to land somewhere in the person's
        // name, so "Абдуллаев Фарход" finds them as surely as "фарход" does.
        $query->when($filters['holder'], function (Builder $query, string $term) {
            $words = self::words($term);

            $query->where(fn (Builder $q) => $q
                ->whereHas('holder', function (Builder $q) use ($words) {
                    foreach ($words as $word) {
                        $q->where(fn (Builder $q) => $q->where('surname', 'like', "%{$word}%")->orWhere('name', 'like', "%{$word}%"));
                    }
                }));
        });
        $query->when($filters['issued_from'], fn (Builder $q, string $date) => $q->whereDate('issued_at', '>=', $date));
        $query->when($filters['issued_to'], fn (Builder $q, string $date) => $q->whereDate('issued_at', '<=', $date));

        // One box over everything the row prints about a unit: what it is called,
        // its sticker and whatever its category asks about — a serial number, an
        // IMEI, a model — the category it is filed under, and who holds it. It
        // stays one question: a unit is found when the term lands anywhere, and
        // the viewer's slice of the fleet is not widened by asking.
        $query->when($filters['q'], function (Builder $query, string $term) {
            // The three fields a unit is described by all hold the term whole,
            // because that is how it was typed. A person's name is the one thing
            // typed in pieces, and is looked for piece by piece.
            $words = self::words($term);

            $query->where(fn (Builder $q) => $q
                ->whereHas('fieldValues', fn (Builder $q) => $q->where('value', 'like', "%{$term}%"))
                ->orWhereHas('type', fn (Builder $q) => $q->where('name', 'like', "%{$term}%"))
                ->orWhereHas('holder', fn (Builder $q) => self::whereNamed($q, $words)));
        });
    }

    /**
     * A typed name broken into the words it was typed in, so each can be looked
     * for on its own: "Абдуллоев Фарход" then finds the colleague as surely as
     * "фарход" does, whichever way round the two are given.
     *
     * @return list<string>
     */
    private static function words(string $term): array
    {
        return preg_split('/\s+/u', $term, -1, PREG_SPLIT_NO_EMPTY) ?: [$term];
    }

    /**
     * A colleague whose ФИО holds every word that was typed, each word anywhere
     * in any of the three parts. The patronymic is among them because this is a
     * search for a person, and a person is named in full.
     *
     * @param  Builder<User>  $query
     * @param  list<string>  $words
     */
    private static function whereNamed(Builder $query, array $words): void
    {
        foreach ($words as $word) {
            $query->where(fn (Builder $q) => $q
                ->where('surname', 'like', "%{$word}%")
                ->orWhere('name', 'like', "%{$word}%")
                ->orWhere('patronymic', 'like', "%{$word}%"));
        }
    }

    private function applySort(Builder $query, string $sort, string $direction): void
    {
        match ($sort) {
            // When the unit was put on the books, not when it was bought or
            // handed over: the id breaks a tie, so two units entered in the
            // same second keep the order they were entered in.
            'created' => $query->orderBy('created_at', $direction)->orderBy('id', $direction),
            // The two a unit is named by live in its category's fields, so the
            // order is taken from the value each unit holds there.
            'name' => $query->orderBy(Equipment::roleValueQuery('title'), $direction),
            'inventory_number' => $query->orderBy(Equipment::roleValueQuery('inventory'), $direction),
            // Sorting by a related name, not by the foreign key behind it.
            'type' => $query->orderBy(EquipmentType::select('name')->whereColumn('equipment_types.id', 'equipment.equipment_type_id'), $direction),
            'holder' => $query->orderBy(User::select('surname')->whereColumn('users.id', 'equipment.holder_user_id'), $direction),
            // Down the tabs: issued, on the balance sheet, written off.
            'status' => $query->orderByRaw(
                "case status when 'issued' then 0 when 'stock' then 1 else 2 end ".($direction === 'desc' ? 'desc' : 'asc')
            ),
            default => $query->orderBy($sort, $direction),
        };

        // A stable order, so paging never shows the same unit twice. Sorting by
        // when a unit arrived has already said how a tie falls.
        if ($sort !== 'created') {
            $query->orderBy('id');
        }
    }

    /**
     * The number beside each tab, the whole fleet included.
     *
     * @return array<string, int>
     */
    private function counts(User $viewer): array
    {
        // Counted over the same slice the list shows: a tab promising eleven
        // units and opening on three would be worse than no number at all.
        $seen = fn () => Equipment::query()->tap(fn (Builder $q) => EquipmentAccess::narrow($q, $viewer));
        $byStatus = $seen()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return [
            'all' => (int) $byStatus->sum(),
            ...collect(Equipment::STATUSES)->mapWithKeys(fn (string $s) => [$s => (int) ($byStatus[$s] ?? 0)])->all(),
            'service' => $seen()->underService()->count(),
        ];
    }
}
