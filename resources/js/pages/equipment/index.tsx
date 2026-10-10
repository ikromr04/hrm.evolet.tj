import {
    clearedFilter,
    countActiveFilters,
    cycleSort,
    DataTable,
    MobileListTools,
    MobileRow,
    resetView,
    useDebouncedSearch,
    useRememberedQuery,
    useTableView,
    type ColumnDef,
    type Sort,
    type ViewState,
} from '@/components/data-table';
import { CategoryChip } from '@/components/equipment-icon';
import { EquipmentMoveDialog, moveLabel, type AskedMove } from '@/components/equipment-move-dialog';
import { MobileFab } from '@/components/mobile-fab';
import { MultiSelect } from '@/components/multi-select';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
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
    List,
    ListFilter,
    LoaderCircle,
    Plus,
    RotateCcw,
    Search,
    Trash2,
    UserPlus,
    Users,
    Wrench,
    X,
    type LucideIcon,
} from 'lucide-react';
import { useMemo, useState } from 'react';

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

/**
 * One line of what a colleague holds: enough to name a unit and to open it.
 * Neither its status nor the day it was handed over belongs here — this view is
 * read to see what somebody has, not what state it is in.
 */
interface Held {
    id: number;
    name: string;
    inventory_number: string;
}

/** A colleague and everything that stands against their name, by category. */
interface HolderRow {
    id: number;
    /** "Фамилия Имя" */
    name: string;
    avatar: string | null;
    /** What they hold, under the id of the category it belongs to. */
    units: Record<number, Held[]>;
}

/** Which of the two tables is being read: the fleet unit by unit, or by who holds it. */
type View = 'list' | 'holders';

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
    /** The categories, with the icon each was given in the directory. */
    types: { id: number; name: string; icon: string | null }[];
    statuses: { value: Status; label: string }[];
    holders: { id: number; name: string }[];
}

