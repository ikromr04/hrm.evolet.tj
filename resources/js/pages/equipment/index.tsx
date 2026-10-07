import {
    clearedFilter,
    countActiveFilters,
    cycleSort,
    DataTable,
    isSorted,
    MobileListTools,
    MobileRow,
    resetView,
    useRememberedQuery,
    useTableView,
    type ColumnDef,
    type Sort,
    type ViewState,
} from '@/components/data-table';
import { CategoryChip } from '@/components/equipment-icon';
import { EquipmentMoveDialog, moveLabel, type AskedMove } from '@/components/equipment-move-dialog';
import { MobileFab } from '@/components/mobile-fab';
import { Pagination, type Paginated } from '@/components/pagination';
import { PersonFace } from '@/components/person-face';
import { PersonLink } from '@/components/person-link';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import AppLayout from '@/layouts/app-layout';
import { type EquipmentRights } from '@/lib/access';
import { formatDate } from '@/lib/employee';
import { statusLabel, statusTone, type EquipmentStatus as Status } from '@/lib/equipment';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import {
    ArrowDownToLine,
    ChevronDown,
    Columns3,
    Ellipsis,
    Eraser,
    History,
    LoaderCircle,
    Plus,
    RotateCcw,
    Search,
    Trash2,
    UserPlus,
    Wrench,
    X,
    type LucideIcon,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

interface Unit {
    id: number;
    name: string;
    /** The first couple of things its category asks about, as it answered them. */
    details: string | null;
    inventory_number: string;
    type: string | null;
    type_icon: string | null;
    status: Status;
    condition: string | null;
    /** Null while nobody holds it. */
    holder: { id: number; name: string; avatar: string | null } | null;
    issued_at: string | null;
    written_off_at: string | null;
    /** A piece of service work on it has not ended yet. */
    in_service: boolean;
}

/** The tab above the table: a status, the units being serviced, or everything. */
type Tab = Status | 'service' | 'all';

/** How much of the fleet is open: one's own, one's department's, all of it. */
type Scope = 'own' | 'department' | 'all';

interface Filters {
    q: string;
    name: string;
    inventory_number: string;
    type: number[];
    status: Status[];
    /** A name typed in, not a pick from a list. */
    holder: string;
    issued_from: string | null;
    issued_to: string | null;
}

interface Options {
    types: { id: number; name: string }[];
    statuses: { value: Status; label: string }[];
    holders: { id: number; name: string }[];
}

interface Props {
    equipment: Paginated<Unit>;
    filters: Filters;
    /** The tab above the table; "all" is "Все", "service" is not a status. */
    tab: Tab;
    sort: Sort;
    sortable: string[];
    perPage: number;
    perPageOptions: number[];
    counts: Record<'all' | 'service' | Status, number>;
    options: Options;
    /** Which blocks and moves are open to the viewer; the server decided each one. */
    can: EquipmentRights;
    /** Which parts of the fleet the list was narrowed to; the server did the narrowing. */
    scopes: Scope[];
    /** The parts of those whose journal may be read; empty means no journal at all. */
    journalScopes: Scope[];
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Оборудование', href: '/equipment' }];

const STORAGE_KEY = 'equipment.table.view.v2';
const DEFAULT_SORT: Sort = { key: 'holder', direction: 'asc' };

/** Red, for the one action that cannot be undone. */
const dangerItem = 'text-[#B42318] focus:text-[#B42318] dark:text-[#F7A19A] [&_svg]:text-current!';

/**
 * The "⋯" at the end of a row. A written-off unit has nowhere left to go: the
 * only thing left to do with it is strike it off the books for good.
 *
 * Every item is a right of its own, so a storekeeper who hands units out and an
 * accountant who writes them off are offered different menus — and a viewer left
 * with no item at all is offered no menu, rather than one that opens on nothing.
 */
function RowActions({
    unit,
    can,
    onAsk,
    onDelete,
}: {
    unit: Unit;
    can: EquipmentRights;
    onAsk: (move: { unit: Unit; kind: AskedMove }) => void;
    onDelete: (unit: Unit) => void;
}) {
    const moves: { kind: AskedMove; icon: LucideIcon }[] = [];

    // A unit that has been written off has nowhere left to move: the only thing
    // to do with the record is to strike it out.
    if (unit.status !== 'written_off') {
        if (unit.status === 'issued') {
            if (can.take) moves.push({ kind: 'take', icon: ArrowDownToLine });
        } else if (can.issue) {
            moves.push({ kind: 'issue', icon: UserPlus });
        }

        if (can.write_off) moves.push({ kind: 'write-off', icon: Trash2 });
    }

    const removable = unit.status === 'written_off' && can.delete;

    if (moves.length === 0 && !removable) {
        return null;
    }

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    variant="ghost"
                    size="icon"
                    className="text-muted-foreground size-10 md:size-9 lg:size-8"
                    aria-label={`Действия: ${unit.name}`}
                >
                    <Ellipsis className="size-5!" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-56">
                {moves.map(({ kind, icon: Icon }) => (
                    <DropdownMenuItem key={kind} onSelect={() => onAsk({ unit, kind })} className={cn(kind === 'write-off' && dangerItem)}>
                        <Icon />
                        {moveLabel[kind]}
                    </DropdownMenuItem>
                ))}

                {removable && (
                    <DropdownMenuItem onSelect={() => onDelete(unit)} className={dangerItem}>
                        <Eraser />
                        Удалить запись…
                    </DropdownMenuItem>
                )}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

/**
 * Striking a unit off the books for good — a duplicate, or something entered
 * by mistake. Everything filed under it goes too, so the dialog says as much
 * before it asks.
 */
function DeleteDialog({ unit, onClose }: { unit: Unit; onClose: () => void }) {
    const [busy, setBusy] = useState(false);

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Удалить запись?</DialogTitle>
                    <DialogDescription>
                        {unit.name} · инв. № {unit.inventory_number}
                    </DialogDescription>
                </DialogHeader>

                <p className="text-sm">
                    Вместе с единицей исчезнут её история передач, ремонты, документы и журнал. Отменить это нельзя. Если техника просто отслужила
                    своё — её нужно <span className="font-medium">списать</span>, а не удалять.
                </p>

                <DialogFooter className="gap-2">
                    <Button type="button" variant="outline" onClick={onClose}>
                        Отмена
                    </Button>
                    <Button
                        type="button"
                        variant="destructive"
                        disabled={busy}
                        onClick={() => {
                            setBusy(true);
                            router.delete(route('equipment.destroy', unit.id), { onFinish: onClose });
                        }}
                    >
                        {busy && <LoaderCircle className="animate-spin" />}
                        Удалить
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

/** Who has it; on the balance sheet, nobody. */
function Holder({ unit }: { unit: Unit }) {
    if (unit.holder) {
        return (
            <PersonLink
                id={unit.holder.id}
                title={`Открыть профиль: ${unit.holder.name}`}
                className="text-brand-strong flex items-center gap-2 hover:underline dark:text-[#C5E27A]"
            >
                <PersonFace id={unit.holder.id} name={unit.holder.name} avatar={unit.holder.avatar} className="size-7 text-[11px]" />
                <span className="truncate">{unit.holder.name}</span>
            </PersonLink>
        );
    }

    return <span className="text-muted-foreground">—</span>;
}

/** The columns, each with the filter that narrows it. */
function buildColumns(options: Options): ColumnDef[] {
    return [
        {
            key: 'holder',
            label: 'У кого',
            width: 250,
            filter: { type: 'text', param: 'holder', placeholder: 'Фамилия сотрудника' },
        },
        {
            key: 'name',
            label: 'Наименование',
            width: 380,
            filter: { type: 'text', param: 'name', placeholder: 'Название модели' },
        },
        {
            key: 'inventory_number',
            label: 'Инв. номер',
            width: 160,
            filter: { type: 'text', param: 'inventory_number', placeholder: 'EV-0421' },
        },
        {
            key: 'type',
            label: 'Категория',
            width: 180,
            filter: { type: 'multi', param: 'type', options: options.types.map((t) => ({ value: t.id, label: t.name })) },
        },
        {
            key: 'status',
            label: 'Статус',
            width: 160,
            filter: { type: 'multi', param: 'status', options: options.statuses.map((s) => ({ value: s.value, label: s.label })) },
        },
        {
            key: 'issued_at',
            label: 'Выдано',
            width: 170,
            filter: { type: 'dates', from: 'issued_from', to: 'issued_to' },
        },
    ];
}

const defaultView = (): ViewState => ({ hidden: [], pinned: { left: ['holder'], right: [] } });

/**
 * A short line above the table for a viewer who is not shown the whole fleet,
 * so that a list of three units — or of none — reads as the part of it they
 * were given rather than as something gone wrong.
 */
function narrowedTo(scopes: Scope[]): string | null {
    if (scopes.includes('all')) return null;

    const own = scopes.includes('own');
    const department = scopes.includes('department');

    if (own && department) return 'Здесь только ваша техника и техника вашего отдела — не весь фонд компании.';
    if (department) return 'Здесь только техника вашего отдела — не весь фонд компании.';
    if (own) return 'Здесь только техника, которая числится за вами, — не весь фонд компании.';

    return null;
}

export default function EquipmentIndex({
    equipment,
    filters,
    tab,
    sort,
    sortable,
    perPage,
    perPageOptions,
    counts,
    options,
    can,
    scopes,
    journalScopes,
}: Props) {
    // The journal is open where at least one part of the fleet carries it.
    const canJournal = journalScopes.length > 0;
    // Whether there is anything at all to do with a row. Which item a row offers
    // depends on where its unit is, so the column stays wherever one of them
    // could come up, and goes for a viewer who only reads the list.
    const actsOnRows = can.issue || can.take || can.write_off || can.delete;
    const notice = narrowedTo(scopes);
    const columns = useMemo(() => buildColumns(options), [options]);
    const defaults = useMemo(defaultView, []);
    const { view, setView, pin, toggleHidden } = useTableView(
        STORAGE_KEY,
        columns.map((column) => column.key),
        defaults,
    );
    const isHidden = (key: string) => view.hidden.includes(key);

    useRememberedQuery('equipment.table.query');
    const [query, setQuery] = useState(filters.q);
    const [asking, setAsking] = useState<{ unit: Unit; kind: AskedMove } | null>(null);
    const [deleting, setDeleting] = useState<Unit | null>(null);

    /** Everything the list is looking at, as one query string. */
    const visit = (next: { filters?: Partial<Filters>; sort?: Sort; perPage?: number; tab?: Tab }) => {
        const merged = { ...filters, ...next.filters };
        const nextSort = next.sort ?? sort;
        const nextPer = next.perPage ?? perPage;
        const nextTab = next.tab === undefined ? tab : next.tab;

        type Value = string | number | (string | number)[] | null;
        const params: Record<string, Value> = {
            ...merged,
            tab: nextTab,
            sort: nextSort.key === DEFAULT_SORT.key ? null : nextSort.key,
            direction: nextSort.direction === DEFAULT_SORT.direction ? null : nextSort.direction,
            per_page: nextPer === perPageOptions[0] ? null : nextPer,
        };

        // An empty filter is left out rather than sent as a blank.
        const query = Object.fromEntries(
            Object.entries(params).filter(([, value]) => (Array.isArray(value) ? value.length > 0 : value !== null && value !== '')),
        ) as Record<string, Exclude<Value, null>>;

        router.get(route('equipment.index'), query, { preserveState: true, preserveScroll: true, replace: true });
    };

    // Typing searches on its own, once the typing stops.
    useEffect(() => setQuery(filters.q), [filters.q]);
    useEffect(() => {
        if (query === filters.q) return;
        const timer = setTimeout(() => visit({ filters: { q: query } }), 300);

        return () => clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [query]);

    const tabs: { key: Tab; label: string; count: number }[] = [
        { key: 'all', label: 'Все', count: counts.all },
        ...(['issued', 'stock'] as Status[]).map((key) => ({ key, label: statusLabel[key], count: counts[key] })),
        // Not a status: a unit can be with its owner and on service at once.
        { key: 'service', label: 'На обслуживании', count: counts.service },
        { key: 'written_off', label: statusLabel.written_off, count: counts.written_off },
    ];

    const activeFilters = countActiveFilters(columns, filters as unknown as Record<string, unknown>, () => true);
    // A sorting of the viewer's own counts as one more change to reset.
    const changed = activeFilters + (isSorted(sort, DEFAULT_SORT) ? 1 : 0);
    const resetAll = () =>
        visit({
            filters: columns.reduce<Partial<Filters>>((acc, column) => ({ ...acc, ...clearedFilter(column.filter!) }), {}),
            sort: DEFAULT_SORT,
        });

    const cell = (column: ColumnDef, unit: Unit) => {
        switch (column.key) {
            case 'name':
                return (
                    <Link href={route('equipment.show', unit.id)} className="group flex items-center gap-3" title={`Открыть: ${unit.name}`}>
                        <CategoryChip icon={unit.type_icon} />
                        <div className="flex min-w-0 flex-col gap-0.5">
                            {/* Brand colour and an underline on hover: the app's mark of a link. */}
                            <span className="text-brand-strong truncate font-medium group-hover:underline dark:text-[#C5E27A]">{unit.name}</span>
                            <span className="text-muted-foreground truncate text-[13px]">{unit.details}</span>
                        </div>
                    </Link>
                );
            case 'inventory_number':
                return <span className="tabular-nums">{unit.inventory_number}</span>;
            case 'type':
                return <span className="text-muted-foreground">{unit.type ?? '—'}</span>;
            case 'status':
                return (
                    <span className="flex items-center gap-1.5">
                        <StatusBadge tone={statusTone[unit.status]}>{statusLabel[unit.status]}</StatusBadge>
                        {unit.in_service && <Wrench className="text-muted-foreground size-4 shrink-0" aria-label="На обслуживании" />}
                    </span>
                );
            case 'holder':
                return <Holder unit={unit} />;
            default:
                return (
                    <span className="text-muted-foreground tabular-nums">
                        {unit.status === 'written_off' ? `Списан ${formatDate(unit.written_off_at)}` : (formatDate(unit.issued_at) ?? '—')}
                    </span>
                );
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs} fitViewport>
            <Head title="Оборудование" />

            <div className="flex flex-1 flex-col gap-4 p-3 max-md:gap-3 md:min-h-0 md:px-5 md:py-4">
                {/* The phone's top bar already carries the page's name. */}
                <h1 className="text-xl font-semibold tracking-tight max-md:sr-only">Оборудование</h1>

                {/* Search, the status lists, the view and the one thing you can add: one line.
                    On a phone: the search and the sorting-and-filters button, then the tabs
                    as a row of chips; the rest is reached from the tab bar or the round button. */}
                <div className="flex flex-wrap items-center gap-2 md:-mb-2">
                    {/* text-base on a phone: a smaller font makes iOS zoom in on focus. */}
                    <label className="border-input bg-background text-muted-foreground focus-within:ring-ring max-md:bg-card flex h-10 min-w-48 flex-1 items-center gap-2 rounded-md border px-3 shadow-xs focus-within:ring-2 max-md:min-w-0 max-md:rounded-xl max-md:border-transparent max-md:shadow-none lg:h-8">
                        <Search className="size-4 shrink-0" />
                        <span className="sr-only">Поиск по всем полям</span>
                        <input
                            type="search"
                            value={query}
                            onChange={(event) => setQuery(event.target.value)}
                            placeholder="Поиск по всем полям"
                            className="text-foreground min-w-0 flex-1 bg-transparent text-sm outline-hidden max-md:text-base"
                        />
                    </label>

                    <MobileListTools
                        columns={columns}
                        filters={filters as unknown as Record<string, unknown>}
                        onFilter={(changes) => visit({ filters: changes as Partial<Filters> })}
                        sort={sort}
                        sortable={sortable}
                        onSort={(key, direction) => visit({ sort: direction ? { key, direction } : cycleSort(sort, key, DEFAULT_SORT) })}
                        defaultSort={DEFAULT_SORT}
                        onReset={resetAll}
                        className="max-md:bg-card max-md:rounded-xl max-md:border-transparent max-md:shadow-none"
                    />

                    {/* A phone scrolls the tabs sideways rather than stacking them in rows,
                        as chips that run to the screen's edges and show no scrollbar. The
                        desktop look is kept under md: so neither size overrides the other. */}
                    <nav
                        aria-label="Статус оборудования"
                        className="flex w-full items-center gap-1 overflow-x-auto text-sm max-md:-mx-3 max-md:w-[calc(100%+1.5rem)] max-md:gap-1.5 max-md:px-3 max-md:[scrollbar-width:none] lg:w-auto lg:flex-wrap lg:overflow-visible max-md:[&::-webkit-scrollbar]:hidden"
                    >
                        {tabs.map((item) => {
                            const active = tab === item.key;

                            return (
                                <button
                                    key={item.key}
                                    type="button"
                                    onClick={() => visit({ tab: item.key })}
                                    aria-current={active ? 'page' : undefined}
                                    className={cn(
                                        'flex shrink-0 items-center gap-1.5 whitespace-nowrap transition-colors max-md:h-8 max-md:rounded-full max-md:px-3 md:h-9 md:rounded-md md:px-2.5 lg:h-8',
                                        active
                                            ? 'max-md:bg-brand-soft max-md:text-foreground md:bg-brand-soft md:text-foreground font-semibold max-md:font-medium max-md:font-semibold max-md:dark:bg-white/10 md:dark:bg-white/10'
                                            : 'max-md:bg-card max-md:text-foreground md:text-muted-foreground md:hover:bg-accent md:hover:text-foreground',
                                    )}
                                >
                                    {item.label}
                                    <span
                                        className={cn(
                                            'text-xs font-semibold tabular-nums',
                                            active ? 'max-md:text-muted-foreground md:text-muted-foreground' : 'text-muted-foreground',
                                        )}
                                    >
                                        {item.count}
                                    </span>
                                </button>
                            );
                        })}
                    </nav>

                    {changed > 0 && (
                        <Button
                            variant="ghost"
                            // On a phone the filters are cleared in their own sheet.
                            className="h-10 max-md:hidden lg:h-8"
                            onClick={resetAll}
                        >
                            <X />
                            Сбросить фильтры ({changed})
                        </Button>
                    )}

                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button variant="outline" className="h-10 font-normal max-md:hidden lg:h-8">
                                <Columns3 />
                                Колонки
                                <ChevronDown className="text-muted-foreground" />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" className="max-h-96 w-60 overflow-y-auto">
                            <DropdownMenuLabel>Показывать колонки</DropdownMenuLabel>
                            {columns.map((column) => (
                                <DropdownMenuCheckboxItem
                                    key={column.key}
                                    checked={!isHidden(column.key)}
                                    disabled={column.key === 'name'}
                                    onCheckedChange={(checked) => toggleHidden(column.key, !checked)}
                                    onSelect={(event) => event.preventDefault()}
                                >
                                    {column.label}
                                </DropdownMenuCheckboxItem>
                            ))}
                            <DropdownMenuSeparator />
                            <DropdownMenuItem
                                onSelect={() => {
                                    resetView(STORAGE_KEY);
                                    setView(defaults);
                                }}
                            >
                                <RotateCcw />
                                Сбросить вид
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>

                    {(canJournal || can.create) && (
                        <>
                            {canJournal && (
                                <Button variant="outline" className="h-10 max-md:hidden lg:h-8" asChild>
                                    <Link href={route('equipment.journal')}>
                                        <History />
                                        Журнал
                                    </Link>
                                </Button>
                            )}

                            {can.create && (
                                <Button className="h-10 max-md:hidden lg:h-8" asChild>
                                    <Link href={route('equipment.create')}>
                                        <Plus />
                                        Добавить оборудование
                                    </Link>
                                </Button>
                            )}
                        </>
                    )}
                </div>

                {/* Said once, above the table, rather than repeated on every empty tab. */}
                {notice && <p className="text-muted-foreground text-[13px]">{notice}</p>}

                <DataTable
                    columns={columns}
                    rows={equipment.data}
                    rowKey={(unit) => unit.id}
                    renderCell={cell}
                    sort={sort}
                    sortable={sortable}
                    onSort={(key, direction) => visit({ sort: direction ? { key, direction } : cycleSort(sort, key, DEFAULT_SORT) })}
                    filters={filters as unknown as Record<string, unknown>}
                    onFilter={(changes) => visit({ filters: changes as Partial<Filters> })}
                    view={view}
                    onPin={pin}
                    onHide={(key) => toggleHidden(key, true)}
                    lockedKey="holder"
                    actions={actsOnRows ? (unit) => <RowActions unit={unit} can={can} onAsk={setAsking} onDelete={setDeleting} /> : undefined}
                    empty="Ничего не найдено."
                    mobileRow={(unit) => (
                        <MobileRow
                            href={route('equipment.show', unit.id)}
                            leading={<CategoryChip icon={unit.type_icon} size={40} iconSize={20} className="rounded-xl" />}
                            title={unit.name}
                            // Who has it says more than the status the badge already
                            // shows; a unit nobody holds is placed by its category.
                            subtitle={
                                <span className="tabular-nums">
                                    Инв. № {unit.inventory_number} · {unit.holder?.name ?? unit.type ?? statusLabel[unit.status]}
                                </span>
                            }
                            // Under a status tab every row has that status, and the badge
                            // would only take the width the name needs; it shows under «Все».
                            meta={
                                (tab === 'all' || unit.in_service) && (
                                    <span className="flex items-center gap-1">
                                        {unit.in_service && <Wrench className="size-3.5" aria-label="На обслуживании" />}
                                        {tab === 'all' && (
                                            <StatusBadge tone={statusTone[unit.status]} className="h-5 px-1.5 text-[11px]">
                                                {statusLabel[unit.status]}
                                            </StatusBadge>
                                        )}
                                    </span>
                                )
                            }
                            trailing={actsOnRows ? <RowActions unit={unit} can={can} onAsk={setAsking} onDelete={setDeleting} /> : undefined}
                        />
                    )}
                    footer={
                        <>
                            <div className="text-muted-foreground flex items-center gap-2 text-sm max-md:hidden">
                                Строк на странице
                                <DropdownMenu>
                                    <DropdownMenuTrigger asChild>
                                        <Button variant="outline" size="sm" className="h-8 gap-1 px-2.5 tabular-nums">
                                            {perPage}
                                            <ChevronDown className="text-muted-foreground" />
                                        </Button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent align="start" className="min-w-20">
                                        <DropdownMenuRadioGroup value={String(perPage)} onValueChange={(value) => visit({ perPage: Number(value) })}>
                                            {perPageOptions.map((option) => (
                                                <DropdownMenuRadioItem key={option} value={String(option)} className="tabular-nums">
                                                    {option}
                                                </DropdownMenuRadioItem>
                                            ))}
                                        </DropdownMenuRadioGroup>
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            </div>
                            <Pagination paginator={equipment} className="min-w-0 flex-1" />
                        </>
                    }
                />
            </div>

            {can.create && <MobileFab href={route('equipment.create')} label="Добавить оборудование" />}
            {asking && <EquipmentMoveDialog unit={asking.unit} kind={asking.kind} holders={options.holders} onClose={() => setAsking(null)} />}
            {deleting && <DeleteDialog unit={deleting} onClose={() => setDeleting(null)} />}
        </AppLayout>
    );
}
