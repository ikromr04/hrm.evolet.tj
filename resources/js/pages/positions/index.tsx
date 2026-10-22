import {
    cycleSort,
    DataTable,
    MobileListTools,
    MobileRow,
    TableMemoryButton,
    useTableMemory,
    useTableView,
    type ColumnDef,
    type FilterDef,
    type FilterValues,
    type Sort,
    type ViewState,
} from '@/components/data-table';
import { DepartmentLink } from '@/components/department-name';
import { searchBox } from '@/components/directory-manager';
import { MobileFab } from '@/components/mobile-fab';
import { PageActions } from '@/components/page-actions';
import { PositionEditor } from '@/components/position-editor';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import CompanyStructureLayout from '@/layouts/company-structure-layout';
import { plural } from '@/lib/plural';
import { Link, router } from '@inertiajs/react';
import { Ellipsis, LoaderCircle, Pencil, Plus, Search, Trash2 } from 'lucide-react';
import { useMemo, useState } from 'react';

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
    /**
     * Whether whoever holds this heads the department it sits in. Shown beside
     * the name on the list, because a flag that only one dialog mentions is one
     * nobody knows is there.
     */
    is_head: boolean;
    /** Where the position sits; null for the few nobody has filed yet. */
    department: Filing | null;
    /**
     * What the job is, a short line per duty, in the order they were entered.
     * One list: the position belongs to one department, so there is nothing
     * inside it to tell apart.
     *
     * The table has no column for them any more: a duty is a sentence, and a
     * column of sentences standing beside columns of single words read as a wall
     * of text rather than as a value. They are still what the phone's row says
     * under a name, what the search box finds a position by, what the editor
     * offers the next position as suggestions, and what the position's own page
     * prints in full.
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

const STORAGE_KEY = 'positions.table.view.v2';

/**
 * The order the list opens in, and the one a third press on a header and "reset"
 * both bring back: the structure itself, read down — the department, the
 * positions filed on the department itself, then its units and the positions in
 * each.
 *
 * It is an order of its own rather than the «Департамент» column ascending,
 * though the two put the rows in the same sequence and draw the same table down
 * to the last line. What differs is what the page is claiming: at rest no header
 * carries an arrow, there is nothing to reset and the phone's sheet shows
 * «Структура»; sorted by the column, the arrow is on it and the reset is on
 * offer. Keeping the two apart is what gives every header its three presses —
 * up, down, and back to the order the list came in — instead of «Департамент»
 * alone toggling between two.
 *
 * No column of the table carries it, so the phone's sheet names it: «Структура».
 *
 * The list used to open newest first, as the staff list does. That order told a
 * reader nothing here: the names repeat — twenty of them — and «Аналитик» above
 * «Дизайнер» above «Аналитик» in the order somebody happened to type them is a
 * list one can only search. The structure is what the columns are for, and it is
 * the order in which the whole of it is read down at once. Every row names its
 * own department, so the name repeats all the way down a department's rows; that
 * is what makes the order worth opening in, since «ДАС» seventeen times running
 * is a block the eye can take in and seventeen scattered «ДАС» are not.
 */
const STRUCTURE = 'structure';

/** What the phone's sheet calls that order, there being no header to carry it. */
const STRUCTURE_LABEL = 'Структура';

const DEFAULT_SORT: Sort = { key: STRUCTURE, direction: 'asc' };

/** Stands for "nothing there at all" where a department's id would stand. */
const NONE = 'none';

const collator = new Intl.Collator('ru');

/**
 * The row reads along the structure and then down into the job: the department,
 * the unit inside it, and the position itself. That is the order somebody says
 * it out loud in — «в ДАС, в отделе аналитики, аналитик» — and the two
 * departments stand together because a position means little without them:
 * twenty of these names repeat, and «Аналитик» alone answers nothing.
 *
 * Neither who holds a position nor what it answers for is here any more. Those
 * were the two widest columns, and each of them said what the position's own
 * page says better — the holders with the duties each of them carries beside
 * their name, the duties themselves numbered and whole. What is left is where
 * the position sits and what it is called, so all the room goes to the name: it
 * is the column that fills the table, and it wraps.
 */
/** What the one text filter looks through, said in full for whoever opens it. */
const SEARCH_HINT = 'Поиск по названию, департаменту, отделу и обязанностям';