interface Props {
    /**
     * Which table the page is showing. It opens on «По сотрудникам» — who has
     * what is the question the page is opened with — and the list is asked for
     * by name, which is also how a link that carries a status or a date opens.
     */
    view: View;
    /** The units themselves — sent for that view and for no other. */
    equipment?: Paginated<Unit>;
    /** The colleagues, a page of them, each with what they hold; likewise. */
    holders?: Paginated<HolderRow>;
    filters: Filters;
    /**
     * The tab above the table; "all" is "Все", "service" is not a status. The
     * tabs belong to the list, and «По сотрудникам» is sent no tab at all.
     */
    tab?: Tab;
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
/** The other table's columns are the categories, so its own vista is kept apart. */
const HOLDERS_STORAGE_KEY = 'equipment.holders.view.v1';

/**
 * A stand-in for the order a table opens in — the newest unit in the list, the
 * latest handover in «По сотрудникам».
 *
 * Neither reads a column of the table, and the server names them itself: it
 * sends the order it opened in and answers a query with no sorting in it the
 * same way. So the page never has to know what either is called. It only needs
 * something to hang the phone's "as it opens" choice on while a column is doing
 * the sorting, and this key goes nowhere near the server.
 */
const OPENING_SORT: Sort = { key: 'opening', direction: 'desc' };

/** How many units of one category a row names before counting off the rest. */
const HELD_SHOWN = 3;

/**
 * One of the two choices of «Вид». The one in force takes the same pale green a
 * chosen tab takes beside it. On a phone the pair shares the width of the
 * screen between them, so the words are set a little smaller and wrap rather
 * than being cut short.
 */
const viewChoice =
    'data-[state=on]:bg-brand-soft flex-1 gap-1.5 px-3 data-[state=on]:font-semibold max-md:px-2 max-md:text-[13px] lg:h-8 lg:flex-none dark:data-[state=on]:bg-white/10';

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

/** Both tables open with whoever holds the thing at the left edge. */
const defaultView = (): ViewState => ({ hidden: [], pinned: { left: ['holder'], right: [] } });

/** A category's column, named after the category it stands for. */
const typeKey = (id: number) => `type-${id}`;

/**
 * «По сотрудникам»: the colleague, then a column per category of equipment.
 *
 * The categories come in the order the toolbar already offers them in, and only
 * those asked for come at all — somebody who filtered the view down to monitors
 * is not asking to scroll past eleven empty columns to see them.
 */
function buildHolderColumns(options: Options, chosen: number[]): ColumnDef[] {
    const types = chosen.length > 0 ? options.types.filter((type) => chosen.includes(type.id)) : options.types;

    return [
        {
            key: 'holder',
            label: 'Сотрудник',
            width: 260,
            filter: { type: 'text', param: 'holder', placeholder: 'Фамилия сотрудника' },
        },
        // Wide enough for the category's chip, a model name on two lines and the
        // number under it; a dozen categories make a wide table, and it scrolls.
        ...types.map((type) => ({ key: typeKey(type.id), label: type.name, width: 240, typeId: type.id, icon: type.icon })),
    ];
}

/**
 * What still narrows «По сотрудникам». Three of these ask about equipment rather
 * than about any column of that table, so they are columns only in the sense the
 * filters need — something with a label to show them under, in the toolbar and in
 * the phone's sheet. Nothing lays them out, which is why the width is nothing.
 */
function buildHolderFilters(options: Options): ColumnDef[] {
    return [
        { key: 'name', label: 'Наименование', width: 0, filter: { type: 'text', param: 'name', placeholder: 'Название модели' } },
        {
            key: 'inventory_number',
            label: 'Инвентарный номер',
            width: 0,
            filter: { type: 'text', param: 'inventory_number', placeholder: 'EV-0421' },
        },
        {
            key: 'type',
            label: 'Категория',
            width: 0,
            filter: { type: 'multi', param: 'type', options: options.types.map((t) => ({ value: t.id, label: t.name })) },
        },
    ];
}

/**
 * The chip that counts off what a cell left out, and shows it where it stands.
 *
 * Pressing the count opens the rest in place: somebody who wants to know which
 * six monitors a colleague has is not asking to leave the table they are reading.
 */
function MoreChip({ hidden, open, onToggle }: { hidden: number; open: boolean; onToggle: () => void }) {
    return (
        <button
            type="button"
            onClick={onToggle}
            aria-expanded={open}
            // "+3" says nothing when it is read out rather than seen.
            aria-label={open ? 'Свернуть' : `Показать ещё ${hidden}`}
            title={open ? 'Свернуть' : 'Показать остальные'}
            // The names above it fill the cell so that they wrap; the chip takes
            // only the width of its count.
            className="self-start"
        >
            <StatusBadge className="text-muted-foreground hover:bg-accent">{open ? 'Свернуть' : `+${hidden}`}</StatusBadge>
        </button>
    );
}

/**
 * What a colleague has of one category, each unit a way to its card.
 *
 * A row has to survive somebody with six monitors, so it names a few and counts
 * the rest off behind a chip, the way the duties of a position are capped. An
 * empty cell is left empty: a dash in every other square of a wide table reads
 * as noise, and the eye is looking for what is there.
 */
function HeldUnits({ units, icon }: { units: Held[]; icon: string | null }) {
    const [open, setOpen] = useState(false);

    if (units.length === 0) return null;

    const shown = open ? units : units.slice(0, HELD_SHOWN);
    const hidden = units.length - shown.length;

    return (
        <div className="flex flex-col gap-2 whitespace-normal">
            {shown.map((unit) => (
                <Link
                    key={unit.id}
                    href={route('equipment.show', unit.id)}
                    className="group flex min-w-0 items-start gap-2"
                    title={`Открыть: ${unit.name}`}
                >
                    {/* The category's own icon, as the unit list draws it beside a name;
                        a category nobody chose one for falls back to the common mark. */}
                    <CategoryChip icon={icon} size={28} iconSize={14} className="mt-px" />
                    <span className="flex min-w-0 flex-col">
                        {/* Brand colour and an underline on hover: the app's mark of a link. */}
                        <span className="text-brand-strong leading-tight font-medium break-words group-hover:underline dark:text-[#C5E27A]">
                            {unit.name}
                        </span>
                        <span className="text-muted-foreground text-[13px] leading-tight tabular-nums">инв. № {unit.inventory_number}</span>
                    </span>
                </Link>
            ))}
            {(hidden > 0 || open) && <MoreChip hidden={hidden} open={open} onToggle={() => setOpen(!open)} />}
        </div>
    );
}

/** The colleague a row is about, drawn as the list's own holder column draws one. */
function HolderName({ row }: { row: HolderRow }) {
    return (
        <PersonLink
            id={row.id}
            title={`Открыть профиль: ${row.name}`}
            className="text-brand-strong flex items-center gap-2 hover:underline dark:text-[#C5E27A]"
        >
            <PersonFace id={row.id} name={row.name} avatar={row.avatar} className="size-7 text-[11px]" />
            <span className="truncate">{row.name}</span>
        </PersonLink>
    );
}

/**
 * The filters «По сотрудникам» has no header to hang on.
 *
 * In that table the only column of its own is the person; what a cell may
 * contain is a question about equipment, so the three questions about it gather
 * in one place in the toolbar instead — the same three the phone's sheet asks,
 * and the count on the button says how many are in force, as the sheet's does.
 */
function HolderFilters({
    columns,
    types,
    filters,
    onApply,
}: {
    /** The three themselves, so the button counts exactly what it offers. */
    columns: ColumnDef[];
    types: Options['types'];
    filters: Filters;
    onApply: (changes: Partial<Filters>) => void;
}) {
    const [open, setOpen] = useState(false);
    const [name, setName] = useState(filters.name);
    const [number, setNumber] = useState(filters.inventory_number);
    const active = countActiveFilters(columns, filters as unknown as Record<string, unknown>, () => true);

    return (
        <Popover
            open={open}
            onOpenChange={(next) => {
                // Reopened after a reset — or after the same filter was set from the
                // phone's sheet — the boxes show what is actually in force.
                if (next) {
                    setName(filters.name);
                    setNumber(filters.inventory_number);
                }
                setOpen(next);
            }}
        >
            <PopoverTrigger asChild>
                <Button variant="outline" className="h-10 font-normal max-md:hidden lg:h-8">
                    <ListFilter />
                    Фильтры
                    {active > 0 && <span className="text-brand-strong font-semibold tabular-nums dark:text-[#C5E27A]">{active}</span>}
                    <ChevronDown className="text-muted-foreground" />
                </Button>
            </PopoverTrigger>
            <PopoverContent align="end" className="w-80 max-w-[calc(100vw-2rem)] p-3">
                <form
                    className="flex flex-col gap-3"
                    onSubmit={(event) => {
                        event.preventDefault();
                        onApply({ name: name.trim(), inventory_number: number.trim() });
                        setOpen(false);
                    }}
                >
                    <Label className="flex flex-col items-start gap-1.5 text-xs">
                        Наименование
                        <Input value={name} onChange={(event) => setName(event.target.value)} placeholder="Название модели" className="h-9" />
                    </Label>

                    <Label className="flex flex-col items-start gap-1.5 text-xs">
                        Инвентарный номер
                        <Input value={number} onChange={(event) => setNumber(event.target.value)} placeholder="EV-0421" className="h-9" />
                    </Label>

                    <div className="flex flex-col gap-1.5">
                        {/* The categories picked are also the columns the table draws, so
                            this choice is applied as it is made rather than on the button. */}
                        <span className="text-xs font-medium">Категория</span>
                        <MultiSelect
                            options={types.map((type) => ({ value: type.id, label: type.name }))}
                            value={filters.type}
                            onChange={(type) => onApply({ type })}
                            placeholder="Все категории"
                            searchPlaceholder="Поиск категории"
                        />
                    </div>

                    <Button type="submit" size="sm">
                        Применить
                    </Button>
                </form>
            </PopoverContent>
        </Popover>
    );
}

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
    view,
    equipment,
    holders,
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
    const byHolder = view === 'holders';
    // The journal is open where at least one part of the fleet carries it.
    const canJournal = journalScopes.length > 0;
    // Whether there is anything at all to do with a row. Which item a row offers
    // depends on where its unit is, so the column stays wherever one of them
    // could come up, and goes for a viewer who only reads the list. Nothing is
    // done to a unit from «По сотрудникам»: it is a table for reading.
    const actsOnRows = !byHolder && (can.issue || can.take || can.write_off || can.delete);
    const notice = narrowedTo(scopes);
    const unitColumns = useMemo(() => buildColumns(options), [options]);
    const holderColumns = useMemo(() => buildHolderColumns(options, filters.type), [options, filters.type]);
    const holderFilters = useMemo(() => buildHolderFilters(options), [options]);
    const columns = byHolder ? holderColumns : unitColumns;
    const defaults = useMemo(defaultView, []);

