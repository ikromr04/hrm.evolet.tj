import {
    countActiveFilters,
    cycleSort,
    DataTable,
    MobileListTools,
    MobileRow,
    orderedColumns,
    TableMemoryButton,
    useDebouncedSearch,
    useRememberedQuery,
    useTableMemory,
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
import { SearchInput } from '@/components/search-input';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import AppLayout from '@/layouts/app-layout';
import { type EquipmentRights } from '@/lib/access';
import { formatDate } from '@/lib/employee';
import { statusLabel, statusTone, type EquipmentStatus as Status } from '@/lib/equipment';
import { INVENTORY_PLACES, type InventoryPlace } from '@/lib/inventory';
import { cn } from '@/lib/utils';
import { Head, Link, router } from '@inertiajs/react';
import { ArrowDownToLine, ChevronDown, Ellipsis, Eraser, LoaderCircle, Plus, Trash2, UserPlus, Wrench, type LucideIcon } from 'lucide-react';
import { useMemo, useRef, useState, type ReactNode } from 'react';

interface Department {
    /** The abbreviation where the department has one, the full name otherwise. */
    name: string;
    /** What that abbreviation stands for, spelled out. */
    full_name: string;
    id: number;
}

/**
 * Where the holder of a row stands, with the department above them.
 *
 * The structure is two levels and no more, so this pair is the whole of it: a
 * department with a parent is a unit inside that parent, and one without is a
 * department of the company. Both travel because the page has no tree of its
 * own to walk up.
 */
interface Filing extends Department {
    parent: Department | null;
}

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
    /**
     * Where the unit is read in the structure: the department of whoever holds
     * it — the one its holder stands in, or the department itself where the
     * department is the holder. Null both for a unit nobody holds and for a
     * colleague nobody has filed; the two below tell those apart.
     */
    department: Filing | null;
    /**
     * Whether the department above is holding it rather than standing behind a
     * colleague — a printer in a corridor, a department's spare laptop. The
     * two columns of the structure read the same either way; «У кого» does not.
     */
    held_by_department: boolean;
    /** Null while no colleague holds it, which a department's own equipment never has. */
    holder: { id: number; name: string; avatar: string | null; avatar_original: string | null } | null;
    issued_at: string | null;
    written_off_at: string | null;
    /** The day it was put on the books, for the column that is hidden until asked for. */
    created_at: string | null;
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
    /** The same line the unit list prints under a name; null for a category that asks nothing. */
    details: string | null;
    inventory_number: string;
}

/**
 * A holder and everything that stands against their name, by category.
 *
 * Most of them are colleagues. Some things are nobody's in particular, though
 * — a printer in a corridor, the router in the ceiling, a spare laptop — and a
 * department answers for those, so a department is a holder here too, with a
 * row of its own that reads first among the rows of the department it names.
 */
interface HolderRow {
    id: number;
    /** "Фамилия Имя", or the name the department is known by. */
    name: string;
    /** Which of the two this row is; a department is not a who, and is not drawn as one. */
    kind: 'person' | 'department';
    avatar: string | null;
    /** The upload behind the thumbnail, which is what pressing the face opens. */
    avatar_original: string | null;
    /** Where they stand; null for somebody nobody has filed in a department. */
    department: Filing | null;
    /** What they hold, under the id of the category it belongs to. */
    units: Record<number, Held[]>;
}

/**
 * What the two columns of the structure need of a row, in either table: where
 * its holder stands, and who that holder is — a colleague, a department, or
 * nobody at all, which the three of them read as three different cells.
 */
interface Placed {
    id: number;
    department: Filing | null;
    /**
     * Who has the thing. Only the unit list has rows nobody holds; every row of
     * «По сотрудникам» is a person, so there the question does not arise.
     */
    holder?: { id: number } | null;
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
    /** «На обслуживании» is one of the answers and is no unit's status. */
    status: (Status | 'service')[];
    /** The departments of the company asked about, and the units inside them. */
    department: number[];
    unit: number[];
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
    /** The structure flattened, departments first with their units after each. */
    departments: (Department & { depth?: number })[];
}

interface Props {
    /**
     * Which table the page is showing. It opens on «По сотрудникам» — who has
     * what is the question the page is opened with — and the list is asked for
     * by name, which is also how a link that carries a status or a date opens.
     */
    view: View;
    /** Which of the two addresses this page is being drawn at. */
    section: InventoryPlace;
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
    /**
     * The parts of those whose journal may be read; empty means no journal at
     * all. The page draws nothing from it — the journal is reached from the
     * menu — and the server sends it because the right is read there.
     */
    journalScopes: Scope[];
}