const columns: ColumnDef[] = [
    // Both are cut to their headers: «Департамент» has to stand beside its own
    // funnel and menu without either leaning into the column next door. The
    // abbreviation each cell leads with fits that easily, and the full name under
    // it does not always — it wraps to two lines and is cut there. Widening these
    // two to hold the longest name would take the room from the job title, which
    // is what the row is about; whoever wants the whole name has the hover and
    // the department's own page.
    { key: 'department', label: 'Департамент', width: 215 },
    { key: 'unit', label: 'Отдел', width: 180 },
    // The column that takes whatever room the others leave, and the width is the
    // floor it fills up from rather than what it is drawn at. A job title is the
    // point of the row and the one value here that is glad of the room: it wraps
    // instead of being cut. Said outright rather than left to be the last column
    // standing, because the three of them can be dragged into any order and the
    // name is the one to hand the gap to wherever it ends up.
    //
    // The funnel here is the search this page used to carry above the table:
    // it answers by the job title, by either step of the structure and by the
    // duties, which is what the box did, and it stands on the column the
    // reader is looking for a name in.
    {
        key: 'name',
        label: 'Должность',
        width: 245,
        fill: true,
        filter: { type: 'text', param: 'q', placeholder: SEARCH_HINT },
    },
];

const columnKeys = columns.map((column) => column.key);

/**
 * Every column of the table, each of them a value rows can be put in order by.
 * Written out rather than taken from the columns themselves, so that a column
 * added later has to say for itself whether it is a thing that can be sorted at
 * all.
 */
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

    // The structure read down — the department, its own positions, then the units
    // within it and the positions in each — and the «Департамент» column's own
    // order, which is the same comparison: that column is the structure's first
    // step, and its ties are broken by the step after it. The two draw the same
    // table, and which of them the list is in is read off the key rather than
    // off the rows: one carries an arrow on the header and a reset, the other is
    // simply where the list opens.
    if (sort.key === STRUCTURE || sort.key === 'department') return sign * byDepartment() || sign * byUnit() || byName();
    // By the unit the units stand together, but their departments are scattered
    // among them: a department holds units whose names begin anywhere.
    if (sort.key === 'unit') return sign * byUnit() || byDepartment() || byName();

    // Namesakes stand in the order the columns before them already read in.
    return sign * byName() || byDepartment() || byUnit();
}