    // Each table keeps its own vista, and each keeps it under its own key: a
    // column hidden among the categories means nothing to the list of units.
    // Both are loaded either way, because a switch of view does not remount the
    // page and a hook asked for conditionally would be the wrong one afterwards.
    const unitView = useTableView(
        STORAGE_KEY,
        unitColumns.map((column) => column.key),
        defaults,
    );
    const holderView = useTableView(
        HOLDERS_STORAGE_KEY,
        // Every category, not only the columns drawn right now: a category left
        // out by a filter has not been hidden, and should come back as it was.
        ['holder', ...options.types.map((type) => typeKey(type.id))],
        defaults,
    );
    const { view: layout, setView: setLayout, pin, toggleHidden } = byHolder ? holderView : unitView;
    const isHidden = (key: string) => layout.hidden.includes(key);
    const storageKey = byHolder ? HOLDERS_STORAGE_KEY : STORAGE_KEY;

    useRememberedQuery('equipment.table.query');
    const [asking, setAsking] = useState<{ unit: Unit; kind: AskedMove } | null>(null);
    const [deleting, setDeleting] = useState<Unit | null>(null);

    /**
     * Whether a column is doing the sorting, rather than the order the table
     * opens in. That order names no column — the moment a unit was added, the
     * moment it last changed hands — so this is the whole difference, and the
     * page can tell the two apart without knowing what the server calls either.
     */
    const sortedByColumn = columns.some((column) => column.key === sort.key);
    /**
     * The order the table opens in, for the one place that has to name it: the
     * phone's sheet, where it is a choice beside the columns. While the table is
     * in it, it is whatever the server sent; once a column took over the sorting
     * the server stopped saying, and the stand-in holds the place until the
     * choice is made, which asks for that order back without naming it.
     */
    const openingSort = sortedByColumn ? OPENING_SORT : sort;