// Both keys were bumped when the structure took the front of either table: a
// view saved before it pinned «У кого» to the left edge, and read back now it
// would hold the holder in front of the two columns the table is meant to open
// with. The list's went one further when the date a unit was entered became a
// column of its own: a view that had never heard of it would show it, and the
// table is meant to open without it.
/** The other table's columns are the categories, so its own vista is kept apart. */
/** Which of the two tables a kept view was made in. */
type Table = 'list' | 'holders';

const tableOf = (one: { state?: unknown }) => ((one.state as { table?: Table } | undefined)?.table === 'holders' ? 'holders' : 'list');

const collator = new Intl.Collator('ru');

/** What the «Отдел» funnel calls «Без отдела»; the server knows the same word. */
const NO_UNIT = 'none';

/**
 * The order both tables open in, and the one a third press on a header comes
 * back to: the company read down — the department, the unit inside it, the
 * people in each and what each of them holds.
 *
 * It is no column of either table, so nothing opens with an arrow on it and
 * there is nothing to reset; and it is what makes the two structure columns
 * worth having at all, since a cell covers the rows beside it that say the same
 * thing and no other order puts a department's rows side by side.
 *
 * The server names it and answers a query with no sorting in it with it, so the
 * key here is the server's own rather than a stand-in. The page still needs it
 * written down: a header's third press asks for it by asking for nothing, and
 * the phone's sheet offers it beside the columns while one of them is doing the
 * sorting.
 */
const OPENING_SORT: Sort = { key: 'structure', direction: 'asc' };

/** What the phone's sheet calls that order, there being no header to carry it. */
const OPENING_LABEL = 'Структура';

/** How many units of one category a row names before counting off the rest. */
const HELD_SHOWN = 3;

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

/**
 * Who has it; on the balance sheet, nobody — and nobody again where the thing
 * is a department's own.
 *
 * A printer in a corridor is signed out to the отдел аналитики and to no
 * colleague, so this column reads as empty for it, and the two columns before
 * it say whose it is. That is the whole of what the dash means here: there is
 * no person to ask for it back.
 */
function Holder({ unit }: { unit: Unit }) {
    if (unit.holder) {
        return (
            // The picture and the name answer differently: the first opens the
            // photograph, the second the card.
            <span className="flex min-w-0 items-center gap-2">
                <PersonFace
                    id={unit.holder.id}
                    name={unit.holder.name}
                    avatar={unit.holder.avatar}
                    original={unit.holder.avatar_original}
                    className="size-7 text-[11px]"
                />
                <PersonLink
                    id={unit.holder.id}
                    title={`Открыть профиль: ${unit.holder.name}`}
                    className="text-brand-strong truncate hover:underline dark:text-[#C5E27A]"
                >
                    {unit.holder.name}
                </PersonLink>
            </span>
        );
    }

    // The same dash the two columns before it draw, and for the same reason:
    // the unit is on the balance sheet, between two pairs of hands.
    return <Empty />;
}

/**
 * The two steps a row reads under, and whether anybody holds it at all.
 *
 * A row is handed the one department it is read under, and the structure is two
 * deep, so this is all there is to work out — a department with a parent is a
 * unit, and the parent is the department the row belongs to. Several
 * departments keep people without any unit between, and a holder nobody has
 * filed has neither.
 */
function placing(row: Placed): { department: Department | null; unit: Department | null; unheld: boolean } {
    const own = row.department;
    // Held by nobody at all: no colleague, and no department either — which is
    // a unit on the balance sheet and nothing to put right. A row of «По
    // сотрудникам» has no holder of its own, because it is one.
    const unheld = row.holder === null && own === null;

    if (own === null) return { department: null, unit: null, unheld };

    return own.parent === null ? { department: own, unit: null, unheld } : { department: own.parent, unit: own, unheld };
}

/** A value the row has not got, written the way the rest of the project writes it. */
function Empty() {
    return <span className="text-muted-foreground">—</span>;
}

/**
 * A department named as the org chart names it: the abbreviation leading, and
 * underneath it, quieter and smaller, what the abbreviation stands for.
 *
 * The chart, the positions and this table are where the structure itself is
 * read, and an abbreviation learned in one has to be the same thing in the
 * others, so the two lines stand in the same order and the same tone in all of
 * them. Both belong to the link, so the label reads out whole; only the
 * abbreviation takes the underline — two underlined lines would read as two
 * names rather than one.
 */
