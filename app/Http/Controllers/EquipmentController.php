<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\KeepsEquipmentPhotos;
use App\Http\Controllers\Concerns\SavesEquipmentFields;
use App\Models\Department;
use App\Models\Equipment;
use App\Models\EquipmentEvent;
use App\Models\EquipmentField;
use App\Models\EquipmentPhoto;
use App\Models\EquipmentType;
use App\Models\User;
use App\Observers\EquipmentObserver;
use App\Support\EmployeeFields;
use App\Support\EquipmentAccess;
use App\Support\EquipmentName;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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

    /** The one answer of that column that is not a status of a unit. */
    private const SERVICE = 'service';

    public const PER_PAGE_OPTIONS = [25, 50, 100];

    /** Columns the list can be ordered by, named as the table names them. */
    private const SORTS = ['created', 'department', 'unit', 'name', 'inventory_number', 'type', 'status', 'holder', 'issued_at'];

    /**
     * What the two structure funnels call «Без департамента» and «Без отдела»:
     * filed in nothing of that kind, which is what their column shows as a dash.
     */
    public const NO_UNIT = 'none';

    /**
     * The order both tables open in, and the one a third press on a header
     * comes back to: the company read down — the department, the unit inside
     * it, the people in each and what each of them holds.
     *
     * It is an order of its own rather than a column, which is what lets a
     * header be pressed up, down and back to it, and what lets the fleet be
     * read by department the moment the page opens: the cells merge over
     * neighbours that say the same thing, so a department is one block only
     * while its rows are side by side, and no order but this one puts them
     * there. Opening on the date a unit was entered left the departments
     * scattered down their own column and the two columns saying the structure
     * had nothing to say.
     *
     * No header carries it, so nothing opens with an arrow on it and there is
     * nothing to reset; the phone's sheet names it «Структура», since there is
     * no column to carry the name of an order that belongs to none.
     */
    private const STRUCTURE = 'structure';

    private const DEFAULT_SORT = self::STRUCTURE;

    /**
     * Everything the list will answer to: its columns and that order.
     *
     * "created" is among the columns now. The date a unit was entered used to
     * be the opening order and no column at all — the one question the table
     * answered without a header to press — so it has a column of its own
     * rather than becoming unreachable; it is hidden until somebody asks for
     * it, and the table is no wider than it was.
     */
    private const LIST_ORDERS = [...self::SORTS, self::STRUCTURE];

    /**
     * The second table is read by colleague, so what orders it is where that
     * colleague stands and what they are called; the other columns are
     * categories, and a category holds a list rather than a value.
     */
    private const HOLDER_SORTS = ['department', 'unit', 'holder'];

    /**
     * Those, the structure it opens in, and "issued" — whoever was handed
     * something last, which this table opened on until the structure took its
     * place. It answers to it still, so a link or a remembered query carrying
     * it is served rather than refused.
     */
    private const HOLDER_ORDERS = [...self::HOLDER_SORTS, self::STRUCTURE, 'issued'];

    private const DEFAULT_HOLDER_SORT = self::STRUCTURE;

    /**
     * The name a department is shown under, read off its own row: the
     * abbreviation it is known by, or its full name where it has none. An empty
     * abbreviation is no abbreviation either, so that the database picks the
     * name `Department::$display_name` picks for the page.
     */
    private const OWN_SHOWN = "coalesce(nullif(departments.abbreviation, ''), departments.name)";

    /**
     * The department a row is read under, which is the first step of where its
     * holder stands: the department above their own where that one is a unit
     * inside it, and their own where it is a department of the company. A
     * parent that is there answers first, and a row with no parent falls
     * through to the department itself.
     */
    private const DEPARTMENT_SHOWN = "coalesce(nullif(parents.abbreviation, ''), parents.name, nullif(departments.abbreviation, ''), departments.name)";

    /**
     * The second step: the unit inside that department, where the holder stands
     * in one. A holder filed on a department itself has none, and that is
     * nothing missing — several departments keep no units at all.
     */
    private const UNIT_SHOWN = 'case when departments.parent_id is null then null else '.self::OWN_SHOWN.' end';

    public function index(Request $request): Response
    {
        // Two readings of the same fleet on the same page: one row per
        // colleague with what each of them holds, or the fleet unit by unit.
        // Which one is settled before anything else is read, because the two
        // are not ordered by the same things.
        $byHolder = self::opensByHolder($request);

        $input = $request->validate([
            // Carried by older links and by arrangements kept before the two
            // tables became two pages; read past, since the address decides.
            'view' => ['nullable', Rule::in(['list', 'holders'])],
            'per_page' => ['nullable', 'integer', Rule::in(self::PER_PAGE_OPTIONS)],

            'sort' => ['nullable', Rule::in($byHolder ? self::HOLDER_ORDERS : self::LIST_ORDERS)],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],

            // The toolbar search, across everything printed on a unit.
            'q' => ['nullable', 'string', 'max:100'],
            // One per column.
            'name' => ['nullable', 'string', 'max:100'],
            'inventory_number' => ['nullable', 'string', 'max:50'],
            'type' => ['nullable', 'array'],
            'type.*' => ['integer', Rule::exists('equipment_types', 'id')],
            'status' => ['nullable', 'array'],
            // «На обслуживании» is not a status — a unit can be with its owner
            // and on service at once — but it is one of the answers the column
            // is asked for, as it is one of the tabs above the table.
            'status.*' => [Rule::in([...Equipment::STATUSES, self::SERVICE])],
            // Where the holder stands, a column apiece: a department of the
            // company, and a unit inside one. Both are departments as far as the
            // directory is concerned, so both are checked against it.
            'department' => ['nullable', 'array'],
            'department.*' => ['integer', Rule::exists('departments', 'id')],
            'unit' => ['nullable', 'array'],
            'unit.*' => ['integer', Rule::exists('departments', 'id')],
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
            'department' => array_map(fn ($value) => $value === self::NO_UNIT ? $value : (int) $value, $input['department'] ?? []),
            'unit' => array_map(fn ($value) => $value === self::NO_UNIT ? $value : (int) $value, $input['unit'] ?? []),
            'holder' => trim($input['holder'] ?? ''),
            'issued_from' => $input['issued_from'] ?? null,
            'issued_to' => $input['issued_to'] ?? null,
        ];

        // The list opens on the whole fleet: what the company owns is the
        // question the page is opened with, and a status is how that is
        // narrowed afterwards. A tab asked for by name still settles it.
        $tab = $input['tab'] ?? 'all';
        $sort = $input['sort'] ?? ($byHolder ? self::DEFAULT_HOLDER_SORT : self::DEFAULT_SORT);
        // From the top: a column asked for by name reads down from A, and so
        // does the structure the table opens in — the company is read from the
        // first department, not from the last. The two orders that read newest
        // first say so in the query; neither is the default any more.
        $direction = $input['direction'] ?? 'asc';
        $perPage = (int) ($input['per_page'] ?? self::PER_PAGE_OPTIONS[0]);

        if ($byHolder) {
            return $this->byHolder($request, $filters, $sort, $direction, $perPage);
        }

        // The values come with the fields they belong to: the name and the
        // number are read off the two that carry a role, and a page of units
        // should cost one query for the lot rather than one apiece.
        // Where the holder stands comes with the holder, both steps of it: the
        // table reads a unit under a department and under the unit inside it,
        // and the structure is two deep, so a department and its parent are the
        // whole of the path. Read with the page of units rather than row by row.
        $query = Equipment::query()->with([
            'type:id,name,icon',
            'type.fields',
            'fieldValues.field:id,role,name',
            'holder:id,name,surname,avatar,avatar_original',
            'holder.departments:id,name,abbreviation,parent_id',
            'holder.departments.parent:id,name,abbreviation',
            // And the other kind of holder: the department a unit of such a
            // category is signed out to, read under itself and under the
            // department above it exactly as a colleague's is.
            'holderDepartment:id,name,abbreviation,parent_id',
            'holderDepartment.parent:id,name,abbreviation',
        ]);
        // Whatever the tabs and filters then do, the list starts from the part of
        // the fleet this person may see at all.
        EquipmentAccess::narrow($query, $request->user());
        // And from the part of it this page is about: a desk is not read under
        // «Рабочее оборудование», and a laptop is not read under «Мебель».
        $query->when(self::part($request), fn (Builder $q, string $part) => $q->ofPart($part));
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
                // Ready to be read: a unit whose category keeps no field for a
                // name has none, and a row that printed nothing would read as
                // an empty line rather than as a unit without a name.
                'name' => EquipmentName::read($unit->name),
                // "Dell · S/N 7K2L9P3" under the name.
                'details' => self::details($unit),
                'inventory_number' => EquipmentName::readNumber($unit->inventory_number),
                'type' => $unit->type?->name,
                'type_icon' => $unit->type?->icon,
                'status' => $unit->status,
                // The write-off form opens on it, so the list carries it too.
                'condition' => $unit->condition,
                // Where it is read in the structure, off whichever kind of
                // holder it has. Null twice over — for a unit nobody holds and
                // for a colleague standing in no department — and the page
                // tells those two apart by the holder itself: the first is
                // where a unit on the balance sheet belongs, the second is a
                // row somebody still has to file.
                'department' => self::filingOf($unit->heldBy()),
                // Whether this unit is a department's own rather than
                // anybody's, which the page says in the «У кого» column with
                // the dash the user asked for. Read off the row and not off
                // the category: a category switched kind while this unit was
                // out is exactly the case the two must not disagree on.
                'held_by_department' => $unit->holder_department_id !== null,
                'holder' => $unit->holder ? [
                    'id' => $unit->holder->id,
                    'name' => "{$unit->holder->surname} {$unit->holder->name}",
                    // Who holds it is the point of the column; their face is a line
                    // of their card, and travels only when that line is open.
                    'avatar' => EmployeeFields::showsAvatar($request->user(), $unit->holder) ? $unit->holder->avatar : null,
                    // The upload behind it, which is what pressing the face opens.
                    'avatar_original' => EmployeeFields::showsAvatar($request->user(), $unit->holder) ? $unit->holder->avatar_original : null,
                ] : null,
                // Marked in the list, because it cuts across the statuses.
                'in_service' => (bool) $unit->repairs_exists,
                'issued_at' => $unit->issued_at?->toDateString(),
                'written_off_at' => $unit->written_off_at?->toDateString(),
                // When it was put on the books, which the list used to open on
                // and now shows in a column of its own: the question «что
                // добавили недавно» outlived the order that answered it.
                'created_at' => $unit->created_at?->toDateString(),
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
     * The entry of the menu a page of this section belongs to, as the menu
     * knows it: by the address it leads to.
     */
    private static function menuOf(?string $part): string
    {
        return match ($part) {
            'furniture' => '/inventory/furniture',
            'office' => '/inventory/office',
            'equipment' => '/inventory/equipment',
            default => '/inventory',
        };
    }

    /**
     * Which part of the stock this page is: the furniture, what the office
     * runs on, what people work on — or nothing at all, for «Обзор», which is
     * the whole of it.
     */
    private static function part(Request $request): ?string
    {
        return match (true) {
            $request->routeIs('inventory.furniture', 'inventory.furniture.create') => 'furniture',
            $request->routeIs('inventory.office', 'inventory.office.create') => 'office',
            // «Обзор» and the form opened from it are about the whole stock.
            $request->routeIs('inventory.overview', 'inventory.create') => null,
            default => 'equipment',
        };
    }

    /**
     * Which of the two tables answers this request: the address says, and it
     * is the only thing that does.
     *
     * The fleet is read two ways and the two are pages rather than views of
     * one page now. «Обзор» of «Инвентаризация» is what the company owns, unit
     * by unit, with the tabs and the statuses that belong to units; «Рабочее
     * оборудование» is who holds what, one row per colleague. A query asking
     * for the other one is read past: the question is settled before anything
     * is asked of it.
     */
    private static function opensByHolder(Request $request): bool
    {
        return ! $request->routeIs('inventory.overview');
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
            // The part of the stock this page is about, as the list reads it.
            ->when(self::part($request), fn (Builder $q, string $part) => $q->ofPart($part))
            // In somebody's hands: a colleague's, or a department's own.
            ->where(fn (Builder $q) => $q->whereNotNull('holder_user_id')->orWhereNotNull('holder_department_id'))

            ->tap(fn (Builder $q) => $this->applyFilters($q, [
                ...$filters,
                // This table says nothing about the state of a unit or the day
                // it was handed over, so it is not narrowed by either.
                'status' => [],
                'issued_from' => null,
                'issued_to' => null,
            ]));

        // Everybody who holds something, and every department that holds
        // something of its own. Both are rows of this table and both are read
        // under the same structure, so the two are put in one order and that
        // order is paged — rather than a page of colleagues with the
        // departments scattered around it.
        $people = User::query()
            ->whereIn('id', $held()->select('holder_user_id'))
            // Where each of them stands, as the unit list reads it off a
            // holder: the two columns this table opens with say it, and the
            // rows of one department stand together under one name.
            ->with(['departments:id,name,abbreviation,parent_id', 'departments.parent:id,name,abbreviation'])
            ->get(['id', 'name', 'surname', 'avatar', 'avatar_original']);

        $departments = Department::query()
            ->whereIn('id', $held()->select('holder_department_id'))
            ->with('parent:id,name,abbreviation')
            ->get(['id', 'name', 'abbreviation', 'parent_id']);

        $rows = $this->holderRows($people, $departments, $held, $sort, $direction);
        $page = LengthAwarePaginator::resolveCurrentPage();
        $holders = new LengthAwarePaginator(
            $rows->forPage($page, $perPage)->values(),
            $rows->count(),
            $perPage,
            $page,
            ['path' => LengthAwarePaginator::resolveCurrentPath()],
        );
        $holders->withQueryString();

        // The units of the whole page in one go rather than a query per row,
        // each category's in the order the list reads them: by name. Both kinds
        // of holder are asked for together, because a page holds both.
        $onPage = $holders->getCollection();
        $units = $held()
            // Every value a unit holds and the fields of its category: the two
            // that name it, and the ones the line under the name is read off.
            // They come with the units of the page rather than unit by unit, so
            // the table costs the same few reads whatever the rows on it
            // happen to hold.
            ->with(['type.fields', 'fieldValues.field:id,role,name'])
            ->where(fn (Builder $q) => $q
                ->whereIn('holder_user_id', self::keysOf($onPage, 'person'))
                ->orWhereIn('holder_department_id', self::keysOf($onPage, 'department')))
            ->orderByRole('title')
            ->orderBy('id')
            ->get(['id', 'equipment_type_id', 'holder_user_id', 'holder_department_id'])
            // Under the key of whichever holder they stand against, so a cell
            // is read off the row that is being drawn rather than off its kind.
            ->groupBy(fn (Equipment $unit) => self::rowKey(
                $unit->holder_user_id === null ? 'department' : 'person',
                $unit->holder_user_id ?? $unit->holder_department_id,
            ));

        return Inertia::render('equipment/index', [
            'view' => 'holders',
            'holders' => $holders->through(fn (array $row) => [
                'id' => $row['model']->id,
                // A colleague as every list here names somebody; a department
                // as every screen but the directory names one.
                'name' => $row['name'],
                // Which kind of row this is, since the two are drawn
                // differently: a department is not a who, and the page says so
                // rather than dressing one up as a colleague.
                'kind' => $row['kind'],
                // Where it stands, read the same way the unit list reads it:
                // one row per holder here, so a department's cell covers its
                // own row and its people's with everything they hold beside
                // them. Null for a colleague nobody has filed in a department.
                'department' => self::filingOf($row['filing']),
                // Their face is a line of their card, and travels only when
                // that line is open — the same rule the list's column follows.
                // A department has none; it is drawn by its name.
                'avatar' => $row['kind'] === 'person' && EmployeeFields::showsAvatar($request->user(), $row['model'])
                    ? $row['model']->avatar
                    : null,
                // The upload behind it, which is what pressing the face opens.
                'avatar_original' => $row['kind'] === 'person' && EmployeeFields::showsAvatar($request->user(), $row['model'])
                    ? $row['model']->avatar_original
                    : null,
                // By category id, so the page can read a cell off the column it
                // is drawing; a category it holds nothing of is simply absent.
                'units' => $units->get(self::rowKey($row['kind'], $row['model']->id), collect())
                    ->groupBy('equipment_type_id')
                    ->map(fn ($ofCategory) => $ofCategory->map(fn (Equipment $unit) => [
                        'id' => $unit->id,
                        // Named as the unit list names it, nameless units
                        // included: one unit reads the same either way round.
                        'name' => EquipmentName::read($unit->name),
                        // The same line the unit list prints under a name, so a
                        // unit says the same thing whichever way it is read.
                        'details' => self::details($unit),
                        'inventory_number' => EquipmentName::readNumber($unit->inventory_number),
                    ])->values()),
            ]),
            'sortable' => ['department', 'unit', 'holder'],
            ...$this->shared($request, $filters, $sort, $direction, $perPage),
        ]);
    }

    /**
     * A row of «По сотрудникам» named so that two kinds of holder cannot
     * collide: a colleague and a department may share an id, and what they hold
     * is looked up by this.
     */
    private static function rowKey(string $kind, int|string $id): string
    {
        return "{$kind}:{$id}";
    }

    /** The ids of one kind of row on this page, for the one query that reads their units. */
    private static function keysOf(Collection $rows, string $kind): array
    {
        return $rows->where('kind', $kind)->map(fn (array $row) => $row['model']->id)->all();
    }

    /**
     * The rows of «По сотрудникам» in the order they are read: the structure
     * down, and inside each department its own equipment first and then the
     * people who work there.
     *
     * Ordered here rather than in the database, which is where every other list
     * in this section is ordered. Two kinds of row cannot be put in one order
     * by one query without a union, and a union of two shapes is harder to read
     * — and to keep true — than this is; and the rows are few, since only
     * somebody who actually holds something is on this table at all. The cost
     * is that the whole of them is read to draw a page of them, which for a
     * fleet of this size is one query returning a few hundred rows.
     *
     * The department's own row opens its block because nothing sorts before
     * every name there is: the printer of the отдел аналитики is the отдел
     * аналитики's own, and the people who work there follow it — which is the
     * same order the unit list puts the same equipment in.
     *
     * @param  \Illuminate\Database\Eloquent\Collection<int, User>  $people
     * @param  \Illuminate\Database\Eloquent\Collection<int, Department>  $departments
     * @param  callable(): Builder<Equipment>  $held
     * @return Collection<int, array<string, mixed>>
     */
    private function holderRows(iterable $people, iterable $departments, callable $held, string $sort, string $direction): Collection
    {
        $rows = collect();

        foreach ($departments as $department) {
            $rows->push([
                'kind' => 'department',
                'model' => $department,
                'filing' => $department,
                'name' => $department->display_name,
            ]);
        }

        foreach ($people as $person) {
            $rows->push([
                'kind' => 'person',
                'model' => $person,
                'filing' => self::standsIn($person),
                'name' => "{$person->surname} {$person->name}",
            ]);
        }

        // The order this table opened on before the structure took its place,
        // and which a link may still ask for: whoever was handed something
        // last. Read for every row in one go, as one question about the units.
        $handed = $sort === 'issued'
            ? $held()->selectRaw('holder_user_id, holder_department_id, max(issued_at) as last')
                ->groupBy('holder_user_id', 'holder_department_id')
                ->get()
                ->mapWithKeys(fn ($row) => [
                    self::rowKey($row->holder_user_id === null ? 'department' : 'person', $row->holder_user_id ?? $row->holder_department_id) => (string) $row->last,
                ])
            : collect();

        $sign = $direction === 'desc' ? -1 : 1;
        // Ordered by where the rows stand, a department comes before the people
        // of its own block; ordered by who holds the thing it has no name to
        // stand among them with at all, and reads last. Either way ties read by
        // name and then by id, so that paging never shows one row twice and
        // never leaves one out.
        $compare = match ($sort) {
            // A department is not a who. Its cell says «оборудование отдела»
            // rather than naming anybody, so there is no name in it to put in
            // order among the people's — and ranked by its own abbreviation it
            // would land between two colleagues, which reads as a person
            // nobody can find. The kind is compared before the name, so those
            // rows read last whichever way the column is sorted, as everything
            // a sorted column has nothing to show for does here.
            'holder' => function (array $a, array $b) use ($sign) {
                $named = ($a['kind'] === 'person' ? 0 : 1) <=> ($b['kind'] === 'person' ? 0 : 1);

                return $named ?: $sign * self::compareNames($a['name'], $b['name']);
            },
            'issued' => fn (array $a, array $b) => $sign * strcmp(
                $handed->get(self::rowKey($a['kind'], $a['model']->id), ''),
                $handed->get(self::rowKey($b['kind'], $b['model']->id), ''),
            ) ?: self::compareNames($a['name'], $b['name']),
            default => fn (array $a, array $b) => self::compareFilings($a, $b, $sort, $sign),
        };

        return $rows->sort(fn (array $a, array $b) => $compare($a, $b) ?: $a['model']->id <=> $b['model']->id)->values();
    }

    /**
     * Two rows by where they stand: the department, the unit inside it, the
     * department's own row before the people of it, and then the name.
     *
     * The same comparison the database makes for the list of units, written
     * again because these rows are put in order here. A row nobody has filed
     * reads last whichever way the column is sorted, as it does there.
     *
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    private static function compareFilings(array $a, array $b, string $sort, int $sign): int
    {
        $steps = fn (array $row) => [
            $row['filing']?->parent->display_name ?? $row['filing']?->display_name,
            $row['filing']?->parent === null ? null : $row['filing']->display_name,
        ];

        [$department, $unit] = $steps($a);
        [$otherDepartment, $otherUnit] = $steps($b);

        // Nothing at all reads last, whichever way the column is sorted.
        $filed = ($b['filing'] === null ? 1 : 0) <=> ($a['filing'] === null ? 1 : 0);

        if ($filed !== 0) {
            return -$filed;
        }

        $byDepartment = $sign * self::compareNames($department, $otherDepartment);
        // And the department's own equipment before the people of that block.
        $byKind = ($a['kind'] === 'person' ? 1 : 0) <=> ($b['kind'] === 'person' ? 1 : 0);
        $byName = self::compareNames($a['name'], $b['name']);
        $byUnitName = $sign * self::compareNames($unit, $otherUnit);

        if ($sort === 'unit') {
            // The column being sorted by: what it has nothing to show for
            // reads last, whichever way it is sorted, as it does in the list.
            $unnamed = ($unit === null ? 1 : 0) <=> ($otherUnit === null ? 1 : 0);

            return $unnamed ?: $byUnitName ?: $byDepartment ?: $byKind ?: $byName;
        }

        // Inside a department, its own rows before its units, as the structure
        // reads down: those are the department's own, and a unit is a step
        // further in. Turned round with the rest when the column is.
        $own = $sign * (($unit === null ? 0 : 1) <=> ($otherUnit === null ? 0 : 1));

        return $byDepartment ?: $own ?: $byUnitName ?: $byKind ?: $byName;
    }

    /**
     * Two names, either of which may be missing.
     *
     * By code point, which for names written in one alphabet is the order they
     * are read in — and Latin before Cyrillic, as the database puts them when
     * it orders the other table. Nothing here has to agree with the database
     * anyway: these rows are put in order in full, here, and never half in
     * each.
     */
    private static function compareNames(?string $a, ?string $b): int
    {
        return strcmp((string) $a, (string) $b) <=> 0;
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
            // Where this table is being read. The page is the same one either
            // way; what changes is the name over it and the trail to it.
            'section' => match (true) {
                $request->routeIs('inventory.overview') => 'inventory',
                $request->routeIs('inventory.furniture') => 'furniture',
                $request->routeIs('inventory.office') => 'office',
                default => 'equipment',
            },
            // Which entry of the menu this page is read under.
            'menu' => self::menuOf(self::part($request)),
            'filters' => $filters,
            'sort' => ['key' => $sort, 'direction' => $direction],
            'perPage' => $perPage,
            'perPageOptions' => self::PER_PAGE_OPTIONS,
            'counts' => $this->counts($request->user()),
            'options' => [
                // The icon travels with the category rather than on every unit
                // under it: by colleague the category is the column, and the
                // list reads the same value off the unit's own row.
                'types' => EquipmentType::query()
                    ->when(self::part($request), fn (Builder $q, string $part) => $q->where('section', $part))
                    ->orderBy('name')
                    ->get(['id', 'name', 'icon']),
                // The structure flattened for the two funnels that narrow both
                // tables to a department or to one unit inside it. The whole
                // tree goes: which department a colleague stands in is read
                // wherever their name is, and asks no right of its own.
                'departments' => $this->departmentOptions(),
                'statuses' => [
                    ...collect(Equipment::STATUSES)->map(fn (string $s) => ['value' => $s, 'label' => self::STATUS_LABELS[$s]])->all(),
                    ['value' => self::SERVICE, 'label' => 'На обслуживании'],
                ],
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
            // Which part of the stock this form is for: the page it was opened
            // from says so, and on three of the four it is settled — the unit
            // joins that part and the categories offered are its own.
            'section' => self::part($request),
            'menu' => self::menuOf(self::part($request)),
            'sections' => EquipmentType::sections(),
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
                // And the departments, for a category whose equipment is a
                // department's own: the form asks for one or for the other
                // according to the category chosen in it.
                'departments' => $this->departmentOptions(),
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
        // ones it asks for by themselves below.
        $type = self::ownFields($request->integer('equipment_type_id'));
        // And those: whichever of the two the category still names its units
        // by. A category may have dropped either, and then the form never asked
        // and the unit goes on the books without it.
        $named = $this->identityFields($type);

        $data = $request->validate([
            'equipment_type_id' => ['required', 'integer', Rule::exists('equipment_types', 'id')],
            'name' => $this->identityRules($type, $named, 'title', 150),
            // The number on the sticker, where the category keeps one: one unit,
            // one number, checked against what every inventory field holds.
            'inventory_number' => [
                ...$this->identityRules($type, $named, 'inventory', 50),
                ...($named->has('inventory') ? [$this->freeNumber()] : []),
            ],

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
            // handed over in the same breath as it is put on the books — to a
            // colleague, or to the department that will answer for it. One or
            // the other, as the handover itself allows.
            'holder_user_id' => ['nullable', 'prohibits:holder_department_id', 'integer', Rule::exists('users', 'id')],
            'holder_department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')],
            // The form's second button: stay here and enter the next unit.
            'another' => ['nullable', 'boolean'],
            // The day it changed hands, asked for whichever kind of hands.
            'issued_at' => ['nullable', 'required_with:holder_user_id,holder_department_id', 'date', 'before_or_equal:today'],
        ], messages: [
            // The form never sends both; this is what the rule says if anything
            // ever does, and it says it the way the form asks the question.
            'holder_user_id.prohibits' => 'У единицы один владелец: либо сотрудник, либо отдел.',
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
            'holder_department_id' => 'отдел',
            'issued_at' => 'дата выдачи',
            ...$this->fieldAttributes($type),
        ]);

        // Whom the unit goes to as it arrives, if anybody. Whose card the form
        // was opened from outranks whatever it posted about the holder: a page
        // opened for somebody hands the unit to them, and then it is nobody's
        // department's. Otherwise the form's own answer stands, which the rules
        // above have already held to one of the two.
        $holder = $employee?->id ?? $data['holder_user_id'] ?? null;
        $department = $employee === null ? ($data['holder_department_id'] ?? null) : null;

        $equipment = Equipment::create([
            ...Arr::except($data, [
                'name', 'inventory_number', 'holder_user_id', 'holder_department_id', 'issued_at', 'photos', 'another', 'fields',
            ]),
            'status' => 'stock',
        ]);

        // What the unit is called and the number on its sticker are fields of
        // its category, which can only be written once the row they hang on
        // exists — so they follow the row rather than going into it. Either may
        // be missing: the category has no field for it, and nothing is written.
        $equipment->writeIdentity($data['name'] ?? null, $data['inventory_number'] ?? null);
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

        if (! $holder && ! $department) {
            return $this->afterCreating($equipment, $data, $employee);
        }

        // Handed over as it arrives. The move is made as a move rather than
        // written into the new row, so the journal shows both the arrival and
        // the handover, in that order.
        $equipment->update([
            'status' => 'issued',
            'holder_user_id' => $holder,
            'holder_department_id' => $department,
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

        // Named for the line the form puts up about what has just been filed,
        // which is a line to read and nothing to fill a box with.
        return $form->with('equipment', [
            'id' => $equipment->id,
            'name' => EquipmentName::read($equipment->name),
            'inventory_number' => EquipmentName::readNumber($equipment->inventory_number),
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
            'holder:id,name,surname,avatar,avatar_original',
            'repairs.photos',
            'events.user:id,name,surname,avatar,avatar_original',
            'events.photos',
        ]);

        $holderDepartment = $equipment->holder?->departments()->orderBy('name')->first();
        // The other kind of holder: a department this unit is signed out to
        // itself, for a category whose things are nobody's in particular.
        $heldBy = $equipment->holderDepartment;

        return Inertia::render('equipment/show', [
            // The parts the stock is read in, for the window that moves a
            // unit from one of them to another by its category.
            'sections' => EquipmentType::sections(),
            // A card belongs to the page its unit is read on, which its
            // category decides rather than its address.
            'menu' => self::menuOf($equipment->type?->section),
            'unit' => [
                'id' => $equipment->id,
                // The one place these two travel as they are rather than as
                // they read: the card's "Характеристики" form opens on what the
                // card was given, and a form that opened on «Без наименования»
                // would save those words as the unit's name. The heading says
                // it in its own words instead, as it says every missing value.
                'name' => $equipment->name,
                'equipment_type_id' => $equipment->equipment_type_id,
                'type' => $equipment->type?->name,
                // Which part of the stock it is of, which is what the trail
                // back from this card leads to: a desk is read under «Мебель»,
                // not under the working equipment.
                'section' => $equipment->type?->section,
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
                    // The upload behind it, which is what pressing the face opens.
                    'avatar_original' => EmployeeFields::showsAvatar($request->user(), $equipment->holder) ? $equipment->holder->avatar_original : null,
                    // Named as every screen but the directory names a department:
                    // by its abbreviation, or by its full name where it has none.
                    'department' => $holderDepartment?->display_name,
                ] : null,
                // Whose it is where no colleague holds it: the department it is
                // signed out to, named and linked as the card names a
                // department. Null for everything held by a person and for
                // everything on the balance sheet.
                'holder_department' => $heldBy === null ? null : [
                    ...self::departmentRef($heldBy),
                    // The path spelled out, since the card has room for it and
                    // «ОА» alone says little on a page about one printer.
                    'path' => $heldBy->path(),
                ],
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
                    // The upload behind it, which is what pressing the face opens.
                    'avatar_original' => EmployeeFields::showsAvatar($request->user(), $event->user) ? $event->user->avatar_original : null,
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
            // Whom else a unit may be handed to: the structure, for the
            // categories whose things a department holds. It is the same list
            // the equipment table narrows by, and it asks no right of its own.
            'departments' => $this->departmentOptions(),
            // What the card's forms offer; only an editor needs any of it.
            'types' => EquipmentAccess::edits($request->user()) ? EquipmentType::formOptions() : [],
            'neighbours' => $this->neighbours($equipment),
            // What the heading calls a unit whose category keeps no name for
            // it. Every other screen is handed the words already in place; the
            // card is handed them apart from the unit, because its own forms
            // open on the unit and would save them as a name. The words come
            // from here all the same, so no screen can drift from the rest.
            'nameless' => EquipmentName::NAMELESS,
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
        // has just struck a unit off is looking at the fleet, not at people —
        // and the fleet unit by unit is «Обзор» of «Инвентаризация».
        return to_route('inventory.overview');
    }

    /**
     * One category with the fields it asks about by field id: its own, without
     * the ones a unit is named by. A form sends those as "name" and
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
            ->tap(fn (Builder $q) => Equipment::putMissingLast($q, 'title', $direction))
            ->orderBy($name(), $direction)
            ->orderBy('id', $direction);

        // A unit of a category that keeps no name has none to be walked by, and
        // walks among the others in the same position instead, in the order they
        // were entered. The two walks never cross: a comparison against a name
        // that is not there is true of nothing, which is exactly what keeps the
        // named units out of this one.
        if ($equipment->name === null) {
            $nameless = fn (Builder $q) => $q->whereDoesntHave(
                'fieldValues',
                fn (Builder $values) => $values->whereRelation('field', 'role', 'title'),
            );

            $before = fn (Builder $q) => $q->tap($nameless)->where('id', '<', $equipment->id);
            $after = fn (Builder $q) => $q->tap($nameless)->where('id', '>', $equipment->id);
        } else {
            // "Before" means earlier in (name, id) order; a tie on the name falls back to the id.
            $before = fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->where($name(), '<', $equipment->name)
                ->orWhere(fn (Builder $q) => $q->where($name(), $equipment->name)->where('id', '<', $equipment->id)));
            $after = fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->where($name(), '>', $equipment->name)
                ->orWhere(fn (Builder $q) => $q->where($name(), $equipment->name)->where('id', '>', $equipment->id)));
        }

        $unit = fn (?Equipment $u) => $u ? [
            'id' => $u->id,
            'name' => EquipmentName::read($u->name),
            'inventory_number' => EquipmentName::readNumber($u->inventory_number),
        ] : null;

        return [
            'prev' => $unit($order('desc')->tap($before)->first(['id', 'equipment_type_id'])),
            'next' => $unit($order('asc')->tap($after)->first(['id', 'equipment_type_id'])),
        ];
    }

    /**
     * A department as every screen but the directory names it: the short form
     * it is known by, with the full name along for the quieter line under it.
     *
     * @return array{id: int, name: string, full_name: string}
     */
    private static function departmentRef(Department $department): array
    {
        return [
            'id' => $department->id,
            'name' => $department->display_name,
            'full_name' => $department->name,
        ];
    }

    /**
     * Where a row is read in the structure: the department of whoever holds it,
     * with the department above that one where there is one. Both travel
     * because the page has no tree to walk — the table reads a row under the
     * department in one column and under the unit inside it in the next.
     *
     * Whoever holds it is a colleague or a department: a printer signed out to
     * the отдел аналитики is read under the отдел аналитики and under ДАС above
     * it, beside the laptops of the people who work there.
     *
     * Null twice over: a unit in nobody's hands has no department at all, and a
     * colleague may stand in none. The page tells the two apart by the holder,
     * and reads them differently: the first is where a unit on the balance
     * sheet belongs, the second is a row somebody still has to file.
     *
     * @return array{id: int, name: string, full_name: string, parent: array{id: int, name: string, full_name: string}|null}|null
     */
    private static function filingOf(User|Department|null $heldBy): ?array
    {
        // A department holds its equipment itself, so there is nothing to work
        // out: it is the department the row is read under. A colleague is read
        // under one of theirs, which is the question below.
        $own = $heldBy instanceof Department ? $heldBy : self::standsIn($heldBy);

        if ($own === null) {
            return null;
        }

        return [
            ...self::departmentRef($own),
            'parent' => $own->parent === null ? null : self::departmentRef($own->parent),
        ];
    }

    /**
     * Which of a colleague's departments a row is read under.
     *
     * Most people stand in one, but a few stand in two — often a department of
     * the company and a unit inside it at once — and a cell covering a run of
     * rows can only name one of them. The most specific wins: a unit says both
     * steps of the path, where the department above it says only the first. Two
     * that are equally specific fall to the name they are shown under, so the
     * answer never depends on the order the rows happened to arrive in.
     *
     * `orderByStructure` picks the same department in the database, by the same
     * two rules. The two have to agree: the cells merge over neighbours that
     * read alike, so a row sorted as ДФКА and printed as ДКП would break the
     * block it was sorted into.
     */
    private static function standsIn(?User $holder): ?Department
    {
        return $holder?->departments
            ->sortBy(fn (Department $department) => $department->display_name)
            ->sortByDesc(fn (Department $department) => $department->parent_id !== null)
            ->first();
    }

    /**
     * The tree flattened for the two funnels, the way the staff list flattens
     * it for its own: the departments of the company in order, each with its
     * units under it, every one of them named as the columns name it.
     *
     * @return list<array{id: int, name: string, full_name: string, depth: int}>
     */
    private function departmentOptions(): array
    {
        $departments = Department::query()->orderBy('name')->get(['id', 'name', 'abbreviation', 'parent_id']);

        return $departments
            ->whereNull('parent_id')
            ->flatMap(fn (Department $department) => [
                [...self::departmentRef($department), 'depth' => 0],
                ...$departments
                    ->where('parent_id', $department->id)
                    ->map(fn (Department $unit) => [...self::departmentRef($unit), 'depth' => 1]),
            ])
            ->values()
            ->all();
    }

    /**
     * One step of where a row's holder stands, as a value the database can put
     * in order — the department named by `self::DEPARTMENT_SHOWN`, the unit by
     * `self::UNIT_SHOWN`.
     *
     * One department per row, and the one the page prints: the most specific of
     * the holder's, then the first by the name it is shown under, which is the
     * rule `standsIn` follows over the departments the page is handed.
     *
     * @param  string  $select  what to read off that department
     * @param  string  $holder  the column naming the holder in the query this hangs on
     */
    private static function filingQuery(string $select, string $holder): QueryBuilder
    {
        return DB::table('department_user')
            ->join('departments', 'departments.id', '=', 'department_user.department_id')
            // The department above it, where the holder's own is a unit inside
            // one; the structure is two deep, so this is the whole of the path.
            ->leftJoin('departments as parents', 'parents.id', '=', 'departments.parent_id')
            ->whereColumn('department_user.user_id', $holder)
            // A unit before a department of the company, and then by name: the
            // two rules that pick one of two departments, in that order.
            ->orderByRaw('departments.parent_id is null')
            ->orderByRaw(self::OWN_SHOWN)
            ->limit(1)
            ->selectRaw($select);
    }

    /**
     * The same step of the structure read off the other kind of holder: the
     * department a unit is signed out to, which is the department itself and
     * asks nothing about anybody's filing.
     *
     * @param  string  $select  what to read off it, the same two expressions
     * @param  string  $column  the column naming that department
     */
    private static function heldFilingQuery(string $select, string $column): QueryBuilder
    {
        return DB::table('departments')
            ->leftJoin('departments as parents', 'parents.id', '=', 'departments.parent_id')
            ->whereColumn('departments.id', $column)
            ->limit(1)
            ->selectRaw($select);
    }

    /**
     * One step of the structure for a row of this query, whichever kind of
     * holder it has: the colleague's filing where a colleague holds it, the
     * department itself where a department does.
     *
     * Written as one value rather than two, because the columns are one
     * question — where is this read? — and the cells merge over rows that
     * answer it alike. A department's own printer and its people's laptops
     * stand in the same block under the same name, which is the whole of what
     * the user asked for and what a second expression would have undone.
     *
     * A table whose rows are the colleagues themselves has no second holder,
     * and is handed none.
     */
    private static function step(string $shown, string $holder, ?string $department): string
    {
        $byColleague = '('.self::filingQuery($shown, $holder)->toSql().')';

        if ($department === null) {
            return $byColleague;
        }

        // Neither is ever anything but null while the other answers: the
        // database refuses a unit held by both at once.
        return 'coalesce('.$byColleague.', ('.self::heldFilingQuery($shown, $department)->toSql().'))';
    }

    /**
     * The structure read down, by either of its two columns: a department's
     * rows together under its name, and inside it the rows of each unit.
     *
     * The order is what makes the merged cells worth having — a cell covers the
     * rows beside it that say the same thing, so a department is one block only
     * while its rows are side by side — and the second step is ordered along
     * with the first for the same reason: ordered by the department alone, its
     * units would interleave and the second column would merge nothing.
     *
     * What a column has nothing to show for reads last, whichever way it is
     * sorted, as a unit with no name of its own already does here. There are
     * two such nothings and they are kept apart: a holder standing in no
     * department is a row somebody has still to file, and a unit in nobody's
     * hands is simply on the balance sheet, which is a third of the fleet and
     * has no business opening a list read by department.
     *
     * @param  Builder<Equipment>|Builder<User>  $query
     * @param  string  $holder  the column naming the colleague who holds a row
     * @param  string|null  $held  the column naming the department that holds it, where a row can have one
     */
    private static function orderByStructure(Builder $query, string $sort, string $direction, string $holder, ?string $held = null): void
    {
        $department = self::step(self::DEPARTMENT_SHOWN, $holder, $held);
        $unit = self::step(self::UNIT_SHOWN, $holder, $held);
        $missing = fn (string $step) => $step.' is null';
        // Held by nobody at all: not by a colleague, and not — where a row can
        // be — by a department either.
        $unheld = $holder.' is null'.($held === null ? '' : ' and '.$held.' is null');
        // Nothing at all reads last, and each kind of nothing one step before
        // the next: the rows that name the level being sorted by, then those
        // filed a step short of it, then the units nobody holds. The holder is
        // asked about first, because a unit in nobody's hands is missing every
        // step of the path at once and would otherwise answer to a later one. A
        // table whose rows are the people themselves has no holder to be
        // without, and that first question is then simply never true.
        $query->orderByRaw($sort === 'department'
            ? 'case when '.$unheld.' then 2 when '.$missing($department).' then 1 else 0 end'
            : 'case when '.$unheld.' then 3 when '.$missing($department).' then 2 when '.$missing($unit).' then 1 else 0 end');

        // Then down the structure itself. By the unit the units stand together
        // and their departments are scattered among them — a department holds
        // units whose names begin anywhere — so the department follows as a tie
        // and in its own order.
        // Raw rather than a subquery handed to `orderBy`, because a step is now
        // a coalesce of two of them. The direction comes off a list of two
        // words the request was checked against.
        $down = fn (string $step, string $way = 'asc') => $query->orderByRaw($step.' '.($way === 'desc' ? 'desc' : 'asc'));

        if ($sort === 'department') {
            $down($department, $direction);
            $down($unit, $direction);
        } else {
            $down($unit, $direction);
            $down($department);
        }
    }

    /**
     * The company read down to a single unit of equipment: the department, the
     * unit inside it, the people in each by name, and what each of them holds.
     *
     * The two steps of the structure are what the merged cells are drawn from;
     * the two after them are what makes the block read as a block. A
     * department's rows gathered but shuffled among its people would put four
     * of one colleague's things in four places, so the holder breaks the tie
     * and the name of the thing breaks theirs — and a unit whose category keeps
     * no name for it reads last among that person's, as it does in every other
     * order here.
     *
     * @param  Builder<Equipment>  $query
     * @param  string  $step  which of the two columns is doing the sorting
     */
    private function orderDownTheStructure(Builder $query, string $step, string $direction): void
    {
        self::orderByStructure($query, $step, $direction, 'equipment.holder_user_id', 'equipment.holder_department_id');

        $named = fn (string $column) => User::select($column)->whereColumn('users.id', 'equipment.holder_user_id');

        // A department's own equipment opens its block, before the people in
        // it: nothing sorts before every name there is, and the printer of the
        // отдел аналитики is the отдел аналитики's own rather than anybody's.
        $query->orderBy($named('surname'))->orderBy($named('name'))->orderByRole('title');
    }

    /**
     * The departments asked for and the units inside them: asking about ДАС
     * asks about its отдел аналитики too, the way the staff list reads the same
     * choice. The structure is two deep, so one read down is the whole of it.
     *
     * @param  list<int>  $ids
     * @return list<int>
     */
    private static function withUnits(array $ids): array
    {
        return [...$ids, ...Department::query()->whereIn('parent_id', $ids)->pluck('id')->all()];
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
        $query->when($filters['status'], function (Builder $q, array $statuses) {
            $plain = array_values(array_diff($statuses, [self::SERVICE]));
            $service = in_array(self::SERVICE, $statuses, true);

            // Asked together, the answers are read together: a unit of one of
            // the statuses picked, or one that is away being looked after.
            $q->where(function (Builder $q) use ($plain, $service) {
                if ($plain !== []) {
                    $q->orWhereIn('status', $plain);
                }

                if ($service) {
                    $q->orWhere(fn (Builder $q) => $q->underService());
                }
            });
        });
        // Where the holder stands. Asked of every department they stand in
        // rather than of the one their row happens to print: "техника отдела"
        // means the equipment of the people in it, which is what the right to
        // see a department's fleet already means, and the few colleagues who
        // stand in two departments hold their units in both.
        // «Без департамента» asks for the rows that column is empty on, and is
        // added to whatever else the funnel holds.
        $query->when($filters['department'], function (Builder $q, array $picked) {
            $none = in_array(self::NO_UNIT, $picked, true);
            $ids = array_values(array_filter($picked, fn ($value) => $value !== self::NO_UNIT));

            $q->where(function (Builder $q) use ($none, $ids) {
                if ($ids !== []) {
                    $q->filedIn(self::withUnits($ids));
                }

                if ($none) {
                    $q->orWhere(fn (Builder $q) => $q->outsideStructure());
                }
            });
        });
        // A unit has nothing under it to read down: the structure is two deep.
        // «Без отдела» asks for the rows whose unit column is empty; the two
        // answers are added together, as two ticks in one funnel always are.
        $query->when($filters['unit'], function (Builder $q, array $picked) {
            $none = in_array(self::NO_UNIT, $picked, true);
            $ids = array_values(array_filter($picked, fn ($value) => $value !== self::NO_UNIT));

            $q->where(function (Builder $q) use ($none, $ids) {
                if ($ids !== []) {
                    $q->filedIn($ids);
                }

                if ($none) {
                    $q->orWhere(fn (Builder $q) => $q->outsideUnits());
                }
            });
        });
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
                ->orWhereHas('holder', fn (Builder $q) => self::whereNamed($q, $words))
                // And by where the holder stands, now that the table says it:
                // by the abbreviation the column prints or by the name under
                // it, and «ДАС» answers for its units too — their rows read
                // under ДАС, so a search for it has to find them.
                ->orWhereHas('holder.departments', fn (Builder $q) => $q
                    ->where(fn (Builder $q) => self::whereDepartment($q, $term))
                    ->orWhereHas('parent', fn (Builder $q) => self::whereDepartment($q, $term)))
                // And the department a unit is signed out to, read the same
                // way: «ДАС» finds the printer of its отдел аналитики as it
                // finds the laptops of the people there.
                ->orWhereHas('holderDepartment', fn (Builder $q) => $q
                    ->where(fn (Builder $q) => self::whereDepartment($q, $term))
                    ->orWhereHas('parent', fn (Builder $q) => self::whereDepartment($q, $term))));
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

    /**
     * A department named either way it is written: by the abbreviation its
     * column prints, or by the full name standing under it.
     *
     * @param  Builder<Department>  $query
     */
    private static function whereDepartment(Builder $query, string $term): void
    {
        $query->where('name', 'like', "%{$term}%")->orWhere('abbreviation', 'like', "%{$term}%");
    }

    private function applySort(Builder $query, string $sort, string $direction): void
    {
        match ($sort) {
            // When the unit was put on the books, not when it was bought or
            // handed over: the id breaks a tie, so two units entered in the
            // same second keep the order they were entered in.
            'created' => $query->orderBy('created_at', $direction)->orderBy('id', $direction),
            // The structure, read down from whoever holds the unit — which is
            // what gathers a department's equipment into one block for the two
            // columns that merge their cells over it.
            //
            // The order the table opens in is the same comparison as its first
            // column's, as it is on the positions page: that column is the
            // structure's first step, and the step after it breaks its ties.
            // The rows come out in the same sequence and the tables do not look
            // alike, because only one of the two has an arrow on a header and
            // something to reset; which one the list is in is read off the key.
            self::STRUCTURE, 'department', 'unit' => $this->orderDownTheStructure(
                $query,
                $sort === self::STRUCTURE ? 'department' : $sort,
                $direction,
            ),
            // The two a unit is named by live in its category's fields, so the
            // order is taken from the value each unit holds there — and a unit
            // whose category keeps no such field holds nothing and reads last,
            // whichever way the column is sorted.
            'name' => $query->orderByRole('title', $direction),
            'inventory_number' => $query->orderByRole('inventory', $direction),
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