    /** Everything the table is looking at, as one query string. */
    const visit = (next: { filters?: Partial<Filters>; sort?: Sort | null; perPage?: number; tab?: Tab; view?: View }) => {
        const merged = { ...filters, ...next.filters };
        const nextView = next.view ?? view;
        // A view sorts by columns of its own, so the order is not carried across:
        // a view asked for opens the way it opens. Null is that order in either
        // view — the server answers a query with no sorting in it with it.
        const nextSort = next.sort !== undefined ? next.sort : nextView !== view ? null : sortedByColumn ? sort : null;
        const nextPer = next.perPage ?? perPage;
        const nextTab = next.tab === undefined ? tab : next.tab;
        const toHolders = nextView === 'holders';

        type Value = string | number | (string | number)[] | null;
        const params: Record<string, Value> = {
            ...merged,
            // The state of a unit and the day it changed hands are the list's own
            // questions, and a query that carries either of them opens the list —
            // which is how /equipment?tab=stock from the dashboard still works. So
            // the view that does not ask them does not keep them in its query.
            ...(toHolders ? { status: [], issued_from: null, issued_to: null } : {}),
            tab: toHolders ? null : (nextTab ?? null),
            // The page opens on «По сотрудникам», so that one is asked for by
            // asking for nothing; the list is named.
            view: toHolders ? null : 'list',
            sort: nextSort?.key ?? null,
            direction: nextSort?.direction ?? null,
            per_page: nextPer === perPageOptions[0] ? null : nextPer,
        };

        // An empty filter is left out rather than sent as a blank.
        const query = Object.fromEntries(
            Object.entries(params).filter(([, value]) => (Array.isArray(value) ? value.length > 0 : value !== null && value !== '')),
        ) as Record<string, Exclude<Value, null>>;

        router.get(route('equipment.index'), query, { preserveState: true, preserveScroll: true, replace: true });
    };

    /**
     * Where a press on a column header takes the sorting: up, then down, then
     * back to the order the table opens in. That last one is asked for by asking
     * for nothing — a query without a sorting in it — so the page never has to
     * name an order it does not know the name of. A direction asked for outright
     * still goes as it is, which is how the phone's sheet turns round an opening
     * order it is already in.
     */
    const sortBy = (key: string, direction?: 'asc' | 'desc') => {
        const next = direction ? { key, direction } : cycleSort(sort, key, OPENING_SORT);
        const opening = !columns.some((column) => column.key === next.key);

        visit({ sort: opening ? (next.key === sort.key ? next : null) : next });
    };

    // Typing searches on its own, once the typing stops.
    const [query, setQuery] = useDebouncedSearch(filters.q, (q) => visit({ filters: { q } }));