function DepartmentLink({ department }: { department: Department }) {
    // A department with no abbreviation is already named in full on the first
    // line, so there is nothing left to spell out underneath it.
    const spelled = department.full_name !== department.name;

    return (
        <Link
            href={route('departments.show', department.id)}
            className="group flex min-w-0 flex-col gap-0.5"
            // Two lines hold most of these names but not the longest, and a
            // name cut off at the second has to be readable without leaving
            // the table.
            title={`Открыть: ${department.full_name}`}
        >
            {/* An abbreviation is three letters and needs nothing said about
                it. A name standing here on its own is a whole name, and wraps
                to the second line and stops there rather than growing the
                cell. Asked for here and not taken off the cell, because a cell
                holds its content on one line on purpose — a cell that hid what
                overflowed would be a scrolling box, and the label kept in view
                inside it would never move. */}
            <span className={cn('group-hover:underline', !spelled && 'line-clamp-2 break-words whitespace-normal')}>{department.name}</span>
            {spelled && (
                // The columns are cut to the abbreviations, so a full name has
                // to wrap — and then to stop, at two lines, rather than make
                // every row of the table as tall as the longest name in it.
                // What is cut off the hover still says, and a word longer than
                // the column breaks rather than pushing the cell wider.
                <span className="text-muted-foreground line-clamp-2 text-[11px] leading-tight break-words whitespace-normal">
                    {department.full_name}
                </span>
            )}
        </Link>
    );
}

/**
 * A holder nobody has filed says so in as many words, and in the colour of
 * something to be dealt with rather than of something missing: a blank cell
 * reads as a page that failed to load, and these rows are the ones somebody has
 * to go and finish — the unit is out with a colleague who stands in no
 * department, so there is no telling whose fleet it belongs to. It says it in
 * both columns, because a column is read down on its own and each of the two
 * has to answer for itself.
 */
function Unfiled({ children }: { children: string }) {
    return (
        <StatusBadge tone="warning" title="Сотрудник не закреплён ни за одним отделом">
            {children}
        </StatusBadge>
    );
}

/**
 * «ДАС › ОА» — both steps of where somebody stands, on the one line a phone row
 * has, written the way a department's own page writes a path. Somebody filed in
 * a department with no unit between is that department alone, and one nobody
 * has filed says so instead, in the colour of something to be dealt with: that
 * is how the unfiled are found on a phone at all.
 *
 * The full names the table's cells carry under their abbreviations stay out of
 * here: the line is cut at the edge of the screen, and spelling two departments
 * out would push everything beside them off it.
 */
function StructureLine({ row }: { row: Placed }) {
    const { department, unit, unheld } = placing(row);

    if (unheld) return null;
    if (department === null) return <span className="text-[#9A4A06] dark:text-[#F8C471]">без департамента</span>;

    return <span>{unit === null ? department.name : `${department.name} › ${unit.name}`}</span>;
}

/**
 * The two columns both tables now open with, and the funnels that narrow either
 * of them to a department or to one unit inside it.
 *
 * Each is a flat list rather than the indented tree one funnel would be: the
 * columns tell the two levels apart, so neither list has to. Neither offers an
 * empty answer, because on this page an empty cell is three different things —
 * nobody holds the unit, the holder stands in no department, the department
 * keeps no units — and one «Без департамента» could not mean all three.
 *
 * Both are cut to their headers, as the positions table cuts the same two: the
 * abbreviation each cell leads with fits that easily, and the full name under it
 * wraps to two lines and is cut there. Widening them to hold the longest name
 * would take the room from what the row is about — the unit and who has it.
 */
function structureColumns(options: Options): ColumnDef[] {
    const option = (department: Department) => ({
        value: department.id,
        label: department.name,
        // The lists are named by abbreviations, and a filter is not a link one
        // can follow to find out what an abbreviation stands for.
        hint: department.full_name === department.name ? undefined : department.full_name,
    });

    return [
        {
            key: 'department',
            label: 'Департамент',
            width: 240,
            filter: {
                type: 'multi',
                param: 'department',
                // The departments the company is built of. Choosing one keeps
                // the equipment of its units as well: a laptop in the отдел
                // аналитики is a laptop of ДАС in every sense somebody means by
                // the question.
                // «Без департамента» asks for the rows this column is empty on.
                options: [
                    { value: NO_UNIT, label: 'Без департамента' },
                    ...options.departments.filter((department) => !department.depth).map(option),
                ],
            },
        },
        {
            key: 'unit',
            label: 'Отдел',
            width: 200,
            filter: {
                type: 'multi',
                param: 'unit',
                // The units, by the name they are shown under rather than in
                // the tree's order: their departments are not in this list to
                // be read down, so a reader looks a unit up by its own name.
                // «Без отдела» asks for the rows this column is empty on.
                options: [
                    { value: NO_UNIT, label: 'Без отдела' },
                    ...[...options.departments.filter((department) => department.depth === 1)]
                        .sort((a, b) => collator.compare(a.name, b.name))
                        .map(option),
                ],
            },
        },
    ];
}

