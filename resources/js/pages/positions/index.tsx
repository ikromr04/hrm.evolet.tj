import {
    cycleSort,
    DataTable,
    isSorted,
    MobileListTools,
    MobileRow,
    useTableView,
    type ColumnDef,
    type FilterDef,
    type FilterValues,
    type Sort,
    type ViewState,
} from '@/components/data-table';
import { searchBox } from '@/components/directory-manager';
import InputError from '@/components/input-error';
import { MobileFab } from '@/components/mobile-fab';
import { MultiSelect } from '@/components/multi-select';
import { SearchableSelect } from '@/components/searchable-select';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { plural } from '@/lib/plural';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { Ellipsis, LoaderCircle, Pencil, Plus, Search, Trash2 } from 'lucide-react';
import { useMemo, useState, type FormEventHandler, type ReactNode } from 'react';

interface Department {
    /** The abbreviation where the department has one, the full name otherwise. */
    name: string;
    /** What that abbreviation stands for, spelled out. */
    full_name: string;
    id: number;
}

/**
 * The department a position belongs to, with the one above it.
 *
 * The structure is two levels and no more, so this pair is the whole of where a
 * position sits: a department with a parent is a unit inside that parent, and
 * one without is a department of the company. The page is handed both because
 * it has no tree of its own to walk up.
 */
interface Filing extends Department {
    parent: Department | null;
}

interface Position {
    id: number;
    name: string;
    /** «Аналитик (ОПР)» — the name with the department after it, where one line is all there is. */
    display_name: string;
    /** Where the position sits; null for the few nobody has filed yet. */
    department: Filing | null;
    /**
     * What the job is, a short line per duty, in the order they were entered.
     * One list: the position belongs to one department, so there is nothing
     * inside it to tell apart.
     */
    duties: string[];
    /**
     * How many working employees hold the position. The table does not show it:
     * who holds a position is read on the position's own page, and this is what
     * the warning before a deletion counts.
     */
    employees_count: number;
}