    const tabs: { key: Tab; label: string; count: number }[] = [
        { key: 'all', label: 'Все', count: counts.all },
        ...(['issued', 'stock'] as Status[]).map((key) => ({ key, label: statusLabel[key], count: counts[key] })),
        // Not a status: a unit can be with its owner and on service at once.
        { key: 'service', label: 'На обслуживании', count: counts.service },
        { key: 'written_off', label: statusLabel.written_off, count: counts.written_off },
    ];

    /**
     * Everything that narrows the table now, with the label it is asked under.
     * In the list that is the columns themselves; «По сотрудникам» keeps the one
     * column it has and adds the three questions about equipment that have no
     * column there. The statuses and the dates it is not shown are left out of
     * the count too, so the button does not offer to clear what it never offered.
     */
    const narrowing = byHolder ? [columns[0], ...holderFilters] : columns;
    const activeFilters = countActiveFilters(narrowing, filters as unknown as Record<string, unknown>, () => true);
    // A sorting of the viewer's own counts as one more change to reset.
    const changed = activeFilters + (sortedByColumn ? 1 : 0);
    const resetAll = () =>
        visit({
            filters: narrowing.reduce<Partial<Filters>>((acc, column) => ({ ...acc, ...clearedFilter(column.filter!) }), {}),
            sort: null,
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

    /** The person, then a column per category of what stands against their name. */
    const holderCell = (column: ColumnDef, row: HolderRow) =>
        column.key === 'holder' ? (
            <HolderName row={row} />
        ) : (
            <HeldUnits units={row.units[column.typeId as number] ?? []} icon={column.icon as string | null} />
        );

    /** Which categories this colleague actually holds something of, in column order. */
    const held = (row: HolderRow) =>
        holderColumns.filter((column) => column.key !== 'holder' && (row.units[column.typeId as number] ?? []).length > 0);

    /** Rows per page and the paging, under whichever table is being read. */
    const footer = (paginator: Paginated<unknown>) => (
        <>
            <div className="text-muted-foreground flex items-center gap-2 text-sm max-md:hidden">
                {/* The rows of the other table are people, and it counts them as people. */}
                {byHolder ? 'Сотрудников на странице' : 'Строк на странице'}
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
            <Pagination paginator={paginator} className="min-w-0 flex-1" />
        </>
    );

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
                        columns={narrowing}
                        filters={filters as unknown as Record<string, unknown>}
                        onFilter={(changes) => visit({ filters: changes as Partial<Filters> })}
                        sort={sort}
                        sortable={sortable}
                        onSort={sortBy}
                        defaultSort={openingSort}
                        defaultSortLabel={byHolder ? 'Последняя выдача' : 'Дата добавления'}
                        onReset={resetAll}
                        className="max-md:bg-card max-md:rounded-xl max-md:border-transparent max-md:shadow-none"
                    />

                    {/* Which of the two tables is being read. It stands in the query, so a
                        link to the page carries the view somebody was looking at. */}
                    <ToggleGroup
                        type="single"
                        variant="outline"
                        value={view}
                        // Radix sends an empty string when the pressed choice is let go of;
                        // one of the two is always on, so that is nothing to act on.
                        onValueChange={(next) => next && next !== view && visit({ view: next as View })}
                        aria-label="Вид"
                        className="w-full justify-start lg:w-auto"
                    >
                        <ToggleGroupItem value="list" className={viewChoice}>
                            <List />
                            Список
                        </ToggleGroupItem>
                        <ToggleGroupItem value="holders" className={viewChoice}>
                            <Users />
                            По сотрудникам
                        </ToggleGroupItem>
                    </ToggleGroup>

                    {/* A phone scrolls the tabs sideways rather than stacking them in rows,
                        as chips that run to the screen's edges and show no scrollbar. The
                        desktop look is kept under md: so neither size overrides the other.
                        «По сотрудникам» is not offered them: a status there would narrow
                        nothing, since that view says what somebody holds rather than what
                        state it is in, and the server pays the tab no attention. */}
                    {!byHolder && (
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
                    )}

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

                    {/* The questions about equipment that «По сотрудникам» has no column
                        to ask them under; in the list every one of them is a header. */}
                    {byHolder && (
                        <HolderFilters
                            columns={holderFilters}
                            types={options.types}
                            filters={filters}
                            onApply={(changes) => visit({ filters: changes })}
                        />
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
                                    // The column a row is known by stays: the name of a unit
                                    // in the list, the name of a colleague in the other view.
                                    disabled={column.key === (byHolder ? 'holder' : 'name')}
                                    onCheckedChange={(checked) => toggleHidden(column.key, !checked)}
                                    onSelect={(event) => event.preventDefault()}
                                >
                                    {column.label}
                                </DropdownMenuCheckboxItem>
                            ))}
                            <DropdownMenuSeparator />
                            <DropdownMenuItem
                                onSelect={() => {
                                    resetView(storageKey);
                                    setLayout(defaults);
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

                {byHolder && holders && (
                    <DataTable
                        columns={columns}
                        rows={holders.data}
                        rowKey={(row) => row.id}
                        renderCell={holderCell}
                        sort={sort}
                        sortable={sortable}
                        onSort={sortBy}
                        filters={filters as unknown as Record<string, unknown>}
                        onFilter={(changes) => visit({ filters: changes as Partial<Filters> })}
                        view={layout}
                        onPin={pin}
                        onHide={(key) => toggleHidden(key, true)}
                        lockedKey="holder"
                        empty={
                            // A dozen categories make the table wider than the screen,
                            // and a line centred in it would stand off the right edge
                            // where nobody would look for it; it keeps to the left,
                            // where the reading starts.
                            <span className="float-left">
                                {changed > 0 || filters.q !== ''
                                    ? 'Ничего не нашлось. Попробуйте изменить поиск или фильтры.'
                                    : 'Пока ни за кем не числится оборудование.'}
                            </span>
                        }
                        // A card per colleague: the name, and under it only the categories
                        // they actually hold something of, each unit on a line of its own.
                        // Nothing is capped here — the card is the whole answer about that
                        // person — and nothing runs sideways at 320px.
                        mobileRow={(row) => (
                            <li className="flex flex-col gap-2.5 px-4 py-3">
                                <HolderName row={row} />

                                {held(row).length === 0 ? (
                                    <p className="text-muted-foreground pl-9 text-[13px]">Ничего не числится.</p>
                                ) : (
                                    <dl className="flex flex-col gap-2 pl-9">
                                        {held(row).map((column) => (
                                            <div key={column.key} className="flex min-w-0 flex-col gap-0.5">
                                                <dt className="text-muted-foreground text-[12px] leading-tight">{column.label}</dt>
                                                <dd className="flex flex-col gap-2">
                                                    {(row.units[column.typeId as number] ?? []).map((unit) => (
                                                        <Link
                                                            key={unit.id}
                                                            href={route('equipment.show', unit.id)}
                                                            prefetch
                                                            className="flex items-start gap-2 active:opacity-70"
                                                        >
                                                            <CategoryChip
                                                                icon={column.icon as string | null}
                                                                size={28}
                                                                iconSize={14}
                                                                className="mt-px"
                                                            />
                                                            <span className="flex min-w-0 flex-col">
                                                                <span className="text-[15px] leading-tight font-medium break-words">{unit.name}</span>
                                                                <span className="text-muted-foreground text-[13px] leading-tight tabular-nums">
                                                                    инв. № {unit.inventory_number}
                                                                </span>
                                                            </span>
                                                        </Link>
                                                    ))}
                                                </dd>
                                            </div>
                                        ))}
                                    </dl>
                                )}
                            </li>
                        )}
                        footer={footer(holders)}
                    />
                )}

                {!byHolder && equipment && (
                    <DataTable
                        columns={columns}
                        rows={equipment.data}
                        rowKey={(unit) => unit.id}
                        renderCell={cell}
                        sort={sort}
                        sortable={sortable}
                        onSort={sortBy}
                        filters={filters as unknown as Record<string, unknown>}
                        onFilter={(changes) => visit({ filters: changes as Partial<Filters> })}
                        view={layout}
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
                        footer={footer(equipment)}
                    />
                )}
            </div>

            {can.create && <MobileFab href={route('equipment.create')} label="Добавить оборудование" />}
            {asking && <EquipmentMoveDialog unit={asking.unit} kind={asking.kind} holders={options.holders} onClose={() => setAsking(null)} />}
            {deleting && <DeleteDialog unit={deleting} onClose={() => setDeleting(null)} />}
        </AppLayout>
    );
}
