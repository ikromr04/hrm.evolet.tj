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
import { PersonLink } from '@/components/person-link';
import { SearchableSelect } from '@/components/searchable-select';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { peopleLabel } from '@/lib/employee';
import { plural } from '@/lib/plural';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { Ellipsis, LoaderCircle, Pencil, Plus, Search, Trash2 } from 'lucide-react';
import { useMemo, useState, type FormEventHandler } from 'react';

interface Person {
    id: number;
    /** "Фамилия Имя" */
    name: string;
}

interface Department {
    /** The abbreviation where the department has one, the full name otherwise. */
    name: string;
    /** What that abbreviation stands for, spelled out. */
    full_name: string;
    id: number;
}

/**
 * The duties one department adds to the position, which nobody holding it
 * elsewhere has. An analyst in one department does not do an analyst's work in
 * another, and this is how far they differ.
 */
interface DepartmentDuties {
    department: Department;
    duties: string[];
}

interface Position {
    id: number;
    name: string;
    /** When somebody added it: what the list's own opening order reads, newest first. */
    created_at: string | null;
    /**
     * What the job is wherever it is held, a short line per duty, in the order
     * they were entered.
     */
    duties: string[];
    /** The same again per department, for the departments that add work of their own. */
    department_duties: DepartmentDuties[];
    /** Working employees who hold the position. */
    employees_count: number;
    /**
     * Those same employees by name, by surname and then name. Empty where the
     * staff are closed to the viewer, which is what `showsEmployees` tells
     * apart from a position nobody holds.
     */
    employees: Person[];
}