/**
 * The two columns of the structure, in either table: a cell per row, like every
 * other column of both.
 *
 * The name repeats on every row it is true of — ДАС, ДАС, ДАС — rather than
 * standing once over a block of them. A repeated name is read where the eye
 * already is, at the row it is reading; a name that covers twelve rows is read
 * once, at the top, and the eleven rows under it are left to be traced back up
 * to it. The order the table opens in still gathers a department's rows
 * together, so the repetition reads as the block it is.
 */
function structureCell(column: ColumnDef, row: Placed): ReactNode {
    const { department, unit, unheld } = placing(row);

    // Nobody holds it, so there is no department it stands in — and nothing to
    // put right either: the balance sheet is where a unit between two pairs of
    // hands belongs, and a third of the fleet is there at any time. Both cells
    // read as the plain empty value the «У кого» column beside them reads as.
    if (unheld) return <Empty />;

    if (column.key === 'department') {
        return department === null ? <Unfiled>Без департамента</Unfiled> : <DepartmentLink department={department} />;
    }

    if (unit !== null) return <DepartmentLink department={unit} />;

    // A department may keep people with no unit between — ДУЧР has no units at
    // all — and that is nothing to fix, so the cell reads as an empty value
    // rather than as the amber beside it. A holder with no department has no
    // unit either, and says so in the same words the column before it used.
    return department === null ? <Unfiled>Без отдела</Unfiled> : <Empty />;
}

/**
 * The columns, each with the filter that narrows it.
 *
 * The row reads along the structure and then down into the thing: the
 * department, the unit inside it, who has it, and then what it is. That is the
 * order somebody says it out loud in — «в ДАС, в отделе аналитики, у Бобоева,
 * ноутбук» — and it is what lets the fleet be read department by department:
 * put in order by either of the first two columns, one name stands over the run
 * of rows it owns.
 */
function buildColumns(options: Options): ColumnDef[] {
    return [
        ...structureColumns(options),
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
            fill: true,
            filter: { type: 'text', param: 'name', placeholder: 'Название модели' },
        },
        {
            key: 'inventory_number',
            label: 'Инв. номер',
            width: 200,
            filter: { type: 'text', param: 'inventory_number', placeholder: 'EV-0421' },
        },
        {
            key: 'type',
            label: 'Категория',
            width: 200,
            filter: { type: 'multi', param: 'type', options: options.types.map((t) => ({ value: t.id, label: t.name })) },
        },
        {
            key: 'status',
            label: 'Статус',
            width: 180,
            filter: { type: 'multi', param: 'status', options: options.statuses.map((s) => ({ value: s.value, label: s.label })) },
        },
        {
            key: 'issued_at',
            label: 'Выдано',
            width: 190,
            filter: { type: 'dates', from: 'issued_from', to: 'issued_to' },
        },
        // When the unit was put on the books. It was the order the list opened
        // in and no column at all — the one question the table answered with no
        // header to press — until the structure took that place; so it has a
        // column of its own now rather than becoming a thing only a hand-typed
        // address could ask for.
        { key: 'created', label: 'Добавлено', width: 190 },
    ];
}

/**
 * Both tables open with nothing held to an edge and nothing hidden.
 *
 * «У кого» used to be pinned to the left, so that the unit's own facts scrolled
 * under the name of whoever has it. The structure now opens both tables, and
 * holding all three of the columns that say where a unit is would take half the
 * card before a single fact about the unit itself was drawn; holding only the
 * last of them would stand it in front of the two it reads after. So neither
 * table pins anything of its own, as the positions table does not, and a viewer
 * who wants a column at an edge still pins it from the column's own menu.
 */
const defaultView = (hidden: string[] = []): ViewState => ({ hidden, pinned: { left: [], right: [] } });

/** A category's column, named after the category it stands for. */
const typeKey = (id: number) => `type-${id}`;

