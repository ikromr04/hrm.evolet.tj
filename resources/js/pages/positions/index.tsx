import {
    cycleSort,
    DataTable,
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
import CompanyStructureLayout from '@/layouts/company-structure-layout';
import { plural } from '@/lib/plural';
import { cn } from '@/lib/utils';
import { Link, router, useForm } from '@inertiajs/react';
import { Ellipsis, LoaderCircle, Pencil, Plus, Search, Trash2 } from 'lucide-react';
import { useMemo, useState, type FormEventHandler } from 'react';

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
     * What the company grades the job by — «Менеджер» and «Менеджер 1» — the two
     * fields «01 ОБЩАЯ ИНФОРМАЦИЯ» of «Профиль должности» opens with. Nothing
     * here can work either of them out, so both are typed in this page's own
     * editor and both are empty until somebody has.
     */
    category: string | null;
    grade: string | null;
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
 * the unit inside it, the position itself, and what the company grades it by.
 * That is the order somebody says it out loud in — «в ДАС, в отделе аналитики,
 * аналитик» — and the two departments stand together because a position means
 * little without them: twenty of these names repeat, and «Аналитик» alone
 * answers nothing.
 *
 * The category and the grade follow the name because they qualify it — they are
 * the company's own answer to what the job is worth and where in its ladder it
 * stands. They are here at all because this table is the one place every
 * position is read beside every other: a value somebody can type in the editor
 * and never find in the list is one nobody will trust.
 *
 * Neither who holds a position nor what it answers for is here any more. Those
 * were the two widest columns, and each of them said what the position's own
 * page says better — the holders with the duties each of them carries beside
 * their name, the duties themselves numbered and whole. What is left is five
 * short values, so the room the two of them took goes to the name: it is the
 * column that fills the table now, and it wraps.
 */
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
    // instead of being cut. Said outright, because otherwise the last of the
    // columns would take it — and that is «Грайд», a word and a number handed
    // the whole of the gap, with the rest of the table squeezed to its left.
    { key: 'name', label: 'Должность', width: 245, fill: true },
    // Narrow on purpose: a grade is a word and a number, and a category is a
    // word more often than «Руководитель среднего звена». The longest of them
    // wraps rather than widening the column — the name beside them has the
    // better claim on the room. Each is cut to its own header rather than below
    // it: unlike «Департамент» these two carry no funnel, so the label and the
    // two controls after it are all the width has to hold, and a column headed
    // «Гра…» would be a riddle.
    { key: 'category', label: 'Категория', width: 175 },
    { key: 'grade', label: 'Грайд', width: 145 },
];

const columnKeys = columns.map((column) => column.key);

/**
 * Every column of the table, each of them a value rows can be put in order by.
 * The two graded ones are read down to see who sits where in the ladder, which
 * is the whole reason a category is worth comparing across departments. Written
 * out rather than taken from the columns themselves, so that a column added
 * later has to say for itself whether it is a thing that can be sorted at all.
 */
const sortable = ['department', 'unit', 'name', 'category', 'grade'];

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
    // The same rule once more, and for the same reason: a position nobody has
    // graded yet sorts before every word there is, so one press of the header
    // gathers the rows somebody still has to fill in at the top of the list
    // instead of burying them under the ones that are done.
    const byGraded = (key: 'category' | 'grade') => () => collator.compare(a[key] ?? '', b[key] ?? '');

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
    // A grade is shared by positions all over the company, so a great many rows
    // tie on it; they read down the structure they arrived in, and namesakes
    // within it by name, rather than in whatever order the sort left them.
    if (sort.key === 'category' || sort.key === 'grade') {
        return sign * byGraded(sort.key)() || byDepartment() || byUnit() || byName();
    }

    // Namesakes stand in the order the columns before them already read in.
    return sign * byName() || byDepartment() || byUnit();
}

/** A value the row has not got, written the way the rest of the project writes it. */
function Empty() {
    return <span className="text-muted-foreground">—</span>;
}

/**
 * What the company grades the job by, as it was typed: «Менеджер» in one column
 * and «Менеджер 1» in the next.
 *
 * Nothing in the application can work either value out — two positions in one
 * department sit in different grades — so a position nobody has graded yet is
 * an empty cell like any other and says so the way the rest of the table does.
 * It is not the amber of the unfiled rows: a grade that has not been decided is
 * not a row that was filed wrongly.
 *
 * The cell wraps rather than cutting the value off. The columns are narrow, and
 * «Руководитель среднего звена» is one of the categories the sheet prints
 * whole; a word longer than the column breaks instead of leaning into the one
 * beside it.
 */