interface Props {
    positions: Position[];
    /** The department tree flattened, parents first, for the editor to choose from. */
    options: { departments: (Department & { depth?: number })[] };
    /**
     * Whether this list is this viewer's to change. Reading the section and
     * keeping it are two different rights, so without the second one the same
     * table simply has no controls.
     */
    canEdit: boolean;
    /**
     * Whether the count may lead into the staff list narrowed to the position.
     * Nobody narrows a list by what they may not read, so for everybody else the
     * number is a number rather than a way into a refusal.
     */
    canSeeEmployees: boolean;
    /**
     * Whether the names of the staff are this viewer's to read at all. Without
     * that right nobody travelled, and the column falls back to counting them —
     * the same number the page has always shown.
     */
    showsEmployees: boolean;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Должности', href: '/positions' }];

const STORAGE_KEY = 'positions.table.view.v1';

/**
 * The order the list opens in, and the one a third press on a header and "reset"
 * both bring back: the newest position first, as the staff list opens. It reads
 * the moment a row was added rather than a column of the table, so no header
 * carries it and the phone's sheet has to name it.
 */
const DEFAULT_SORT: Sort = { key: 'created', direction: 'desc' };

/** How many duties a row shows before the rest are counted off at the end. */
const DUTIES_SHOWN = 4;

/** The same for the people in a position: a name takes a line's worth of room. */
const EMPLOYEES_SHOWN = 3;

/**
 * Names need more room than the number they replaced, and the duties — which
 * wrap anyway — give it up more cheaply than a job title does. Three columns
 * and the row's menu come to the width a laptop leaves beside the sidebar, so
 * the people are on screen without scrolling the table sideways first.
 */
const columns: ColumnDef[] = [
    { key: 'name', label: 'Должность', width: 300 },
    { key: 'duties', label: 'Обязанности', width: 300 },
    { key: 'employees_count', label: 'Сотрудники', width: 308 },
];

const columnKeys = columns.map((column) => column.key);

/** Duties are a list, not a scale: only the other two columns can be put in order. */
const sortable = ['name', 'employees_count'];

const defaultView: ViewState = { hidden: [], pinned: { left: [], right: [] } };

/**
 * There are dozens of positions, not thousands, so the whole list arrives at
 * once and the search, the order and the narrowing are all worked out here
 * rather than asked of the server.
 *
 * Only the duties carry a funnel of their own. A name or a colleague is what
 * the one box above reads, but "what is done in this department" is a question
 * about how a row's duties break down, and no word typed into a search box asks
 * it: a department's name in the box finds the positions that mention it, while
 * the filter answers with the work itself.
 */
function buildColumns(departments: Props['options']['departments']): ColumnDef[] {
    const filter: FilterDef = {
        type: 'multi',
        param: 'department',
        options: departments.map((department) => ({
            value: department.id,
            label: department.name,
            // The list is named by abbreviations, and a filter is not a link one
            // can follow to find out what an abbreviation stands for.
            hint: department.full_name === department.name ? undefined : department.full_name,
            // Indented by how deep it sits, as the employees list indents the same tree.
            depth: department.depth,
        })),
    };

    return columns.map((column) => (column.key === 'duties' ? { ...column, filter } : column));
}

/** The departments a row adds work of its own for, among those asked about. */
function chosenGroups(position: Position, chosen: number[]): DepartmentDuties[] {
    if (chosen.length === 0) return position.department_duties;

    return position.department_duties.filter((group) => chosen.includes(group.department.id));
}

/**
 * What an empty list says when a department, and not the search, emptied it.
 * The department is spelled out rather than abbreviated: a chip in a cell has
 * only room for "ОА", but a sentence standing alone in an empty table has to be
 * read without anything beside it to explain the letters.
 */
function emptyMessage(departments: Department[]): string {
    if (departments.length === 1) return `${departments[0].full_name} — ни одна должность пока не добавляет сюда свои обязанности.`;

    return 'Ни одна должность пока не добавляет свои обязанности в выбранных отделах.';
}

const collator = new Intl.Collator('ru');

/** When a position was added; one nobody wrote the moment of reads as the oldest. */
const addedAt = (position: Position) => (position.created_at ? Date.parse(position.created_at) : 0);

function compare(a: Position, b: Position, sort: Sort): number {
    const sign = sort.direction === 'asc' ? 1 : -1;

    // Positions that tie — the same number of people, or added in the same
    // second as each other — read alphabetically rather than in whatever order
    // they happened to arrive in.
    if (sort.key === 'employees_count') return sign * (a.employees_count - b.employees_count) || collator.compare(a.name, b.name);
    if (sort.key === 'created') return sign * (addedAt(a) - addedAt(b)) || collator.compare(a.name, b.name);

    return sign * collator.compare(a.name, b.name);
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

    if (duties.length === 0) return <span className="text-muted-foreground">—</span>;

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
 * The whole column: what the job is everywhere, and where it is more than that.
 *
 * The common duties read as they always have. The departments that add work of
 * their own have to be on screen too — that an analyst's duties differ by
 * department is the reason the column exists — but writing every department's
 * list out under them would turn one cell into a second table. So the row names
 * the departments instead, each with how many duties it adds, and pressing one
 * opens that department's list where it stands. Closed, a row says which
 * departments to ask about; open, it answers for the one that was asked, and
 * nothing is ever taller than what somebody asked for.
 *
 * Narrowed to a department, the cell answers a different question — not what
 * the position involves but what is done in that department — so it is laid out
 * the other way round. The department's own duties come first and already open,
 * since there is nothing left to ask it; the common ones follow under a label
 * naming them as common, because whoever holds the position there does both,
 * and a cell showing only the department's half would read as the whole job.
 */
function DepartmentDutiesCell({ position, chosen }: { position: Position; chosen: number[] }) {
    const [opened, setOpened] = useState<number[]>([]);

    const groups = chosenGroups(position, chosen);

    if (position.duties.length === 0 && groups.length === 0) return <span className="text-muted-foreground">—</span>;

    const toggle = (id: number) => setOpened(opened.includes(id) ? opened.filter((other) => other !== id) : [...opened, id]);

    // Nothing in common and everything by department: without the line the
    // departments would read as an afterthought to a list that is missing.
    const common =
        position.duties.length > 0 ? (
            <Duties duties={position.duties} limit={DUTIES_SHOWN} />
        ) : (
            <span className="text-muted-foreground">Общих обязанностей нет</span>
        );

    if (chosen.length > 0) {
        return (
            <div className="flex flex-col items-start gap-2 whitespace-normal">
                {groups.map(({ department, duties }) => (
                    <div key={department.id} className="flex flex-col items-start gap-1">
                        <span className="text-xs font-medium" title={department.full_name === department.name ? undefined : department.full_name}>
                            Добавляются в отделе: {department.name}
                        </span>
                        {/* A group of its own, so numbers of its own. */}
                        <Duties duties={duties} limit={DUTIES_SHOWN} />
                    </div>
                ))}

                <div className="flex w-full flex-col items-start gap-1 border-t pt-2">
                    {position.duties.length > 0 ? (
                        <>
                            <span className="text-muted-foreground text-xs">Общие обязанности должности</span>
                            <Duties duties={position.duties} limit={DUTIES_SHOWN} />
                        </>
                    ) : (
                        // A heading over "there are none" would say the same thing twice.
                        <span className="text-muted-foreground text-xs">Общих обязанностей у должности нет</span>
                    )}
                </div>
            </div>
        );
    }

    return (
        <div className="flex flex-col items-start gap-2 whitespace-normal">
            {common}

            {groups.length > 0 && (
                <div className="flex flex-col items-start gap-1.5">
                    <div className="flex flex-wrap items-center gap-1.5">
                        <span className="text-muted-foreground text-xs">Ещё по отделам:</span>
                        {groups.map(({ department, duties }) => {
                            const open = opened.includes(department.id);

                            return (
                                <button
                                    key={department.id}
                                    type="button"
                                    onClick={() => toggle(department.id)}
                                    aria-expanded={open}
                                    // The abbreviation is all the chip has room for.
                                    title={department.full_name === department.name ? undefined : department.full_name}
                                    aria-label={`${open ? 'Скрыть' : 'Показать'} обязанности отдела ${department.name}: ${duties.length} ${plural(
                                        duties.length,
                                        ['обязанность', 'обязанности', 'обязанностей'],
                                    )}`}
                                >
                                    <StatusBadge tone={open ? 'success' : 'neutral'} className={cn(!open && 'text-muted-foreground hover:bg-accent')}>
                                        {department.name} +{duties.length}
                                    </StatusBadge>
                                </button>
                            );
                        })}
                    </div>

                    {groups
                        .filter(({ department }) => opened.includes(department.id))
                        .map(({ department, duties }) => (
                            <div key={department.id} className="flex flex-col items-start gap-1">
                                <span
                                    className="text-xs font-medium"
                                    title={department.full_name === department.name ? undefined : department.full_name}
                                >
                                    {department.name}
                                </span>
                                {/* A group of its own, so numbers of its own: these are the
                                    department's duties in the department's order, not lines
                                    five and six of the common list. */}
                                <Duties duties={duties} limit={DUTIES_SHOWN} />
                            </div>
                        ))}
                </div>
            )}
        </div>
    );
}

/** The count, and a way into the staff behind it for whoever may read them. */
function EmployeesCount({ position, canSeeEmployees }: { position: Position; canSeeEmployees: boolean }) {
    if (position.employees_count === 0) return <span className="text-muted-foreground tabular-nums">0</span>;

    if (!canSeeEmployees) return <span className="tabular-nums">{position.employees_count}</span>;

    return (
        <Link
            href={route('employees.index', { position: [position.id] })}
            className="text-brand-strong tabular-nums hover:underline dark:text-[#C5E27A]"
            title={`Сотрудники на должности: ${position.name}`}
        >
            {position.employees_count}
        </Link>
    );
}

/**
 * Who holds the position: the people themselves, each a way to their own card.
 *
 * A dozen names would leave the row taller than the screen, so the column names
 * a few and counts off the rest behind a chip, the way the duties beside them
 * are capped. The chip opens them where they stand.
 */
function Employees({ position, canSeeEmployees, showsEmployees }: { position: Position; canSeeEmployees: boolean; showsEmployees: boolean }) {
    const [open, setOpen] = useState(false);

    // Where the staff are closed to the viewer no names arrived; the number
    // always did, and it is the column they have been reading until now. It also
    // answers for a position nobody holds, with the same muted zero as before.
    if (!showsEmployees || position.employees.length === 0) {
        return <EmployeesCount position={position} canSeeEmployees={canSeeEmployees} />;
    }

    const shown = open ? position.employees : position.employees.slice(0, EMPLOYEES_SHOWN);
    const hidden = position.employees.length - shown.length;

    return (
        <div className="flex flex-wrap items-baseline gap-x-1.5 gap-y-1 whitespace-normal">
            {shown.map((person, index) => (
                <span key={person.id}>
                    <PersonLink
                        id={person.id}
                        className="text-brand-strong hover:underline dark:text-[#C5E27A]"
                        title={`Открыть карточку: ${person.name}`}
                    >
                        {person.name}
                    </PersonLink>
                    {index < shown.length - 1 && ','}
                </span>
            ))}
            {(hidden > 0 || open) && <MoreChip hidden={hidden} open={open} onToggle={() => setOpen(!open)} />}
        </div>
    );
}

/**
 * The holders on one line, for the phone row that has only one to give. The
 * names are capped as the column caps them, and the row opens the position,
 * where all of them stand with their duties.
 */
function holdersLine(position: Position): string {
    const shown = position.employees.slice(0, EMPLOYEES_SHOWN);
    const names = shown.map((person) => person.name).join(', ');
    const hidden = position.employees.length - shown.length;

    return hidden > 0 ? `${names} +${hidden}` : names;
}

/** What the phone row says under the name where the holders are not for it to say. */
function dutiesLine(position: Position): string {
    if (position.duties.length > 0) return position.duties.join(' · ');

    // A position whose work is written per department has duties; it is only that
    // none of them are common, and a phone has room to say where they are rather
    // than what they are. The page the row opens says the rest.
    if (position.department_duties.length > 0) {
        return `Обязанности по отделам: ${position.department_duties.map((group) => group.department.name).join(', ')}`;
    }

    return 'Обязанности не заполнены';
}

/**
 * The same line while the list is narrowed to a department: what that
 * department adds, named by it. The row is on screen because of those duties,
 * so they are what the one line under the name is for; the common ones, and
 * everything else, wait on the page the row opens.
 */
function departmentDutiesLine(position: Position, chosen: number[]): string {
    return chosenGroups(position, chosen)
        .map((group) => `${group.department.name}: ${group.duties.join(' · ')}`)
        .join(' · ');
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
                    aria-label={`Действия: ${position.name}`}
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

/** One department and its own duties, as the editor holds them while it is open. */
interface DutiesBlock {
    /** Null in a block just added, before anybody has said which department it is for. */
    department_id: number | null;
    duties: string[];
}

interface EditorData {
    name: string;
    duties: string[];
    blocks: DutiesBlock[];
}

/** Stands for the common duties where a block's own index would otherwise stand. */
const COMMON = -1;

/**
 * The one flat list saving sends, and where each line of it came from.
 *
 * The server takes the common duties and every department's as a single array,
 * each line naming its department or nothing at all, and it reports a line that
 * is too long by its place in that array ("duties.7.name"). So the places are
 * worked out alongside it: that is what reads such an error back as the field
 * somebody typed the line into.
 */
function flatten(data: EditorData) {
    const duties: { name: string; department_id: number | null }[] = [];
    const owners: number[] = [];

    data.duties.forEach((name) => {
        duties.push({ name, department_id: null });
        owners.push(COMMON);
    });

    data.blocks.forEach((block, index) => {
        // A department nobody has chosen yet, or one nobody wrote anything under,
        // is nothing to save: the position just has no duties of its own there.
        if (block.department_id === null) return;

        block.duties.forEach((name) => {
            duties.push({ name, department_id: block.department_id });
            owners.push(index);
        });
    });

    return { duties, owners };
}

/**
 * Adding a position or changing one. The duties are entered the way a person's
 * citizenships are — value by value, each its own chip — and what other
 * positions already say is offered as suggestions, so the same duty is written
 * the same way twice.
 *
 * There are two kinds of them, and the dialog has to say so rather than leave it
 * to be guessed: the common ones, which everybody holding the position does, and
 * a department's, which are *added* for whoever holds it there.
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
        name: position?.name ?? '',
        duties: position?.duties ?? [],
        blocks: position?.department_duties.map((group) => ({ department_id: group.department.id, duties: group.duties })) ?? [],
    });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();

        form.transform((data) => ({ name: data.name.trim(), duties: flatten(data).duties }));
        const options = { preserveScroll: true, onSuccess: onClose };

        if (position) form.put(route('positions.update', position.id), options);
        else form.post(route('positions.store'), options);
    };

    const errors = form.errors as Record<string, string | undefined>;
    const { owners } = flatten(form.data);

    /** What the server said about one line of the list, under the field that wrote it. */
    const dutyError = (owner: number) =>
        Object.entries(errors).find(([key, message]) => {
            const line = /^duties\.(\d+)(\.|$)/.exec(key);

            return line !== null && message !== undefined && owners[Number(line[1])] === owner;
        })?.[1];

    const dutyOptions = suggestions.map((duty) => ({ value: duty, label: duty }));

    const taken = new Set(form.data.blocks.map((block) => block.department_id));
    const setBlock = (index: number, next: DutiesBlock) =>
        form.setData(
            'blocks',
            form.data.blocks.map((block, at) => (at === index ? next : block)),
        );

    // Nothing left to choose, or a block already standing empty: either way the
    // next press would only add a block nobody can fill in.
    const canAddBlock = form.data.blocks.length < departments.length && !form.data.blocks.some((block) => block.department_id === null);

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-h-[90svh] overflow-y-auto sm:max-w-lg">
                <form onSubmit={submit} className="flex flex-col gap-5">
                    <DialogHeader>
                        <DialogTitle>{position ? 'Изменить должность' : 'Новая должность'}</DialogTitle>
                        <DialogDescription className="sr-only">Заполните поля и сохраните.</DialogDescription>
                    </DialogHeader>

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
                        <Label htmlFor="position-duties">Общие обязанности</Label>
                        <MultiSelect
                            id="position-duties"
                            creatable
                            options={dutyOptions}
                            value={form.data.duties}
                            onChange={(duties) => form.setData('duties', duties)}
                            placeholder="Не заполнены"
                            searchPlaceholder="Обязанность"
                        />
                        <p className="text-muted-foreground text-[13px]">
                            Относятся ко всем, кто занимает должность, в любом отделе. По одной обязанности за раз: впишите её и нажмите Enter.
                            Порядок строк здесь — это порядок на странице должности.
                        </p>
                        {/* The whole list can be complained about too ("duties"), and that
                            complaint is nobody's line in particular. */}
                        <InputError message={errors.duties ?? dutyError(COMMON)} />
                    </div>

                    <div className="flex flex-col gap-3 border-t pt-4">
                        <div className="grid content-start gap-1">
                            <h3 className="text-sm font-medium">Обязанности по отделам</h3>
                            <p className="text-muted-foreground text-[13px]">
                                Добавляются к общим, а не заменяют их: у сотрудника этого отдела будут и общие обязанности, и эти. Один отдел можно
                                добавить только один раз.
                            </p>
                        </div>

                        {form.data.blocks.map((block, index) => {
                            // Only one block at a time can be without a department, so
                            // the department it names is a key that stays with it.
                            const key = block.department_id ?? 'new';
                            const chosen = departments.find((department) => department.id === block.department_id);

                            return (
                                <div key={key} className="bg-muted/40 flex flex-col gap-3 rounded-lg border p-3">
                                    <div className="grid content-start gap-2">
                                        <Label htmlFor={`position-department-${key}`}>Отдел</Label>
                                        {/* The field and the button that drops it share a line, and
                                            anything said below the field is said below both: a
                                            complaint must not shift the button out from under the
                                            cursor. */}
                                        <div className="flex items-center gap-2">
                                            <div className="min-w-0 flex-1">
                                                <SearchableSelect
                                                    id={`position-department-${key}`}
                                                    className="max-md:h-11"
                                                    value={block.department_id === null ? '' : String(block.department_id)}
                                                    onChange={(value) => setBlock(index, { ...block, department_id: Number(value) })}
                                                    options={departments
                                                        // A department already spoken for is not
                                                        // offered twice; this block's own stays, or
                                                        // it could not show what it is set to.
                                                        .filter((department) => department.id === block.department_id || !taken.has(department.id))
                                                        .map((department) => ({
                                                            value: String(department.id),
                                                            label: department.name,
                                                            hint: department.full_name === department.name ? undefined : department.full_name,
                                                            // Indented by how deep it sits, as the employees list indents the same tree.
                                                            depth: department.depth,
                                                        }))}
                                                    placeholder="Выберите отдел"
                                                    searchPlaceholder="Отдел"
                                                    empty="Отдел не найден"
                                                />
                                            </div>
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="icon"
                                                className="text-muted-foreground size-10 shrink-0 lg:size-9"
                                                aria-label={chosen ? `Убрать отдел: ${chosen.name}` : 'Убрать отдел'}
                                                onClick={() =>
                                                    form.setData(
                                                        'blocks',
                                                        form.data.blocks.filter((_, at) => at !== index),
                                                    )
                                                }
                                            >
                                                <Trash2 />
                                            </Button>
                                        </div>
                                    </div>

                                    <div className="grid content-start gap-2">
                                        <Label htmlFor={`position-department-duties-${key}`}>Что добавляется в этом отделе</Label>
                                        <MultiSelect
                                            id={`position-department-duties-${key}`}
                                            creatable
                                            options={dutyOptions}
                                            value={block.duties}
                                            onChange={(duties) => setBlock(index, { ...block, duties })}
                                            // Until the department is known there is nothing for a
                                            // duty here to belong to.
                                            disabled={block.department_id === null}
                                            placeholder={block.department_id === null ? 'Сначала выберите отдел' : 'Не заполнены'}
                                            searchPlaceholder="Обязанность"
                                        />
                                        <InputError message={dutyError(index)} />
                                    </div>
                                </div>
                            );
                        })}

                        <div>
                            <Button
                                type="button"
                                variant="outline"
                                className="max-md:h-11"
                                disabled={!canAddBlock}
                                onClick={() => form.setData('blocks', [...form.data.blocks, { department_id: null, duties: [] }])}
                            >
                                <Plus />
                                Добавить отдел
                            </Button>
                        </div>
                    </div>

                    <DialogFooter className="gap-2">
                        <Button type="button" variant="outline" className="max-md:h-11" onClick={onClose}>
                            Отмена
                        </Button>
                        <Button type="submit" className="max-md:h-11" disabled={form.processing || form.data.name.trim() === ''}>
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
                    <DialogTitle className="break-words">Удалить должность «{position.name}»?</DialogTitle>
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

export default function PositionsIndex({ positions, options, canEdit, canSeeEmployees, showsEmployees }: Props) {
    const { view, pin, reorder } = useTableView(STORAGE_KEY, columnKeys, defaultView);
    const [query, setQuery] = useState('');
    const [sort, setSort] = useState<Sort>(DEFAULT_SORT);
    // The departments the duties are narrowed to, kept here beside the search
    // and the order, which this page has never asked the server for either.
    const [chosen, setChosen] = useState<number[]>([]);
    const [editing, setEditing] = useState<Position | 'new' | null>(null);
    const [deleting, setDeleting] = useState<Position | null>(null);

    const tableColumns = useMemo(() => buildColumns(options.departments), [options.departments]);
    const filters: FilterValues = { department: chosen };
    const applyFilter = (changes: FilterValues) => setChosen((changes.department as number[] | undefined) ?? []);

    // A position is searched for by what it is called, by what it does and by
    // who does it: a duty, or a colleague, is often what somebody remembers of a
    // job title they cannot name. A duty a single department adds is as much what
    // the position does as a common one, and the department's own name is on
    // screen beside it, so both answer the box. Names are read where they are on
    // screen; where the staff are closed none arrived and there is nothing to
    // search.
    //
    // A department chosen first: what it keeps are the positions that add work
    // of their own there, which is what the column then shows. The box reads
    // what is left on screen, so another department's duties no longer answer
    // it — searching inside a narrowed list would otherwise bring back rows for
    // words that are nowhere in them.
    const rows = useMemo(() => {
        const term = query.trim().toLowerCase();
        const narrowed = chosen.length > 0 ? positions.filter((position) => chosenGroups(position, chosen).length > 0) : positions;

        const found = term
            ? narrowed.filter(
                  (position) =>
                      position.name.toLowerCase().includes(term) ||
                      position.duties.some((duty) => duty.toLowerCase().includes(term)) ||
                      chosenGroups(position, chosen).some(
                          (group) =>
                              group.duties.some((duty) => duty.toLowerCase().includes(term)) ||
                              group.department.name.toLowerCase().includes(term) ||
                              group.department.full_name.toLowerCase().includes(term),
                      ) ||
                      position.employees.some((person) => person.name.toLowerCase().includes(term)),
              )
            : narrowed;

        return [...found].sort((a, b) => compare(a, b, sort));
    }, [positions, query, sort, chosen]);

    /**
     * Every duty written anywhere, offered to the next position to be entered —
     * a department's among them, since the same wording serves both kinds.
     */
    const suggestions = useMemo(
        () =>
            [...new Set(positions.flatMap((position) => [...position.duties, ...position.department_duties.flatMap((group) => group.duties)]))].sort(
                (a, b) => collator.compare(a, b),
            ),
        [positions],
    );

    const cell = (column: ColumnDef, position: Position) => {
        switch (column.key) {
            case 'name':
                return (
                    <Link
                        href={route('positions.show', position.id)}
                        // Brand colour and an underline on hover: the app's mark of a
                        // link. A job title is the whole point of the row, so it wraps
                        // rather than being cut at the edge of the column.
                        className="text-brand-strong font-medium whitespace-normal hover:underline dark:text-[#C5E27A]"
                        title={`Открыть должность: ${position.name}`}
                    >
                        {position.name}
                    </Link>
                );
            case 'duties':
                return <DepartmentDutiesCell position={position} chosen={chosen} />;
            default:
                return <Employees position={position} canSeeEmployees={canSeeEmployees} showsEmployees={showsEmployees} />;
        }
    };

    // The box reads whatever is on screen, and the names are not on every screen.
    const searchHint = showsEmployees ? 'Поиск по названию, обязанностям и сотрудникам' : 'Поиск по названию и обязанностям';

    /**
     * An empty list has to say which of the two reasons emptied it. A department
     * that nobody writes duties for is the likelier one, and "nothing found"
     * would leave somebody retyping a search that was never the trouble, so the
     * department answers for itself — by name while there is one name to give.
     */
    const chosenDepartments = options.departments.filter((department) => chosen.includes(department.id));
    const noneHere = chosen.length > 0 && !positions.some((position) => chosenGroups(position, chosen).length > 0);
    const empty = positions.length === 0 ? 'Должностей пока нет.' : noneHere ? emptyMessage(chosenDepartments) : 'Ничего не найдено.';

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
                        defaultSort={DEFAULT_SORT}
                        // No header carries the opening order, so the sheet names it.
                        defaultSortLabel="Дата добавления"
                        // One visit clears both, so neither is left narrowing a list
                        // somebody has just put back in order.
                        onReset={() => {
                            setSort(DEFAULT_SORT);
                            setChosen([]);
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
                    // A tree of departments needs more room than a short list.
                    wideFilter={(column) => column.key === 'duties'}
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
                            // A phone row has one line under the name, and the people
                            // are what this column is for; the duties — which a phone
                            // could only ever show the first of — wait on the page the
                            // row opens, where the holders stand with them. With no
                            // holders to name, or none for this viewer to read, that
                            // line stays the duties, as it has been. Narrowed to a
                            // department, the duties take the line back whoever holds
                            // them: the row is here because of them, and the names say
                            // nothing about the department that was asked for.
                            subtitle={
                                chosen.length > 0
                                    ? departmentDutiesLine(position, chosen)
                                    : showsEmployees && position.employees.length > 0
                                      ? holdersLine(position)
                                      : dutiesLine(position)
                            }
                            // Names take the room, so the number keeps to the edge.
                            meta={
                                <span className="tabular-nums" title={peopleLabel(position.employees_count)}>
                                    {position.employees_count}
                                </span>
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