/**
 * «По сотрудникам»: where the colleague stands, the colleague, then a column
 * per category of equipment.
 *
 * The two steps of the structure open this table as they open the other one,
 * and they say more here: a row is a person and everything against their name,
 * so a department named down its column against each of its people is the
 * fleet of that department read in one run, by the people who carry it.
 *
 * The categories come in the order the toolbar already offers them in, and only
 * those asked for come at all — somebody who filtered the view down to monitors
 * is not asking to scroll past eleven empty columns to see them.
 */
function buildHolderColumns(options: Options, chosen: number[]): ColumnDef[] {
    const types = chosen.length > 0 ? options.types.filter((type) => chosen.includes(type.id)) : options.types;

    return [
        ...structureColumns(options),
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
 * The number on a unit's sticker, as both shapes of this view print it: under
 * the name and under whatever the unit's category asks about.
 *
 * Three muted lines one under the other read as one line repeated, so the label
 * keeps the muted grey and the digits take the ordinary foreground. The number
 * is then told apart by weight rather than by a colour, which would compete
 * with the name above it — a link, in brand green — for the loudest thing in a
 * cell. The digits stay plain selectable text, because this is what people
 * search by and read out to each other, and they are tabular so that the
 * numbers of several units line up down the cell.
 */
function InventoryNumber({ number }: { number: string }) {
    return (
        <span className="text-[13px] leading-tight">
            <span className="text-muted-foreground">инв. №</span> <span className="text-foreground font-medium tabular-nums">{number}</span>
        </span>
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
                        {/* What its category asks about, as the unit list prints it under a
                            name. A category that asks nothing leaves no line behind, so a
                            cell of such units stays as tight as it was. */}
                        {unit.details !== null && <span className="text-muted-foreground text-[13px] leading-tight break-words">{unit.details}</span>}
                        <InventoryNumber number={unit.inventory_number} />
                    </span>
                </Link>
            ))}
            {(hidden > 0 || open) && <MoreChip hidden={hidden} open={open} onToggle={() => setOpen(!open)} />}
        </div>
    );
}

/**
 * The colleague a row is about, drawn as the list's own holder column draws
 * one — and, where the row is a department's own equipment, the dash the list
 * draws in the same place.
 *
 * A department is not a who. It is named in the two columns before this one,
 * where it stands over its own row and over its people's, so naming it again
 * here would be the same word twice side by side; what this column has to say
 * about such a row is that there is no colleague to ask for the thing back —
 * which a dash says, as it says it in every other column of the app.
 */
function HolderName({ row }: { row: HolderRow }) {
    if (row.kind === 'department') {
        return <Empty />;
    }

    return (
        <span className="flex min-w-0 items-center gap-2">
            <PersonFace id={row.id} name={row.name} avatar={row.avatar} original={row.avatar_original} className="size-7 text-[11px]" />
            <PersonLink id={row.id} title={`Открыть профиль: ${row.name}`} className="text-brand-strong truncate hover:underline dark:text-[#C5E27A]">
                {row.name}
            </PersonLink>
        </span>
    );
}

/**
 * What a card of «По сотрудникам» is headed by on a phone: the colleague, or
 * the department whose own equipment the card holds.
 *
 * A card is read on its own, with no column of the structure beside it to name
 * what it belongs to, so here the department does name itself — in the mark the
 * application uses for a department everywhere, and with the same quiet line
 * under it that the table's column carries.
 */
