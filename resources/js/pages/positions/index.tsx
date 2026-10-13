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
import { DepartmentName } from '@/components/department-name';
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

interface Position {
    id: number;
    name: string;
    /** «Аналитик (ОПР)» — the name with the department after it, where one line is all there is. */
    display_name: string;
    /** Where the position sits; null for the few nobody has filed yet. */
    department: Department | null;
    /** When somebody added it: what the list's own opening order reads, newest first. */
    created_at: string | null;
    /**
     * What the job is, a short line per duty, in the order they were entered.
     * One list: the position belongs to one department, so there is nothing
     * inside it to tell apart.
     */
    duties: string[];
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
    /** The department tree flattened, parents first, for the filter and the editor. */
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

/** Stands for "no department at all" where a department's id would stand. */
const NONE = 'none';

/**
 * The department is a column rather than a decoration of the name, because it
 * has a column's work to do: twenty names on this list are held in more than one
 * department, and a filter and an order have to hang off something. A name on
 * its own stopped answering "which «Аналитик» is this?", and only the header
 * beside it can be sorted, narrowed, hidden or dragged elsewhere like the rest.
 *
 * It takes its room from the duties, which wrap anyway, so the people are still
 * on screen without scrolling the table sideways first.
 */
const columns: ColumnDef[] = [
    { key: 'name', label: 'Должность', width: 260 },
    { key: 'department', label: 'Отдел', width: 180 },
    { key: 'duties', label: 'Обязанности', width: 280 },
    { key: 'employees_count', label: 'Сотрудники', width: 288 },
];

const columnKeys = columns.map((column) => column.key);

/** Duties are a list, not a scale: the other three columns can be put in order. */
const sortable = ['name', 'department', 'employees_count'];

const defaultView: ViewState = { hidden: [], pinned: { left: [], right: [] } };

/**
 * There are dozens of positions, not thousands, so the whole list arrives at
 * once and the search, the order and the narrowing are all worked out here
 * rather than asked of the server.
 *
 * The one funnel narrows the list to a department — the position's own, now that
 * a position has one. «Без отдела» stands at the top of it on purpose: the few
 * positions the data could not place have to be findable, and this is the one
 * question that finds them all at once.
 */
function buildColumns(departments: Props['options']['departments']): ColumnDef[] {
    const filter: FilterDef = {
        type: 'multi',
        param: 'department',
        options: [
            { value: NONE, label: 'Без отдела' },
            ...departments.map((department) => ({
                value: department.id,
                label: department.name,
                // The list is named by abbreviations, and a filter is not a link one
                // can follow to find out what an abbreviation stands for.
                hint: department.full_name === department.name ? undefined : department.full_name,
                // Indented by how deep it sits, as the employees list indents the same tree.
                depth: department.depth,
            })),
        ],
    };

    return columns.map((column) => (column.key === 'department' ? { ...column, filter } : column));
}

/** Which departments are being asked about; «Без отдела» is one of the answers. */
type Chosen = (number | typeof NONE)[];

/** Whether the row is in one of the departments asked about. */
function inChosen(position: Position, chosen: Chosen): boolean {
    return chosen.length === 0 || chosen.includes(position.department?.id ?? NONE);
}

/**
 * What an empty list says when the funnel, and not the search, emptied it.
 * The department is spelled out rather than abbreviated: a cell has only room
 * for "ОА", but a sentence standing alone in an empty table has to be read
 * without anything beside it to explain the letters.
 */
function emptyMessage(chosen: Chosen, departments: Department[]): string {
    if (chosen.length === 1 && chosen[0] === NONE) return 'Все должности закреплены за отделами.';
    if (chosen.length === 1 && departments.length === 1) return `${departments[0].full_name} — должностей в этом отделе пока нет.`;

    return 'В выбранных отделах должностей пока нет.';
}

const collator = new Intl.Collator('ru');

/** When a position was added; one nobody wrote the moment of reads as the oldest. */
const addedAt = (position: Position) => (position.created_at ? Date.parse(position.created_at) : 0);

function compare(a: Position, b: Position, sort: Sort): number {
    const sign = sort.direction === 'asc' ? 1 : -1;

    // Positions that tie — the same number of people, the same department, or
    // added in the same second as each other — read alphabetically rather than
    // in whatever order they happened to arrive in.
    if (sort.key === 'employees_count') return sign * (a.employees_count - b.employees_count) || collator.compare(a.name, b.name);
    if (sort.key === 'created') return sign * (addedAt(a) - addedAt(b)) || collator.compare(a.name, b.name);
    // A position nobody has filed reads as no department at all, which sorts
    // before every name there is: one press on the header and the unplaced ones
    // are at the top, where somebody can go and file them.
    if (sort.key === 'department') {
        return sign * collator.compare(a.department?.name ?? '', b.department?.name ?? '') || collator.compare(a.name, b.name);
    }

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
 * Where the position sits, named the short way and leading to the department's
 * own page, as an abbreviation does everywhere else on screen.
 *
 * One that nobody has filed says so in as many words, and in the colour of
 * something to be dealt with rather than of something missing: a blank cell
 * reads as a page that failed to load, and these rows are the ones somebody has
 * to go and finish.
 */
function DepartmentCell({ position }: { position: Position }) {
    if (position.department === null) {
        return (
            <StatusBadge tone="warning" title="Должность не закреплена за отделом">
                Без отдела
            </StatusBadge>
        );
    }

    return (
        <Link
            href={route('departments.show', position.department.id)}
            className="hover:underline"
            title={`Открыть отдел: ${position.department.full_name}`}
        >
            <DepartmentName name={position.department.name} full={position.department.full_name} tooltip={false} />
        </Link>
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
            title={`Сотрудники на должности: ${position.display_name}`}
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

    return 'Обязанности не заполнены';
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

export default function PositionsIndex({ positions, options, canEdit, canSeeEmployees, showsEmployees }: Props) {
    const { view, pin, reorder } = useTableView(STORAGE_KEY, columnKeys, defaultView);
    const [query, setQuery] = useState('');
    const [sort, setSort] = useState<Sort>(DEFAULT_SORT);
    // The departments the list is narrowed to, kept here beside the search and
    // the order, which this page has never asked the server for either.
    const [chosen, setChosen] = useState<Chosen>([]);
    const [editing, setEditing] = useState<Position | 'new' | null>(null);
    const [deleting, setDeleting] = useState<Position | null>(null);

    const tableColumns = useMemo(() => buildColumns(options.departments), [options.departments]);
    const filters: FilterValues = { department: chosen };
    const applyFilter = (changes: FilterValues) => setChosen((changes.department as Chosen | undefined) ?? []);

    // A position is searched for by what it is called, by where it sits, by what
    // it does and by who does it: a duty, or a colleague, is often what somebody
    // remembers of a job title they cannot name, and the department is half of
    // what a repeated name means. Names are read where they are on screen; where
    // the staff are closed none arrived and there is nothing to search.
    const rows = useMemo(() => {
        const term = query.trim().toLowerCase();
        const narrowed = chosen.length > 0 ? positions.filter((position) => inChosen(position, chosen)) : positions;

        const found = term
            ? narrowed.filter(
                  (position) =>
                      position.name.toLowerCase().includes(term) ||
                      position.department?.name.toLowerCase().includes(term) ||
                      position.department?.full_name.toLowerCase().includes(term) ||
                      position.duties.some((duty) => duty.toLowerCase().includes(term)) ||
                      position.employees.some((person) => person.name.toLowerCase().includes(term)),
              )
            : narrowed;

        return [...found].sort((a, b) => compare(a, b, sort));
    }, [positions, query, sort, chosen]);

    /** Every duty written anywhere, offered to the next position to be entered. */
    const suggestions = useMemo(
        () => [...new Set(positions.flatMap((position) => position.duties))].sort((a, b) => collator.compare(a, b)),
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
                        // rather than being cut at the edge of the column. The hover
                        // names the department too, for whoever hid that column.
                        className="text-brand-strong font-medium whitespace-normal hover:underline dark:text-[#C5E27A]"
                        title={`Открыть должность: ${position.display_name}`}
                    >
                        {position.name}
                    </Link>
                );
            case 'department':
                return <DepartmentCell position={position} />;
            case 'duties':
                return <Duties duties={position.duties} limit={DUTIES_SHOWN} />;
            default:
                return <Employees position={position} canSeeEmployees={canSeeEmployees} showsEmployees={showsEmployees} />;
        }
    };

    // The box reads whatever is on screen, and the names are not on every screen.
    const searchHint = showsEmployees ? 'Поиск по названию, отделу, обязанностям и сотрудникам' : 'Поиск по названию, отделу и обязанностям';

    /**
     * An empty list has to say which of the two reasons emptied it. A department
     * without a single position of its own is the likelier one, and "nothing
     * found" would leave somebody retyping a search that was never the trouble,
     * so the department answers for itself — by name while there is one name to
     * give.
     */
    const chosenDepartments = options.departments.filter((department) => chosen.includes(department.id));
    const noneHere = chosen.length > 0 && !positions.some((position) => inChosen(position, chosen));
    const empty = positions.length === 0 ? 'Должностей пока нет.' : noneHere ? emptyMessage(chosen, chosenDepartments) : 'Ничего не найдено.';

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
                    wideFilter={(column) => column.key === 'department'}
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
                            // name opens with the department: twenty of these names
                            // repeat, and a row saying «Аналитик» alone could be any of
                            // them. It goes first because the line is cut at the edge
                            // of the screen and an abbreviation is short — the people
                            // after it are what gives way, and the page the row opens
                            // names them in full. One nobody has filed says so here, in
                            // the colour of something to be dealt with: that is how the
                            // unplaced ones are found on a phone at all.
                            //
                            // After the department come the people, which is what the
                            // column beside the name is for; the duties — which a phone
                            // could only ever show the first of — wait on the page the
                            // row opens, where the holders stand with them. With no
                            // holders to name, or none for this viewer to read, that
                            // part stays the duties, as it has been.
                            subtitle={
                                <>
                                    <span className={cn(!position.department && 'text-[#9A4A06] dark:text-[#F8C471]')}>
                                        {position.department ? position.department.name : 'без отдела'}
                                    </span>
                                    {' · '}
                                    {showsEmployees && position.employees.length > 0 ? holdersLine(position) : dutiesLine(position)}
                                </>
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