function Graded({ value }: { value: string | null }) {
    if (value === null || value === '') return <Empty />;

    return <span className="break-words whitespace-normal">{value}</span>;
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
    // line, so there is nothing left to spell out underneath it — and that one
    // line is then as long as «Отдел Развития Продуктового Портфеля», which is
    // the whole of what the cell holds rather than three letters.
    const spelled = department.full_name !== department.name;

    return (
        <Link
            href={route('departments.show', department.id)}
            className="group flex min-w-0 flex-col gap-0.5"
            // Two lines hold most of these names but not the longest, and a name
            // cut off at the second has to be readable without leaving the table.
            title={`Открыть: ${department.full_name}`}
        >
            {/* An abbreviation is three letters and needs nothing said about it.
                A name standing here on its own is a whole name, and it is given
                what the line below is given: it wraps, and stops at the second
                line with an ellipsis rather than growing the row. */}
            <span className={cn('group-hover:underline', !spelled && 'line-clamp-2 break-words whitespace-normal')}>{department.name}</span>
            {spelled && (
                // The columns are cut to the abbreviations, so a full name has to
                // wrap — and then to stop, at two lines, rather than make every
                // row of a department as tall as its name. What is cut off the
                // hover still says, and the department's own page spells it out
                // in its heading either way; a word longer than the column breaks
                // rather than pushing the cell wider.
                //
                // The wrapping is asked for because the cell will not have it
                // otherwise: a cell is cut off at its own edge unless its content
                // says it wraps.
                <span className="text-muted-foreground line-clamp-2 text-[11px] leading-tight break-words whitespace-normal">
                    {department.full_name}
                </span>
            )}
        </Link>
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
    /**
     * The two graded fields as they are typed. An empty box is an answer rather
     * than a question left out: it is sent as it stands and clears the value,
     * which is how a grade entered by mistake is taken back off a position.
     */
    category: string;
    grade: string;
    duties: string[];
}

/**
 * Adding a position or changing one, asked in the order the answers depend on
 * each other: the department first, because the same name means a different job
 * in each of them and is only taken within one; then the name; then what the job
 * involves. The duties are entered the way a person's citizenships are — value
 * by value, each its own chip — and what other positions already say is offered
 * as suggestions, so the same duty is written the same way twice.
 *
 * The category and the grade stand between the name and the duties, which is
 * the order «01 ОБЩАЯ ИНФОРМАЦИЯ» of the profile prints them in and the order
 * the table reads them in as far as it goes. Two boxes side by side rather than
 * one under the other: they are a pair read across — «Менеджер», «Менеджер 1» —
 * and neither is more than a few words.
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
        category: position?.category ?? '',
        grade: position?.grade ?? '',
        duties: position?.duties ?? [],
    });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();

        form.transform((data) => ({
            department_id: data.department_id === '' ? null : Number(data.department_id),
            name: data.name.trim(),
            // Sent on both the new position and the changed one, and sent even
            // when the box is empty: an empty box means "not graded", and the
            // server reads it as nothing. Left out instead, it would mean
            // "leave whatever is there alone" and a grade could never be
            // cleared.
            category: data.category.trim(),
            grade: data.grade.trim(),
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
                        {/* Side by side on anything wider than a phone, one
                            under the other on one; `content-start` holds both
                            boxes level when only one is complained about. */}
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="grid content-start gap-2">
                                <Label htmlFor="position-category">Категория</Label>
                                <Input
                                    id="position-category"
                                    className="max-md:h-11"
                                    value={form.data.category}
                                    onChange={(event) => form.setData('category', event.target.value)}
                                />
                                <InputError message={errors.category} />
                            </div>

                            <div className="grid content-start gap-2">
                                <Label htmlFor="position-grade">Грайд</Label>
                                <Input
                                    id="position-grade"
                                    className="max-md:h-11"
                                    value={form.data.grade}
                                    onChange={(event) => form.setData('grade', event.target.value)}
                                />
                                <InputError message={errors.grade} />
                            </div>
                        </div>
                        {/* Neither is required, and nothing here can guess
                            them: the company writes these words itself and
                            plenty of the positions have not been graded yet. */}
                        <p className="text-muted-foreground text-[13px]">
                            Как должность оценена в профиле должности: например «Менеджер» и «Менеджер 1». Можно оставить пустыми — тогда в списке
                            будет прочерк.
                        </p>
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
                  // Both graded fields answer too, now that both are on screen:
                  // «Менеджер 1» is as likely a thing to go looking for as a
                  // job title, and it is the one way to pull a whole grade out
                  // of the list without a funnel of its own.
                  const graded = [position.category, position.grade];

                  return (
                      position.name.toLowerCase().includes(term) ||
                      where.some((name) => name?.toLowerCase().includes(term)) ||
                      graded.some((value) => value?.toLowerCase().includes(term)) ||
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
                return department === null ? <Unfiled>Без департамента</Unfiled> : <DepartmentLink department={department} />;
            case 'unit':
                // A department may hold positions with no unit between — ДУЧР has
                // no units at all — and that is nothing to fix, so the cell reads
                // as an empty value rather than as the amber beside it. One with
                // no department has no unit either, and says so.
                if (unit !== null) return <DepartmentLink department={unit} />;

                return department === null ? <Unfiled>Без отдела</Unfiled> : <Empty />;
            // Two values of the job itself rather than of where it sits, which is
            // all that sets them apart from the two columns before: every column
            // of this table is a cell per row.
            case 'category':
                return <Graded value={position.category} />;
            case 'grade':
                return <Graded value={position.grade} />;
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

    // Every column of the table, and the duties besides. They are no longer a
    // column of it, but they are not invisible either: the phone's rows name
    // them under the position, and a duty is often the only thing somebody
    // remembers of a job title they cannot name — which is the one search here
    // that answers a question none of the funnels can. A row found by one is
    // still a row to follow rather than a puzzle: it opens the page that prints
    // the duties in full.
    const searchHint = 'Поиск по названию, департаменту, отделу, категории, грайду и обязанностям';

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
                    defaultSort={DEFAULT_SORT}
                    // No header carries the opening order — it is the structure
                    // read down, which is no one column — so the sheet names it
                    // above the columns, and the three presses come back to it.
                    defaultSortLabel={STRUCTURE_LABEL}
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
                        title={position.name}
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
                        //
                        // The category and the grade stay off this line, for the
                        // reason the full names do: it is shared with the duties
                        // and cut at the edge of the screen, and two more words
                        // in front of them would push the duties off it. The
                        // sheet behind the button still sorts by either of them,
                        // the search box still finds them, and the page this row
                        // opens prints both in the profile table at the top.
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
                <EditorDialog
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