function HolderCardName({ row }: { row: HolderRow }) {
    if (row.kind !== 'department' || row.department === null) return <HolderName row={row} />;

    return (
        <span className="flex flex-col gap-0.5">
            <DepartmentLink department={row.department} />
            <span className="text-muted-foreground text-[12px] leading-tight">оборудование отдела</span>
        </span>
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
    section,
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
}: Props) {
    const byHolder = view === 'holders';
    const place = INVENTORY_PLACES[section];
    // The journal is open where at least one part of the fleet carries it.
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
    // Both tables open with every column of theirs showing; what is not wanted
    // is turned off in the «Вид» menu and kept there.
    const unitDefaults = useMemo(() => defaultView(), []);
    const holderDefaults = useMemo(() => defaultView(), []);

    // Both tables keep their views in one list, and each takes only its own: a
    // column hidden among the categories means nothing to the list of units.
    // Both are loaded either way, because a switch of view does not remount the
    // page and a hook asked for conditionally would be the wrong one afterwards.
    const unitView = useTableView(
        place.viewKey,
        unitColumns.map((column) => column.key),
        unitDefaults,
        (one) => tableOf(one) === 'list',
    );
    const holderView = useTableView(
        place.viewKey,
        // Every category, not only the columns drawn right now: a category left
        // out by a filter has not been hidden, and should come back as it was.
        ['department', 'unit', 'holder', ...options.types.map((type) => typeKey(type.id))],
        holderDefaults,
        (one) => tableOf(one) === 'holders',
    );
    const defaults = byHolder ? holderDefaults : unitDefaults;
    const { view: layout, pin, toggleHidden, reorder, order } = byHolder ? holderView : unitView;
    const isHidden = (key: string) => layout.hidden.includes(key);

    /* Both tables can be dragged into another order, so the menu reads along whichever is on screen. */
    const listed = orderedColumns(columns, order);

    useRememberedQuery(place.queryKey, place.viewKey);
    /**
     * Which table a view coming out of the menu belongs to. The menu hands it
     * over before the columns, so the arrangement goes to the table it was kept
     * for rather than to the one still on screen — the switch itself rides in
     * the query the view carries.
     */
    const bound = useRef<Table>(byHolder ? 'holders' : 'list');
    bound.current = byHolder ? 'holders' : 'list';

    // One list of views for the page, each saying which table it is for; the
    // questions are the page's own and are kept with it.
    const memory = useTableMemory({
        viewKey: place.viewKey,
        queryKey: place.queryKey,
        // Both tables' columns, so a view kept for one is not stripped of its
        // columns when it is read while the other is on screen.
        columnKeys: [...new Set([...unitColumns, ...holderColumns].map((column) => column.key))],
        view: layout,
        setView: (next) => (bound.current === 'holders' ? holderView.setView(next) : unitView.setView(next)),
        fallback: defaults,
        state: { table: byHolder ? 'holders' : 'list', label: byHolder ? 'По сотрудникам' : 'Список' },
        // Each address draws the one table, so the one it opens with is that
        // one: «По умолчанию» puts the columns back where they are read, not
        // into a table this page no longer shows.
        defaultState: { table: byHolder ? 'holders' : 'list', label: byHolder ? 'По сотрудникам' : 'Список' },
        onState: (held) => {
            bound.current = (held as { table?: Table })?.table === 'holders' ? 'holders' : 'list';
        },
    });
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
    const visit = (next: { filters?: Partial<Filters>; sort?: Sort | null; perPage?: number; tab?: Tab }) => {
        const merged = { ...filters, ...next.filters };
        // Null is the order the table opens in: the server answers a query with
        // no sorting in it with that one.
        const nextSort = next.sort !== undefined ? next.sort : sortedByColumn ? sort : null;
        const nextPer = next.perPage ?? perPage;
        const nextTab = next.tab === undefined ? tab : next.tab;

        type Value = string | number | (string | number)[] | null;
        const params: Record<string, Value> = {
            ...merged,
            // The state of a unit and the day it changed hands are questions
            // about units, which the table of colleagues does not ask and does
            // not carry.
            ...(byHolder ? { status: [], issued_from: null, issued_to: null } : {}),
            tab: byHolder ? null : (nextTab ?? null),
            sort: nextSort?.key ?? null,
            direction: nextSort?.direction ?? null,
            per_page: nextPer === perPageOptions[0] ? null : nextPer,
        };

        // An empty filter is left out rather than sent as a blank.
        const query = Object.fromEntries(
            Object.entries(params).filter(([, value]) => (Array.isArray(value) ? value.length > 0 : value !== null && value !== '')),
        ) as Record<string, Exclude<Value, null>>;

        router.get(route(place.route), query, { preserveState: true, preserveScroll: true, replace: true });
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
     * In the list that is the columns themselves; «По сотрудникам» keeps the
     * columns that are its own — the structure and the colleague — and adds the
     * three questions about equipment that have no column there, the categories
     * being columns that ask nothing. The statuses and the dates it is not
     * shown are left out of the count too, so the button does not offer to
     * clear what it never offered.
     *
     * Both branches keep only the columns that actually ask something: the list
     * draws plenty that narrow nothing — the inventory number, the dates — and
     * a column with no filter has nothing to clear. Reset read the unfiltered
     * list and threw on the first such column, which took the whole press with
     * it and left the button looking dead.
     */
    const narrowing = byHolder ? [...columns.filter((column) => column.filter), ...holderFilters] : columns.filter((column) => column.filter);
    const activeFilters = countActiveFilters(narrowing, filters as unknown as Record<string, unknown>, () => true);
    // A sorting of the viewer's own counts as one more change to the list.
    const changed = activeFilters + (sortedByColumn ? 1 : 0);

    const cell = (column: ColumnDef, unit: Unit) => {
        switch (column.key) {
            case 'department':
            case 'unit':
                return structureCell(column, unit);
            // The day it was entered, written as every date in the app is. It
            // says nothing about where the unit is, so it is as quiet as the
            // other date beside it.
            case 'created':
                return <span className="text-muted-foreground tabular-nums">{formatDate(unit.created_at) ?? '—'}</span>;
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

    /** Where they stand, the person, then a column per category of what stands against their name. */
    const holderCell = (column: ColumnDef, row: HolderRow) => {
        if (column.key === 'department' || column.key === 'unit') return structureCell(column, row);

        if (column.key === 'holder') return <HolderName row={row} />;

        return <HeldUnits units={row.units[column.typeId as number] ?? []} icon={column.icon as string | null} />;
    };

    /** Which categories this colleague actually holds something of, in column order. */
    const held = (row: HolderRow) =>
        // A category's column is the one that stands for a category; the
        // structure and the colleague stand for themselves.
        holderColumns.filter((column) => column.typeId !== undefined && (row.units[column.typeId as number] ?? []).length > 0);

    /** Rows per page and the paging, under whichever table is being read. */
    const footer = (paginator: Paginated<unknown>) => (
        <>
            <div className="text-muted-foreground flex items-center gap-2 text-sm max-md:hidden">
                {/* The rows of the other table are people, and it counts them as people. */}
                {/* Rows either way. The other table used to count its rows as
                    people, and most of them are; but a department holding its
                    own equipment has a row there too, and it is not an
                    employee — so neither table promises what it is counting. */}
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
            <Pagination paginator={paginator} className="min-w-0 flex-1" />
        </>
    );

    return (
        <AppLayout breadcrumbs={place.crumbs} fitViewport>
            <Head title={place.title} />

            <div className="flex flex-1 flex-col gap-4 p-3 max-md:gap-3 md:min-h-0 md:px-4 md:py-6 lg:px-6">
                <h1 className="text-xl leading-7 font-semibold tracking-[-0.01em] max-md:hidden">{place.title}</h1>

                {/* The lists, the box to look through them, and at the end of the line what
                    acts on the page. On a phone: the search and the sorting-and-filters
                    button, then the tabs as a row of chips; the rest is reached from the tab
                    bar or the round button. */}
                <div className="flex flex-wrap items-center gap-2">
                    {/* The lists the page can show, as plain tabs. Choosing one is a visit,
                        so the value is the address's and the strip only says which is open.
                        «По сотрудникам» is offered none: a status there would narrow
                        nothing, since that table says what somebody holds rather than what
                        state it is in, and the server pays the tab no attention. */}
                    {!byHolder && (
                        <Tabs value={tab} onValueChange={(next) => visit({ tab: next as Tab })} className="shrink-0 max-md:hidden">
                            <TabsList aria-label="Статус оборудования">
                                {tabs.map((item) => (
                                    <TabsTrigger key={item.key} value={item.key}>
                                        {item.label}
                                        <span className="text-muted-foreground text-xs tabular-nums">{item.count}</span>
                                    </TabsTrigger>
                                ))}
                            </TabsList>
                        </Tabs>
                    )}

                    {/* text-base on a phone: a smaller font makes iOS zoom in on focus. */}
                    <SearchInput
                        id="equipment-search"
                        value={query}
                        onChange={setQuery}
                        placeholder="Поиск по всем полям"
                        className="w-80 min-w-48 max-md:w-auto max-md:min-w-0 max-md:flex-1 md:mr-auto"
                        inputClassName="max-md:bg-card text-sm max-md:text-base max-md:rounded-xl max-md:border-transparent max-md:shadow-none"
                    />

                    <MobileListTools
                        columns={narrowing}
                        filters={filters as unknown as Record<string, unknown>}
                        onFilter={(changes) => visit({ filters: changes as Partial<Filters> })}
                        sort={sort}
                        sortable={sortable}
                        onSort={sortBy}
                        defaultSort={openingSort}
                        // No header carries the opening order — it is the one
                        // the three presses come back to, and the one that puts
                        // a department's rows together — so the sheet names it
                        // above the columns.
                        defaultSortLabel={OPENING_LABEL}
                        className="max-md:bg-card max-md:rounded-xl max-md:border-transparent max-md:shadow-none"
                    />

                    {/* A phone scrolls the same lists sideways as chips that run to the
                        screen's edges and show no scrollbar; a desktop reads them off the
                        tabs at the head of this line. */}
                    {!byHolder && (
                        <nav
                            aria-label="Статус оборудования"
                            className="-mx-3 flex w-[calc(100%+1.5rem)] items-center gap-1.5 overflow-x-auto px-3 text-sm [scrollbar-width:none] md:hidden [&::-webkit-scrollbar]:hidden"
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
                                            'flex shrink-0 items-center gap-1.5 whitespace-nowrap transition-colors max-md:h-8 max-md:rounded-full max-md:px-3 md:h-9 md:rounded-md md:px-2.5',
                                            active
                                                ? 'bg-brand-soft text-foreground font-semibold dark:bg-white/10'
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

                    {/* The kept views and the columns in one menu over the table. */}
                    <TableMemoryButton memory={memory} className="h-9 max-md:hidden">
                        {listed.map((column) => (
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
                    </TableMemoryButton>

                    {can.create && (
                        <Button className="h-9 max-md:hidden" asChild>
                            {/* Each page adds to its own part of the stock, and
                                the button says which. */}
                            <Link href={route(place.add.route)}>
                                <Plus />
                                {place.add.label}
                            </Link>
                        </Button>
                    )}
                </div>

                {/* Said once, above the table, rather than repeated on every empty tab. */}
                {notice && <p className="text-muted-foreground text-[13px]">{notice}</p>}

                {byHolder && holders && (
                    <DataTable
                        columns={columns}
                        rows={holders.data}
                        // Two kinds of row on one table, each with ids of its
                        // own: a colleague and a department can both be 29, and
                        // keyed by the number alone the two are one row to
                        // React — which it draws twice and then refuses to put
                        // back in order. The kind is what tells them apart,
                        // here as it does on the server.
                        rowKey={(row) => `${row.kind}:${row.id}`}
                        renderCell={holderCell}
                        sort={sort}
                        sortable={sortable}
                        onSort={sortBy}
                        filters={filters as unknown as Record<string, unknown>}
                        onFilter={(changes) => visit({ filters: changes as Partial<Filters> })}
                        // Both lists of departments spell a full name out
                        // beside an abbreviation, which needs more room than a
                        // short label.
                        view={layout}
                        onPin={pin}
                        // The columns here are the categories, and which of them
                        // a viewer cares about is their own business.
                        reorderable
                        onReorder={reorder}
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
                        // A card per holder: the name, where they stand, and under it only
                        // the categories they actually hold something of, each unit on a
                        // line of its own. Nothing is capped here — the card is the whole
                        // answer about that person, or about what the department keeps in
                        // its own name — and nothing runs sideways at 320px.
                        mobileRow={(row) => (
                            <li className="flex flex-col gap-2.5 px-4 py-3">
                                <HolderCardName row={row} />

                                {/* There are no columns on a phone, so the path is written
                                    out on a line of its own, indented to the name it belongs
                                    to. The cards of a department repeat it, as the rows of
                                    the table do, which is what makes a list sorted by
                                    department readable as one. */}
                                <p className="text-muted-foreground pl-9 text-[12px] leading-tight">
                                    <StructureLine row={row} />
                                </p>

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
                                                                {/* The card is the whole answer about this person,
                                                                    so it says of a unit what the wide table says:
                                                                    two monitors of one name are told apart here
                                                                    and nowhere else. */}
                                                                {unit.details !== null && (
                                                                    <span className="text-muted-foreground text-[13px] leading-tight break-words">
                                                                        {unit.details}
                                                                    </span>
                                                                )}
                                                                <InventoryNumber number={unit.inventory_number} />
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
                        reorderable
                        onReorder={reorder}
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
                                //
                                // Where the holder stands closes the line rather than
                                // opening it: the line is cut at the edge of the screen,
                                // and of the three things on it the path is the one a
                                // narrow phone can best do without — the row names the
                                // person, and a person's department is read on their card.
                                // A unit nobody holds has no path and the line ends early.
                                subtitle={
                                    <span className="tabular-nums">
                                        Инв. № {unit.inventory_number} · {unit.holder?.name ?? unit.type ?? statusLabel[unit.status]}
                                        {unit.holder !== null && (
                                            <>
                                                {' · '}
                                                <StructureLine row={unit} />
                                            </>
                                        )}
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
            {asking && (
                <EquipmentMoveDialog
                    unit={asking.unit}
                    kind={asking.kind}
                    holders={options.holders}
                    // The same structure the two columns are narrowed by, for a
                    // category whose equipment is handed to a department.
                    departments={options.departments}
                    onClose={() => setAsking(null)}
                />
            )}
            {deleting && <DeleteDialog unit={deleting} onClose={() => setDeleting(null)} />}
        </AppLayout>
    );
}