interface Props {
    positions: Position[];
    /** The department tree flattened, parents first, for the filter and the editor. */
    options: { departments: (Department & { depth?: number })[] };
    /**
     * Whether this list is this viewer's to change. Reading the section and
     * keeping it are two different rights, so without the second one the same
     * table simply has no controls.
     */
    canEdit: boolean;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Должности', href: '/positions' }];

const STORAGE_KEY = 'positions.table.view.v2';

/**
 * The order the list opens in, and the one a third press on a header and "reset"
 * both bring back: the structure itself, read down — the department, the
 * positions filed on the department itself, then its units and the positions in
 * each.
 *
 * That is what the «Департамент» column ascending already does, so the column
 * carries the opening order and the phone's sheet names it by its own label; a
 * third press on that header lands on ascending again, because ascending is
 * where the list came from.
 *
 * The list used to open newest first, as the staff list does. That order told a
 * reader nothing here: the names repeat — twenty of them — and «Аналитик» above
 * «Дизайнер» above «Аналитик» in the order somebody happened to type them is a
 * list one can only search. The structure is what the four columns are for, and
 * it is the only order in which a department can be named once over the rows it
 * holds instead of seventeen times down them.
 */
const DEFAULT_SORT: Sort = { key: 'department', direction: 'asc' };

/** How many duties a row shows before the rest are counted off at the end. */
const DUTIES_SHOWN = 4;

/** Stands for "nothing there at all" where a department's id would stand. */
const NONE = 'none';

const collator = new Intl.Collator('ru');

/**
 * The row reads along the structure and then down into the job: the department,
 * the unit inside it, the position itself and what it answers for. That is the
 * order somebody says it out loud in — «в ДАС, в отделе аналитики, аналитик» —
 * and the two departments stand together because a position means little
 * without them: twenty of these names repeat, and «Аналитик» alone answers
 * nothing.
 *
 * Who holds a position is no longer here. It was the widest column of the four
 * and said what a position's own page says better, with the duties each holder
 * carries beside their name; the room it took has gone to the duties, which wrap.
 */
const columns: ColumnDef[] = [
    // Both are cut to their headers: «Департамент» has to stand beside its own
    // funnel and menu without either leaning into the column next door. The
    // abbreviation each cell leads with fits that easily, and the full name under
    // it does not always — it wraps to two lines and is cut there. Widening these
    // two to hold the longest name would take the room from the duties, which are
    // sentences and need it more; whoever wants the whole name has the hover and
    // the department's own page.
    { key: 'department', label: 'Департамент', width: 215 },
    { key: 'unit', label: 'Отдел', width: 180 },
    { key: 'name', label: 'Должность', width: 245 },
    { key: 'duties', label: 'Обязанности', width: 360 },
];

const columnKeys = columns.map((column) => column.key);

/** Duties are a list, not a scale: the other three columns can be put in order. */
const sortable = ['department', 'unit', 'name'];

const defaultView: ViewState = { hidden: [], pinned: { left: [], right: [] } };

/** Which departments or units are being asked about; «Без…» is one of the answers. */
type Chosen = (number | typeof NONE)[];

/** What the two funnels are set to. */
interface Narrowing {
    department: Chosen;
    unit: Chosen;
}

/**
 * The two steps a row reads under: the department, and the unit inside it where
 * the position sits in one.
 *
 * A position belongs to one department, and the structure is two deep, so this
 * is all there is to work out — a department with a parent is a unit, and the
 * parent is the department the row belongs to. Several departments hold
 * positions of their own without any unit between, and one nobody has filed has
 * neither.
 */
function filing(position: Position): { department: Department | null; unit: Department | null } {
    const own = position.department;

    if (own === null) return { department: null, unit: null };

    return own.parent === null ? { department: own, unit: null } : { department: own.parent, unit: own };
}

/**
 * There are dozens of positions, not thousands, so the whole list arrives at
 * once and the search, the order and the narrowing are all worked out here
 * rather than asked of the server.
 *
 * A funnel per column, each a flat list rather than the indented tree the one
 * funnel used to be: the columns tell the two levels apart, so neither list has
 * to. «Без департамента» and «Без отдела» stand at the top of them — the few
 * positions the data could not place have to be findable, and so do the ones
 * hanging off a department with no unit under them.
 */
function buildColumns(departments: Props['options']['departments']): ColumnDef[] {
    const option = (department: Department) => ({
        value: department.id,
        label: department.name,
        // The list is named by abbreviations, and a filter is not a link one
        // can follow to find out what an abbreviation stands for.
        hint: department.full_name === department.name ? undefined : department.full_name,
    });

    const filters: Record<string, FilterDef> = {
        department: {
            type: 'multi',
            param: 'department',
            // The departments the company is built of. Choosing one keeps its
            // own positions and those of every unit inside it: a position in the
            // отдел аналитики is a position in ДАС in every sense somebody means
            // by the question.
            options: [{ value: NONE, label: 'Без департамента' }, ...departments.filter((department) => !department.depth).map(option)],
        },
        unit: {
            type: 'multi',
            param: 'unit',
            // The units, by the name they are shown under rather than in the
            // tree's order: their departments are not in this list to be read
            // down, so a reader looks a unit up by its own name. «Без отдела»
            // asks for the positions that hang off a department itself, and with
            // them the few nobody has filed at all — in both the column is empty.
            options: [
                { value: NONE, label: 'Без отдела' },
                ...departments
                    .filter((department) => department.depth === 1)
                    .sort((a, b) => collator.compare(a.name, b.name))
                    .map(option),
            ],
        },
    };

    return columns.map((column) => (filters[column.key] ? { ...column, filter: filters[column.key] } : column));
}

/** Whether the row is in one of the departments, and one of the units, asked about. */
function inChosen(position: Position, chosen: Narrowing): boolean {
    const { department, unit } = filing(position);

    return (
        (chosen.department.length === 0 || chosen.department.includes(department?.id ?? NONE)) &&
        (chosen.unit.length === 0 || chosen.unit.includes(unit?.id ?? NONE))
    );
}

/**
 * What an empty list says when the funnels, and not the search, emptied it.
 *
 * A name is spelled out rather than abbreviated: a cell has only room for "ОА",
 * but a sentence standing alone in an empty table has to be read without
 * anything beside it to explain the letters. It says «здесь» rather than naming
 * the level again — every full name already opens with «Департамент» or «Отдел».
 */
function emptyMessage(chosen: Narrowing, departments: Props['options']['departments']): string {
    const asked = [...chosen.department, ...chosen.unit];

    if (asked.length === 1 && asked[0] === NONE) {
        return chosen.department.length === 1 ? 'Все должности закреплены за департаментами.' : 'Все должности закреплены за отделами.';
    }

    const alone = asked.length === 1 ? departments.find((department) => department.id === asked[0]) : undefined;
    if (alone) return `${alone.full_name} — должностей здесь пока нет.`;

    if (chosen.unit.length === 0) return 'В выбранных департаментах должностей пока нет.';
    if (chosen.department.length === 0) return 'В выбранных отделах должностей пока нет.';

    return 'В выбранных департаментах и отделах должностей пока нет.';
}

function compare(a: Position, b: Position, sort: Sort): number {
    const sign = sort.direction === 'asc' ? 1 : -1;
    const here = filing(a);
    const there = filing(b);
    // Positions that tie — the same department, the same unit — read
    // alphabetically rather than in whatever order they happened to arrive in.
    const byName = () => collator.compare(a.name, b.name);
    // Nothing at all sorts before every name there is, which decides two things
    // at once. The positions filed on a department itself come before its units,
    // as they should: they are the department's own, and a unit is a step further
    // in. And the three positions with no department whatever open the list — the
    // order the page opens in is this one, so the rows somebody still has to file
    // are the first thing on it, which is where the amber «Без департамента» is
    // meant to be read and the one place they cannot be scrolled past.
    const byDepartment = () => collator.compare(here.department?.name ?? '', there.department?.name ?? '');
    const byUnit = () => collator.compare(here.unit?.name ?? '', there.unit?.name ?? '');

    // Sorted by the department, the order reads the structure itself: the
    // department, its own positions, then the units within it and the positions
    // in each. This is the order the list opens in, and the only one in which a
    // department's rows all stand together.
    if (sort.key === 'department') return sign * byDepartment() || sign * byUnit() || byName();
    // By the unit the units stand together, but their departments are scattered
    // among them: a department holds units whose names begin anywhere.
    if (sort.key === 'unit') return sign * byUnit() || byDepartment() || byName();

    // Namesakes stand in the order the columns before them already read in.
    return sign * byName() || byDepartment() || byUnit();
}

/**
 * How many rows each cell of the two structure columns covers, by the id of the
 * position whose row it begins on. A row the map says nothing about is one a cell
 * above has already covered, and the table draws no cell of its own there.
 */
interface Spans {
    department: Map<number, number>;
    unit: Map<number, number>;
}

/**
 * Where each run of rows sharing a department, and each run sharing a unit
 * inside it, begins and how far it reaches.
 *
 * Read off the rows as the table is about to draw them — after the search and
 * the funnels, in the order they are in — so a block opens on the first row that
 * survived them and covers only the rows still there, and so what the table is
 * told cannot disagree with what is on screen.
 */
function spansOf(rows: Position[]): Spans {
    const department = new Map<number, number>();
    const unit = new Map<number, number>();
    // The row each run began on, so its cell can be told how far it reached once
    // the run has ended. Undefined is "no row yet", which null — a position
    // nobody has filed — is not: whatever the first row holds, it opens both of
    // its blocks.
    let openDepartment: number | undefined;
    let openUnit: number | undefined;
    let lastDepartment: number | null | undefined;
    let lastUnit: number | null | undefined;

    rows.forEach((position) => {
        const where = filing(position);
        const inDepartment = where.department?.id ?? null;
        const inUnit = where.unit?.id ?? null;

        if (inDepartment !== lastDepartment) {
            openDepartment = position.id;
            department.set(position.id, 0);
            // A unit block never spans two departments, even where the two cells
            // read the same: ДУЧР and ДФКА both hold positions with no unit
            // between, and that is two cells rather than one reaching across the
            // line that divides their departments.
            lastUnit = undefined;
        }

        if (inUnit !== lastUnit) {
            openUnit = position.id;
            unit.set(position.id, 0);
        }

        // Every row adds itself to the two cells standing over it.
        department.set(openDepartment!, department.get(openDepartment!)! + 1);
        unit.set(openUnit!, unit.get(openUnit!)! + 1);

        lastDepartment = inDepartment;
        lastUnit = inUnit;
    });

    return { department, unit };
}

/** A value the row has not got, written the way the rest of the project writes it. */
function Empty() {
    return <span className="text-muted-foreground">—</span>;
}

/**
 * The chip that counts off what a cell left out, and shows it where it stands.
 *
 * A row keeps its height by naming a few and counting the rest; pressing the
 * count opens them in place, because somebody who wants to see who else is in a
 * position is not asking to leave the list they are reading.
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
        >
            <StatusBadge className="text-muted-foreground hover:bg-accent">{open ? 'Свернуть' : `+${hidden}`}</StatusBadge>
        </button>
    );
}

/**
 * The duties of a position, numbered down a column.
 *
 * A duty is a sentence more often than a word, and sentences side by side read
 * as one run-on line, so each takes a line of its own and the numbers say how
 * many there are and which is which. They number the duties' own order — the
 * order somebody entered them, which is the order the server sends and the order
 * the editor's chips stand in — rather than any order of this column's making.
 */
function Duties({ duties, limit }: { duties: string[]; limit?: number }) {
    const [open, setOpen] = useState(false);

    if (duties.length === 0) return <Empty />;

    // A position with a dozen duties would otherwise make its row as tall as the
    // rest of the table put together, so the row names a few until asked. They
    // are the first few, so the numbers on them are still the ones they carry in
    // the whole list.
    const shown = limit && !open ? duties.slice(0, limit) : duties;
    const hidden = duties.length - shown.length;

    return (
        <div className="flex flex-col items-start gap-1 whitespace-normal">
            <ol className="flex flex-col gap-1">
                {shown.map((duty, index) => (
                    // Two duties of one position can read the same; their place in the list tells them apart.
                    <li key={`${index}-${duty}`} className="flex gap-1.5">
                        {/* The number keeps its column while the duty beside it wraps. */}
                        <span className="text-muted-foreground shrink-0 tabular-nums">{index + 1}.</span>
                        <span className="min-w-0 break-words">{duty}</span>
                    </li>
                ))}
            </ol>
            {(hidden > 0 || open) && <MoreChip hidden={hidden} open={open} onToggle={() => setOpen(!open)} />}
        </div>
    );
}

/**
 * A department named as the org chart names it: the abbreviation leading, and
 * underneath it, quieter and smaller, what the abbreviation stands for.
 *
 * The chart and this table are the two places the structure itself is read, and
 * an abbreviation learned in one has to be the same thing in the other, so the
 * two lines stand in the same order and the same tone in both. Only the type is
 * this table's own: a column has a header naming the level above every cell in
 * it, so a cell needs no heading of its own the way a box on the chart does.
 *
 * Both lines belong to the link, so the label reads out whole and the full name
 * is no longer waiting behind a hover or in a copy kept for a screen reader.
 * Only the abbreviation takes the underline — two underlined lines would read as
 * two names rather than one.
 */
function DepartmentLink({ department }: { department: Department }) {
    // A department with no abbreviation is already named in full on the first
    // line, so there is nothing left to spell out underneath it. There are none
    // today; the payload has always allowed them.
    const spelled = department.full_name !== department.name;

    return (
        <Link
            href={route('departments.show', department.id)}
            className="group flex min-w-0 flex-col gap-0.5"
            // Two lines hold most of these names but not the longest, and a name
            // cut off at the second has to be readable without leaving the table.
            title={`Открыть: ${department.full_name}`}
        >
            <span className="group-hover:underline">{department.name}</span>
            {spelled && (
                // The columns are cut to the abbreviations, so a full name has to
                // wrap — and then to stop, at two lines, rather than make one
                // cell as tall as the block of rows beside it. What is cut off
                // the hover still says, and the department's own page spells it
                // out in its heading either way; a word longer than the column is
                // cut at the edge instead of spilling into the next one.
                //
                // The wrapping is asked for because the cell will not have it
                // otherwise: a cell covering a block of rows holds its content on
                // one line, and a cell of a single row is cut off at its own edge.
                <span className="text-muted-foreground line-clamp-2 text-[11px] leading-tight whitespace-normal">{department.full_name}</span>
            )}
        </Link>
    );
}

/**
 * The label of a cell that covers a block of rows, kept in view while the block
 * goes past.
 *
 * A department of twenty-one positions is three screens tall. Merged cells were
 * meant to say what a run of rows belongs to, and a name that has scrolled off
 * the top says it to nobody: half way down ДФКА the rows read as a list of job
 * titles again. So the name travels down its own cell and stops at the end of
 * it, which is the block's end — the next name takes over exactly where the next
 * block starts, and at no point is a name standing over rows it does not own.
 *
 * It comes to rest under the header rather than behind it: the strip of column
 * names is sticky too, and `--table-head` is how tall the table says it is. The
 * department's label and its unit's are in two columns side by side, so both can
 * be at rest at once without meeting — which is the path read across, «ДФКА»
 * beside «ОПР», over the rows it belongs to.
 *
 * A block of its own and a flow of its own: `flex` rather than an inline box,
 * which sticks unevenly, and `w-fit` so the box is no wider than the label it
 * holds. The label is two lines now — the abbreviation and the name spelled out
 * under it — which only makes the travelling box taller; it is still one box,
 * both lines move together, and it is held inside its own cell, so the wider of
 * the two columns' labels cannot lean into the other. The underline stays on the
 * abbreviation itself, which is the only line that takes one.
 */
function Sticking({ children }: { children: ReactNode }) {
    return (
        <span className="sticky flex w-fit" style={{ top: 'var(--table-head, 0px)' }}>
            {children}
        </span>
    );
}
/**
 * A position nobody has filed says so in as many words, and in the colour of
 * something to be dealt with rather than of something missing: a blank cell
 * reads as a page that failed to load, and these rows are the ones somebody has
 * to go and finish. It says it in both columns, because a column is read down
 * on its own and each of the two has to answer for itself.
 */
function Unfiled({ children }: { children: string }) {
    return (
        <StatusBadge tone="warning" title="Должность не закреплена ни за департаментом, ни за отделом">
            {children}
        </StatusBadge>
    );
}

/** What the phone row says under where the position sits. */
function dutiesLine(position: Position): string {
    if (position.duties.length > 0) return position.duties.join(' · ');

    return 'Обязанности не заполнены';
}

/**
 * «ДАС › ОА» — both steps of where the position sits, on the one line a phone
 * row has, written the way a department's own page writes a path. A position
 * filed in a department with no unit between is that department alone, and one
 * nobody has filed says so instead, in the colour of something to be dealt
 * with: that is how the unfiled ones are found on a phone at all.
 *
 * The full names the table's cells now carry under their abbreviations stay out
 * of here. This line is shared with the duties and cut at the edge of the
 * screen, so spelling two departments out would push the duties off it and often
 * itself as well; and a row on a phone is a link to the position's own page,
 * where the path is named in full with nothing beside it.
 */
function StructureLine({ position }: { position: Position }) {
    const { department, unit } = filing(position);

    if (department === null) return <span className="text-[#9A4A06] dark:text-[#F8C471]">без департамента и отдела</span>;

    return <span>{unit === null ? department.name : `${department.name} › ${unit.name}`}</span>;
}

/** The "⋯" at the end of a row, for whoever keeps the list. */
function RowActions({ position, onEdit, onDelete }: { position: Position; onEdit: () => void; onDelete: () => void }) {
    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    variant="ghost"
                    size="icon"
                    className="text-muted-foreground size-10 md:size-9 lg:size-8"
                    aria-label={`Действия: ${position.display_name}`}
                >
                    <Ellipsis className="size-5!" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="min-w-44">
                <DropdownMenuItem className="max-md:min-h-10 max-md:text-[15px]" onSelect={onEdit}>
                    <Pencil />
                    Изменить
                </DropdownMenuItem>
                <DropdownMenuItem
                    className="text-[#B42318] focus:text-[#B42318] max-md:min-h-10 max-md:text-[15px] dark:text-[#F7A19A] dark:focus:text-[#F7A19A] [&_svg]:text-current!"
                    onSelect={onDelete}
                >
                    <Trash2 />
                    Удалить…
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

interface EditorData {
    /** The picker writes strings; nothing chosen yet is an empty one. */
    department_id: string;
    name: string;
    duties: string[];
}

/**
 * Adding a position or changing one, asked in the order the answers depend on
 * each other: the department first, because the same name means a different job
 * in each of them and is only taken within one; then the name; then what the job
 * involves. The duties are entered the way a person's citizenships are — value
 * by value, each its own chip — and what other positions already say is offered
 * as suggestions, so the same duty is written the same way twice.
 */
function EditorDialog({
    position,
    suggestions,
    departments,
    onClose,
}: {
    position: Position | null;
    suggestions: string[];
    departments: Props['options']['departments'];
    onClose: () => void;
}) {
    const form = useForm<EditorData>({
        department_id: position?.department === null || position === null ? '' : String(position.department.id),
        name: position?.name ?? '',
        duties: position?.duties ?? [],
    });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();

        form.transform((data) => ({
            department_id: data.department_id === '' ? null : Number(data.department_id),
            name: data.name.trim(),
            duties: data.duties.map((name) => ({ name })),
        }));
        const options = { preserveScroll: true, onSuccess: onClose };

        if (position) form.put(route('positions.update', position.id), options);
        else form.post(route('positions.store'), options);
    };

    const errors = form.errors as Record<string, string | undefined>;

    // The server reports a line of the list by its place in it
    // ("duties.7.name"), and the list is one field here, so whichever line it
    // is about is said under that field.
    const dutyError = Object.entries(errors).find(([key, message]) => /^duties\.\d+(\.|$)/.test(key) && message !== undefined)?.[1];

    const dutyOptions = suggestions.map((duty) => ({ value: duty, label: duty }));

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-h-[90svh] overflow-y-auto sm:max-w-lg">
                <form onSubmit={submit} className="flex flex-col gap-5">
                    <DialogHeader>
                        <DialogTitle>{position ? 'Изменить должность' : 'Новая должность'}</DialogTitle>
                        <DialogDescription className="sr-only">Заполните поля и сохраните.</DialogDescription>
                    </DialogHeader>

                    <div className="grid content-start gap-2">
                        <Label htmlFor="position-department">Отдел</Label>
                        <SearchableSelect
                            id="position-department"
                            className="max-md:h-11"
                            value={form.data.department_id}
                            onChange={(value) => form.setData('department_id', value)}
                            options={departments.map((department) => ({
                                value: String(department.id),
                                label: department.name,
                                hint: department.full_name === department.name ? undefined : department.full_name,
                                // Indented by how deep it sits, as the employees list indents the same tree.
                                depth: department.depth,
                            }))}
                            placeholder="Выберите отдел"
                            searchPlaceholder="Отдел"
                            empty="Отдел не найден"
                            invalid={errors.department_id !== undefined}
                        />
                        <p className="text-muted-foreground text-[13px]">
                            Должность принадлежит одному отделу. Одно и то же название в разных отделах — это разные должности со своими
                            обязанностями.
                        </p>
                        <InputError message={errors.department_id} />
                    </div>

                    <div className="grid content-start gap-2">
                        <Label htmlFor="position-name">Название</Label>
                        <Input
                            id="position-name"
                            className="max-md:h-11"
                            autoFocus
                            value={form.data.name}
                            onChange={(event) => form.setData('name', event.target.value)}
                        />
                        <InputError message={errors.name} />
                    </div>

                    <div className="grid content-start gap-2">
                        <Label htmlFor="position-duties">Обязанности</Label>
                        <MultiSelect
                            numbered
                            id="position-duties"
                            creatable
                            options={dutyOptions}
                            value={form.data.duties}
                            onChange={(duties) => form.setData('duties', duties)}
                            placeholder="Не заполнены"
                            searchPlaceholder="Обязанность"
                        />
                        <p className="text-muted-foreground text-[13px]">
                            Всё, за что отвечает должность. По одной обязанности за раз: впишите её и нажмите Enter. Порядок строк здесь — это порядок
                            на странице должности. Кто из сотрудников что из этого ведёт, указывается в карточке сотрудника.
                        </p>
                        {/* The whole list can be complained about too ("duties"), and that
                            complaint is nobody's line in particular. */}
                        <InputError message={errors.duties ?? dutyError} />
                    </div>

                    <DialogFooter className="gap-2">
                        <Button type="button" variant="outline" className="max-md:h-11" onClick={onClose}>
                            Отмена
                        </Button>
                        <Button
                            type="submit"
                            className="max-md:h-11"
                            disabled={form.processing || form.data.department_id === '' || form.data.name.trim() === ''}
                        >
                            {form.processing && <LoaderCircle className="animate-spin" />}
                            Сохранить
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function DeleteDialog({ position, onClose }: { position: Position; onClose: () => void }) {
    const [processing, setProcessing] = useState(false);

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle className="break-words">Удалить должность «{position.display_name}»?</DialogTitle>
                    <DialogDescription asChild>
                        <div className="flex flex-col gap-1.5">
                            {position.employees_count > 0 ? (
                                <p>
                                    Должность снимется у {position.employees_count}{' '}
                                    {plural(position.employees_count, ['сотрудника', 'сотрудников', 'сотрудников'])}. Самих сотрудников это не
                                    затронет.
                                </p>
                            ) : (
                                <p>Сотрудников на этой должности нет.</p>
                            )}
                            <p>Вместе с должностью исчезнут и её обязанности. Отменить удаление нельзя.</p>
                        </div>
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter className="gap-2">
                    <Button type="button" variant="outline" className="max-md:h-11" onClick={onClose}>
                        Отмена
                    </Button>
                    <Button
                        type="button"
                        variant="destructive"
                        className="max-md:h-11"
                        disabled={processing}
                        onClick={() => {
                            setProcessing(true);
                            router.delete(route('positions.destroy', position.id), {
                                preserveScroll: true,
                                onFinish: () => setProcessing(false),
                                onSuccess: onClose,
                            });
                        }}
                    >
                        {processing && <LoaderCircle className="animate-spin" />}
                        Удалить
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

/** Nothing chosen in either funnel: the whole list. */
const NOTHING_CHOSEN: Narrowing = { department: [], unit: [] };

export default function PositionsIndex({ positions, options, canEdit }: Props) {
    const { view, pin, reorder } = useTableView(STORAGE_KEY, columnKeys, defaultView);
    const [query, setQuery] = useState('');
    const [sort, setSort] = useState<Sort>(DEFAULT_SORT);
    // The departments and the units the list is narrowed to, kept here beside
    // the search and the order, which this page has never asked the server for
    // either.
    const [chosen, setChosen] = useState<Narrowing>(NOTHING_CHOSEN);
    const [editing, setEditing] = useState<Position | 'new' | null>(null);
    const [deleting, setDeleting] = useState<Position | null>(null);

    const tableColumns = useMemo(() => buildColumns(options.departments), [options.departments]);
    const filters: FilterValues = { department: chosen.department, unit: chosen.unit };
    // A funnel reports only its own column, so what it says is laid over what
    // the other one is already set to.
    const applyFilter = (changes: FilterValues) => setChosen((current) => ({ ...current, ...(changes as Partial<Narrowing>) }));

    const narrowed = chosen.department.length > 0 || chosen.unit.length > 0;

    // A position is searched for by what it is called, by where it sits and by
    // what it does: a duty is often what somebody remembers of a job title they
    // cannot name, and the department is half of what a repeated name means.
    // Both steps of the structure answer, so «ДАС» finds the positions of its
    // units as well as its own.
    //
    // A holder's name used to answer here too, and no longer does: the names are
    // not on this screen any more, and a search that quietly matches something
    // invisible leaves somebody looking at rows with no sign of why they are
    // there. "Which position does this person hold?" is a question for the staff
    // list, which narrows by position, and for the card itself.
    const rows = useMemo(() => {
        const term = query.trim().toLowerCase();
        const kept = narrowed ? positions.filter((position) => inChosen(position, chosen)) : positions;

        const found = term
            ? kept.filter((position) => {
                  const { department, unit } = filing(position);
                  const where = [department?.name, department?.full_name, unit?.name, unit?.full_name];

                  return (
                      position.name.toLowerCase().includes(term) ||
                      where.some((name) => name?.toLowerCase().includes(term)) ||
                      position.duties.some((duty) => duty.toLowerCase().includes(term))
                  );
              })
            : kept;

        return [...found].sort((a, b) => compare(a, b, sort));
    }, [positions, query, sort, chosen, narrowed]);

    /** Every duty written anywhere, offered to the next position to be entered. */
    const suggestions = useMemo(
        () => [...new Set(positions.flatMap((position) => position.duties))].sort((a, b) => collator.compare(a, b)),
        [positions],
    );

    /**
     * Whether a department and a unit are drawn as one cell over the rows they
     * hold, rather than as a cell apiece.
     *
     * A merged cell is a claim about the rows it covers, and the claim holds only
     * in the order the list opens in: there the structure is read down, a
     * department's own positions and all of its units stand inside its own run,
     * and a cell can reach across twenty-one rows and be telling the truth about
     * every one of them. Put in order by «Отдел» the units stand together but
     * their departments are scattered among them; by «Должность» nothing stands
     * together at all.
     *
     * So the table is merged exactly while it is at rest, and any sorting at all
     * flattens it. That is a sharper line than "while the rows happen to be in
     * runs" — «Департамент» upside down keeps its runs too — but it is the line
     * the viewer can read everywhere else on the page at the same time: the
     * header arrow, the count on the phone's sort button and the «Сбросить всё»
     * it offers all say the same one thing, that the list has been put in an
     * order of somebody's own. A table that was merged while claiming to be
     * sorted would leave all four of them disagreeing.
     *
     * The search and the funnels change nothing here: they take rows away and
     * leave the order alone, so whatever survives is still in runs.
     */
    const grouped = !isSorted(sort, DEFAULT_SORT);
    const spans = useMemo(() => spansOf(rows), [rows]);

    /**
     * How far each cell of the two structure columns reaches. One row, or none at
     * all where the cell above has already covered this row; the other two
     * columns are a cell per row whatever the order, since a position and its
     * duties are its own.
     */
    const rowSpan = (column: ColumnDef, position: Position) => {
        if (!grouped || (column.key !== 'department' && column.key !== 'unit')) return 1;

        return spans[column.key].get(position.id) ?? 0;
    };

    /**
     * What the line above the table says once the cells have come apart: the
     * column the rows are in order by, and the order that merges them again,
     * named exactly as the header menu names it — «По возрастанию» is the wording
     * a viewer is about to read there. Both columns are read off the table rather
     * than written out, so a header renamed is this sentence renamed with it;
     * every key the table sorts by is a column of it, and the one the list opens
     * in is the first.
     */
    const shapeNote = grouped
        ? null
        : `Порядок — по «${columns.find((column) => column.key === sort.key)!.label}», а не по структуре: ` +
          `департамент и отдел названы в каждой строке. Ячейки снова объединятся в исходном порядке — ` +
          `по «${columns[0].label}», по возрастанию.`;

    const cell = (column: ColumnDef, position: Position) => {
        const { department, unit } = filing(position);
        // Merged, the name of a cell stays in view while the rows it covers go
        // past. Apart, a cell is one row tall and there is nothing for a name to
        // outlast, so it is drawn where it stands.
        const label = (named: ReactNode) => (grouped ? <Sticking>{named}</Sticking> : named);

        switch (column.key) {
            // Both of these are drawn once per cell, and a cell is a block of rows
            // while the table is grouped and a single row otherwise. Which rows a
            // cell covers is `rowSpan`'s to say; what is in it does not change
            // with it, so there is nothing here that has to know.
            case 'department':
                return label(department === null ? <Unfiled>Без департамента</Unfiled> : <DepartmentLink department={department} />);
            case 'unit':
                // A department may hold positions with no unit between — ДУЧР has
                // no units at all — and that is nothing to fix, so the cell reads
                // as an empty value rather than as the amber beside it. Grouped,
                // that one «—» stands over the whole run of the department's own
                // positions, which is how the sketch this was drawn from reads.
                // One with no department has no unit either, and says so.
                if (unit !== null) return label(<DepartmentLink department={unit} />);

                return label(department === null ? <Unfiled>Без отдела</Unfiled> : <Empty />);
            case 'duties':
                return <Duties duties={position.duties} limit={DUTIES_SHOWN} />;
            default:
                return (
                    <Link
                        href={route('positions.show', position.id)}
                        // Brand colour and an underline on hover: the app's mark of a
                        // link. A job title is the whole point of the row, so it wraps
                        // rather than being cut at the edge of the column. The hover
                        // names the department too, for whoever hid those columns.
                        className="text-brand-strong font-medium whitespace-normal hover:underline dark:text-[#C5E27A]"
                        title={`Открыть должность: ${position.display_name}`}
                    >
                        {position.name}
                    </Link>
                );
        }
    };

    // The box reads what is on screen, and nothing else: all four columns.
    const searchHint = 'Поиск по названию, департаменту, отделу и обязанностям';

    /**
     * An empty list has to say which of the two reasons emptied it. A department
     * without a single position of its own is the likelier one, and "nothing
     * found" would leave somebody retyping a search that was never the trouble,
     * so the narrowing answers for itself — by name while there is one name to
     * give.
     */
    const noneHere = narrowed && !positions.some((position) => inChosen(position, chosen));
    const empty = positions.length === 0 ? 'Должностей пока нет.' : noneHere ? emptyMessage(chosen, options.departments) : 'Ничего не найдено.';

    return (
        <AppLayout breadcrumbs={breadcrumbs} fitViewport>
            <Head title="Должности" />

            <div className="flex flex-1 flex-col gap-4 p-3 max-md:gap-3 md:min-h-0 md:px-5 md:py-4">
                {/* The phone's top bar already carries the page's name. */}
                <h1 className="text-xl font-semibold tracking-tight max-md:sr-only">Должности</h1>

                <div className="flex flex-wrap items-center gap-2 md:-mb-2">
                    {/* text-base on a phone: a smaller font makes iOS zoom in on focus. */}
                    <label className={searchBox}>
                        <Search className="size-4 shrink-0" />
                        <span className="sr-only">{searchHint}</span>
                        <input
                            type="search"
                            value={query}
                            onChange={(event) => setQuery(event.target.value)}
                            placeholder={searchHint}
                            className="text-foreground min-w-0 flex-1 bg-transparent text-sm outline-hidden max-md:text-base"
                        />
                    </label>

                    <MobileListTools
                        columns={tableColumns}
                        filters={filters}
                        onFilter={applyFilter}
                        sort={sort}
                        sortable={sortable}
                        onSort={(key, direction) => setSort(direction ? { key, direction } : cycleSort(sort, key, DEFAULT_SORT))}
                        // The opening order is a column of the table now, and the
                        // sheet lists every column it can be put in order by, so
                        // there is no name left for this to give it.
                        defaultSort={DEFAULT_SORT}
                        // One visit clears both, so neither is left narrowing a list
                        // somebody has just put back in order.
                        onReset={() => {
                            setSort(DEFAULT_SORT);
                            setChosen(NOTHING_CHOSEN);
                        }}
                        className="max-md:bg-card max-md:rounded-xl max-md:border-transparent max-md:shadow-none"
                    />

                    {canEdit && (
                        // On a phone the round button over the tab bar takes its place.
                        <Button className="h-10 max-md:hidden lg:h-8" onClick={() => setEditing('new')}>
                            <Plus />
                            Добавить должность
                        </Button>
                    )}
                </div>

                {/* Said once, above the table, and only once the order has pulled
                    the cells apart: the table changed shape under somebody's
                    hands, and nobody should have to work out that the press that
                    did it was a sort arrow a line above. It names the column the
                    rows are in order by and the column that merges them again, so
                    the way back is the sentence itself.

                    Nothing above a grouped table: a cell standing over the rows it
                    holds explains itself, and a line that is always there is a
                    line nobody reads. And nothing on a phone, which has no columns
                    and so no cells to merge. */}
                {shapeNote && <p className="text-muted-foreground text-[13px] max-md:hidden">{shapeNote}</p>}

                <DataTable
                    columns={tableColumns}
                    rows={rows}
                    rowKey={(position) => position.id}
                    renderCell={cell}
                    // The structure drawn as the structure: one cell per
                    // department over the rows it holds, one per unit inside it.
                    // The table knows nothing about blocks and does not need to —
                    // it asks how far a cell reaches and draws it that far.
                    rowSpan={rowSpan}
                    // Lines down the columns as well as between the rows. A cell
                    // covering twenty-one rows needs an edge sideways or it is
                    // only a name with a lot of air under it, and the four
                    // columns of this table are four kinds of thing rather than
                    // four facts about one. They stay drawn whichever column the
                    // table is ordered by: a column boundary is true in every
                    // order, and a table that changed two things at once on a
                    // press of a header would be harder to follow, not easier.
                    gridlines
                    sort={sort}
                    sortable={sortable}
                    onSort={(key, direction) => setSort(direction ? { key, direction } : cycleSort(sort, key, DEFAULT_SORT))}
                    filters={filters}
                    onFilter={applyFilter}
                    // Both lists of departments spell a full name out beside an
                    // abbreviation, which needs more room than a short label.
                    wideFilter={(column) => column.key === 'department' || column.key === 'unit'}
                    view={view}
                    onPin={pin}
                    reorderable
                    onReorder={reorder}
                    lockedKey="name"
                    actions={
                        canEdit
                            ? (position) => (
                                  <RowActions position={position} onEdit={() => setEditing(position)} onDelete={() => setDeleting(position)} />
                              )
                            : undefined
                    }
                    empty={empty}
                    mobileRow={(position) => (
                        <MobileRow
                            href={route('positions.show', position.id)}
                            title={position.name}
                            // There are no columns on a phone, so the line under the
                            // name opens with where the position sits: twenty of these
                            // names repeat, and a row saying «Аналитик» alone could be
                            // any of them. It goes first because the line is cut at the
                            // edge of the screen and two abbreviations are short — the
                            // duties after them are what gives way, and the page the row
                            // opens has them in full, with the holders beside them.
                            //
                            // Nothing is left out of it, however the rows are ordered.
                            // The cells the table merges belong to a column read down;
                            // a phone row is read on its own, and «ДАС › ОА» repeated
                            // down a list of them is what tells one run from the next.
                            // The order is the same structural one the table opens in,
                            // so those runs are there to be read.
                            subtitle={
                                <>
                                    <StructureLine position={position} />
                                    {' · '}
                                    {dutiesLine(position)}
                                </>
                            }
                            trailing={
                                canEdit ? (
                                    <RowActions position={position} onEdit={() => setEditing(position)} onDelete={() => setDeleting(position)} />
                                ) : undefined
                            }
                        />
                    )}
                />
            </div>

            {canEdit && <MobileFab onClick={() => setEditing('new')} label="Добавить должность" />}
            {editing && (
                <EditorDialog
                    key={editing === 'new' ? 'new' : editing.id}
                    position={editing === 'new' ? null : editing}
                    suggestions={suggestions}
                    departments={options.departments}
                    onClose={() => setEditing(null)}
                />
            )}
            {deleting && <DeleteDialog position={deleting} onClose={() => setDeleting(null)} />}
        </AppLayout>
    );
}