/** A value the row has not got, written the way the rest of the project writes it. */
function Empty() {
    return <span className="text-muted-foreground">—</span>;
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
    const { view, setView, pin, reorder } = useTableView(STORAGE_KEY, columnKeys, defaultView);
    // The columns as this viewer arranged them, kept by a press rather than
    // behind their back. The list itself is narrowed in the page rather than in
    // the address bar, so there is no query to keep beside them.
    const memory = useTableMemory({ viewKey: STORAGE_KEY, columnKeys, view, setView, fallback: defaultView });
    const [query, setQuery] = useState('');
    const [sort, setSort] = useState<Sort>(DEFAULT_SORT);
    // The departments and the units the list is narrowed to, kept here beside
    // the search and the order, which this page has never asked the server for
    // either.
    const [chosen, setChosen] = useState<Narrowing>(NOTHING_CHOSEN);
    const [editing, setEditing] = useState<Position | 'new' | null>(null);
    const [deleting, setDeleting] = useState<Position | null>(null);

    const tableColumns = useMemo(() => buildColumns(options.departments), [options.departments]);
    const filters: FilterValues = { department: chosen.department, unit: chosen.unit, q: query };
    // A funnel reports only its own column, so what it says is laid over what
    // the other one is already set to. The search is a filter like the rest,
    // and the one that is not part of the narrowing.
    const applyFilter = (changes: FilterValues) => {
        if ('q' in changes) {
            setQuery(typeof changes.q === 'string' ? changes.q : '');
        }

        const narrowing = Object.fromEntries(Object.entries(changes).filter(([key]) => key !== 'q'));
        setChosen((current) => ({ ...current, ...(narrowing as Partial<Narrowing>) }));
    };

    const narrowed = chosen.department.length > 0 || chosen.unit.length > 0;

    // A position is searched for by what it is called, by where it sits and by
    // what it does: a duty is often what somebody remembers of a job title they
    // cannot name, and the department is half of what a repeated name means.
    // Both steps of the structure answer, so «ДАС» finds the positions of its
    // units as well as its own.
    //
    // A holder's name used to answer here too, and no longer does. The duties,
    // whose column has gone the same way, still answer, and the difference is
    // whose the value is: a duty belongs to the position, is named on every
    // phone row and is printed whole on the page a row opens, so a match is
    // something the reader can go and see. A holder is a row in another list
    // altogether — "which position does this person hold?" is a question for the
    // staff list, which narrows by position, and for the card itself.
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

    const cell = (column: ColumnDef, position: Position) => {
        const { department, unit } = filing(position);

        switch (column.key) {
            // Every row names its own department and its own unit, the way every
            // other column answers for its own row. The names repeat all the way
            // down a department's rows, and a row read on its own — found by the
            // search, or sat on half way down the list — says where it sits
            // without anything above it having to be looked up.
            case 'department':
                return department === null ? (
                    <Unfiled>Без департамента</Unfiled>
                ) : (
                    <DepartmentLink id={department.id} name={department.name} full={department.full_name} />
                );
            case 'unit':
                // A department may hold positions with no unit between — ДУЧР has
                // no units at all — and that is nothing to fix, so the cell reads
                // as an empty value rather than as the amber beside it. One with
                // no department has no unit either, and says so.
                if (unit !== null) return <DepartmentLink id={unit.id} name={unit.name} full={unit.full_name} />;

                return department === null ? <Unfiled>Без отдела</Unfiled> : <Empty />;
            default:
                return (
                    <span className="flex items-start gap-1.5">
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
                    </span>
                );
        }
    };

    // Every column of the table, and the duties besides. They are no longer a
    // column of it, but they are not invisible either: the phone's rows name
    // them under the position, and a duty is often the only thing somebody
    // remembers of a job title they cannot name — which is the one search here
    // that answers a question none of the funnels can. A row found by one is
    // still a row to follow rather than a puzzle: it opens the page that prints
    // the duties in full.
    const searchHint = SEARCH_HINT;

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
        <CompanyStructureLayout>
            {canEdit && (
                <PageActions>
                    <Button className="h-8" onClick={() => setEditing('new')}>
                        <Plus />
                        Добавить должность
                    </Button>
                </PageActions>
            )}

            {/*
             * The phone's own line: there the table is a list of cards with no
             * headers to hang a funnel on, so the box and the sheet of tools
             * stand above it. A desktop reads both off the headers — the search
             * is the funnel on «Должность» — and the button that adds one sits
             * at the end of the line of tabs, where this section keeps it.
             */}
            <div className="flex flex-wrap items-center gap-2 md:hidden">
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
                    defaultSort={DEFAULT_SORT}
                    // No header carries the opening order — it is the structure
                    // read down, which is no one column — so the sheet names it
                    // above the columns, and the three presses come back to it.
                    defaultSortLabel={STRUCTURE_LABEL}
                    className="max-md:bg-card max-md:rounded-xl max-md:border-transparent max-md:shadow-none"
                />
            </div>

            {/* Only while there is something to say, and only where there is room
                to say it: the phone has no pins to keep. */}
            {memory.offered && (
                <div className="flex justify-end max-md:hidden">
                    <TableMemoryButton memory={memory} />
                </div>
            )}

            <DataTable
                columns={tableColumns}
                rows={rows}
                rowKey={(position) => position.id}
                renderCell={cell}
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
                        ? (position) => <RowActions position={position} onEdit={() => setEditing(position)} onDelete={() => setDeleting(position)} />
                        : undefined
                }
                empty={empty}
                mobileRow={(position) => (
                    <MobileRow
                        href={route('positions.show', position.id)}
                        title={<span className="truncate">{position.name}</span>}
                        // There are no columns on a phone, so the line under the
                        // name opens with where the position sits: twenty of these
                        // names repeat, and a row saying «Аналитик» alone could be
                        // any of them. It goes first because the line is cut at the
                        // edge of the screen and two abbreviations are short — the
                        // duties after them are what gives way, and the page the row
                        // opens has them in full, with the holders beside them.
                        //
                        // It is on every row, as it is in every row of the table:
                        // «ДАС › ОА» repeated down a list is what tells one run of
                        // rows from the next, and the order is the same structural
                        // one the table opens in, so those runs are there to read.
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

            {canEdit && <MobileFab onClick={() => setEditing('new')} label="Добавить должность" />}
            {editing && (
                <PositionEditor
                    key={editing === 'new' ? 'new' : editing.id}
                    position={editing === 'new' ? null : editing}
                    suggestions={suggestions}
                    departments={options.departments}
                    onClose={() => setEditing(null)}
                />
            )}
            {deleting && <DeleteDialog position={deleting} onClose={() => setDeleting(null)} />}
        </CompanyStructureLayout>
    );
}
