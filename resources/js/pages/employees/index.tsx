import { CitizenshipBadges } from '@/components/citizenship-badges';
import {
    cycleSort,
    DataTable,
    isSorted,
    MobileListTools,
    MobileRow,
    orderedColumns,
    TableMemoryButton,
    useDebouncedSearch,
    useRememberedQuery,
    useTableMemory,
    useTableView,
    type ColumnDef as TableColumn,
} from '@/components/data-table';
import { DepartmentLink } from '@/components/department-name';
import { EmployeeActions, type EmploymentStatus } from '@/components/employee-actions';
import { LanguageBadges } from '@/components/language-badges';
import { MobileFab } from '@/components/mobile-fab';
import { Pagination, type Paginated } from '@/components/pagination';
import { faceEdge, PersonAvatar } from '@/components/person-avatar';
import { Phones } from '@/components/phones';
import { PositionName, positionTitle, type PositionRef } from '@/components/position-name';
import { SearchInput } from '@/components/search-input';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import AppLayout from '@/layouts/app-layout';
import { useCan } from '@/lib/access';
import { capitalize, formatDate, maritalLabels, sexLabels, type Marital, type PrivateDetails, type Sex, type SpokenLanguage } from '@/lib/employee';
import { TABLE_KEYS } from '@/lib/tables';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { ChevronDown, Lock, Plus } from 'lucide-react';
import { useMemo, useRef, type ReactNode } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Сотрудники', href: '/employees' }];

/* ------------------------------------------------------------------ types */

/**
 * A row of the list. Only the name and the avatar are certain: every other line
 * is a field the viewer may or may not read, and what they may not read does not
 * travel at all — so the types say "maybe" and the columns for those are not
 * built in the first place.
 */
interface EmployeeRow {
    id: number;
    name: string;
    surname: string;
    avatar: string | null;
    patronymic?: string | null;
    sex?: Sex;
    email?: string;
    /** Access roles, shown as «Роль». */
    roles?: string[];
    /** Positions, shown as «Должность»; an employee can hold several. */
    positions?: PositionRef[];
    /**
     * Where the person is attached, one entry per placement — eight of them
     * hold two, and two of those sit in units of different departments. Each
     * entry carries the department above it, so the «Департамент» column and
     * the «Отдел» column can print the same placements in the same order and
     * be read across. `parent` is null for somebody attached straight to a
     * department, which is then all there is to say about where they sit.
     */
    departments?: {
        id: number;
        name: string;
        full_name: string;
        path: string;
        full_path: string;
        is_head: boolean;
        parent: { id: number; name: string; full_name: string } | null;
    }[];
    /** Public, like positions; the best known first. */
    languages?: SpokenLanguage[];
    status: EmploymentStatus;
    status_changed_at: string | null;
    /** Where they were transferred or why they were let go; managers only. */
    status_note: string | null;
    /** Null when the viewer may not see this person's private data. */
    private: PrivateDetails | null;
}

interface Filters {
    /** Toolbar search across every column. */
    q: string;
    /** "Сотрудник" column filter: name and e-mail only. */
    search: string;
    role: string[];
    position: number[];
    /** The same question by title, which is what the column's funnel asks. */
    position_name: string[];
    /** A department, or «none» for the people no department has on its books. */
    department: (number | typeof NONE)[];
    /** A unit, or «none» for the people filed straight into a department. */
    unit: (number | typeof NONE)[];
    language: number[];
    sex: Sex | null;
    birth_from: string | null;
    birth_to: string | null;
    nationality: string[];
    citizenship: string[];
    address: string;
    phone: string;
    marital_status: Marital | null;
    children: number[];
    hired_from: string | null;
    hired_to: string | null;
}

type ColumnKey =
    | 'name'
    | 'role'
    | 'position'
    | 'department'
    | 'unit'
    | 'languages'
    | 'birth_date'
    | 'sex'
    | 'nationality'
    | 'citizenship'
    | 'home_address'
    | 'phone'
    | 'marital_status'
    | 'children'
    | 'hired_at';

interface Sort {
    // `created` is the order the list opens in, newest first; it has no column of its own.
    key: ColumnKey | 'created';
    direction: 'asc' | 'desc';
}

interface EmployeesProps {
    employees: Paginated<EmployeeRow>;
    filters: Filters;
    sort: Sort;
    perPage: number;
    perPageOptions: number[];
    /**
     * Which fields of a card this viewer reads. The columns, their filters and
     * the detail rows are all built from it.
     */
    visibleFields: string[];
    sortable: ColumnKey[];
    options: {
        roles: { name: string; title: string }[];
        /** With «display_name» — «Администратор (ОАП)» — since one name may now be filed in several departments. */
        positions: { id: number; name: string; display_name: string }[];
        /** The departments that stand on their own — the «Департамент» funnel. */
        departments: { id: number; name: string; full_name: string; depth: number }[];
        /** What hangs inside them, a unit of a unit indented under its own — the «Отдел» funnel. */
        units: { id: number; name: string; full_name: string; depth: number }[];
        languages: { id: number; name: string }[];
        nationalities: string[];
        citizenships: string[];
    };
    /** Which list is shown: working, transferred or fired. */
    status: EmploymentStatus;
    /** Per-list counts; null for viewers who only see working staff. */
    statusCounts: Record<EmploymentStatus, number> | null;
    /** May add a colleague: the right to add, which opens the whole card of whoever is added. */
    canCreate: boolean;
    total: number;
}

/** `depth` indents tree options such as departments. */
type Option<T> = { value: T; label: string; depth?: number };

type FilterDef =
    | { type: 'text'; param: 'search' | 'address' | 'phone'; placeholder: string }
    | { type: 'select'; param: 'sex' | 'marital_status'; options: Option<string>[] }
    | {
          type: 'multi';
          param: 'role' | 'position' | 'position_name' | 'department' | 'unit' | 'language' | 'nationality' | 'citizenship' | 'children';
          options: Option<string | number>[];
      }
    | { type: 'dates'; from: 'birth_from' | 'hired_from'; to: 'birth_to' | 'hired_to' };

interface ColumnDef {
    key: ColumnKey;
    label: string;
    width: number;
    /** The column the table widens when the others leave room over; at most one. */
    fill?: boolean;
    private: boolean;
    filter?: FilterDef;
    cell: (row: EmployeeRow, details: PrivateDetails) => ReactNode;
}

/**
 * What the two structure funnels call «Без департамента» and «Без отдела». The
 * server knows the same word.
 */
const NONE = 'none';

/* ---------------------------------------------------------------- helpers */

/* ФИО in full, as far as the viewer may read it: the patronymic travels only
   to those who may see that line, and a row without it simply shows ФИ. */
const fullName = (row: EmployeeRow) => [row.surname, row.name, row.patronymic].filter(Boolean).join(' ');

/* A level of the structure as a funnel reads it: the short name on the line and
   the long one under it, indented by how deep it sits in its own list. */
const departmentOptions = (level: { id: number; name: string; full_name: string; depth: number }[]) =>
    level.map((d) => ({ value: d.id, label: d.name, hint: d.full_name === d.name ? undefined : d.full_name, depth: d.depth }));

function RoleBadges({ titles }: { titles: string[] }) {
    return (
        <div className="flex flex-wrap gap-1 whitespace-normal">
            {titles.map((title) => (
                // A badge long enough to outrun its column breaks onto a
                // second line instead of being cut off at the edge of it.
                <StatusBadge key={title} tone="info" className="h-auto flex-wrap py-[3px] whitespace-normal">
                    {title}
                </StatusBadge>
            ))}
        </div>
    );
}

function PositionBadges({ positions }: { positions: PositionRef[] }) {
    return (
        <div className="flex flex-wrap gap-1 whitespace-normal">
            {positions.map((position) => {
                const badge = (
                    <StatusBadge tone="success" className="h-auto flex-wrap py-[3px] whitespace-normal">
                        <PositionName name={position.name} unit={position.unit} />
                    </StatusBadge>
                );

                // The badge is the way to the job for whoever may read the jobs,
                // and a word for the rest: a position arrives without an id
                // where it is not theirs to open, and a link into a refusal is
                // no better than no link at all.
                return position.id === null ? (
                    <span key={position.name}>{badge}</span>
                ) : (
                    <Link
                        key={position.id}
                        href={route('positions.show', position.id)}
                        title={`Открыть должность: ${positionTitle(position)}`}
                        className="rounded-md transition-[filter] hover:brightness-95 dark:hover:brightness-125"
                    >
                        {badge}
                    </Link>
                );
            })}
        </div>
    );
}

/**
 * How tall one placement stands where a person has more than one.
 *
 * An abbreviation and the one line under it are both written at a size of their
 * own, so the pair is the same height wherever it is drawn; the block is held
 * to that height whatever it holds, which is what lets a person's second
 * placement stand beside their second unit. An entry with no name to spell out,
 * or no unit at all, keeps the room rather than closing it up and carrying
 * everything below it out of step with the column beside it.
 */
const PLACEMENT = 'h-10';

/**
 * The placements of one person, one per line, in the order the server sends.
 *
 * Both structure columns draw this over the same list, so the second line of
 * «Департамент» is the department of the second line of «Отдел» and the two are
 * read across as one placement. Eight people of seventy-two have two of them,
 * and two of those sit in units of different departments, which is the whole
 * reason the pairing has to hold rather than merely look tidy.
 *
 * Where a person has the one placement there is nothing to pair it with, so the
 * name underneath is given the two lines the positions table gives it and the
 * block is left to be as tall as it needs. Where there are several, each is cut
 * to one line and held to a fixed height: the alternative is four lines of
 * spelled-out names in each of two columns, which is a wall rather than a list,
 * and lines that wrap in one column and not the other, which is worse — it
 * would say somebody sits in a department they have never been near.
 */
function Placements({ departments, of }: { departments: NonNullable<EmployeeRow['departments']>; of: 'department' | 'unit' }) {
    const stacked = departments.length > 1;

    return (
        <div className="flex flex-col gap-1">
            {departments.map((placement) => {
                // The department of a placement is the one above the unit, or
                // the department itself where the person is attached to no unit.
                const parent = placement.parent;
                const shown = of === 'department' ? (parent ?? placement) : parent === null ? null : placement;

                return (
                    <div key={placement.id} className={cn('flex max-w-full items-start', stacked && PLACEMENT)}>
                        {shown === null ? (
                            // Attached straight to a department: there is no unit
                            // to name, and the line says so the way the rest of
                            // the table says it.
                            <Empty />
                        ) : (
                            <DepartmentLink id={shown.id} name={shown.name} full={shown.full_name} lines={stacked ? 1 : 2} />
                        )}
                    </div>
                );
            })}
        </div>
    );
}

const statusTabs: { status: EmploymentStatus; label: string }[] = [
    { status: 'active', label: 'Работают' },
    { status: 'transferred', label: 'Переведённые' },
    { status: 'fired', label: 'Уволенные' },
];

/** "Уволена 12.03.2026 · По собственному желанию" under the name. */
function LeftBadge({ row }: { row: EmployeeRow }) {
    const female = row.sex === 'female';
    const label = row.status === 'fired' ? (female ? 'Уволена' : 'Уволен') : female ? 'Переведена' : 'Переведён';
    const text = [label, formatDate(row.status_changed_at)].filter(Boolean).join(' ');

    return (
        <span className="mt-1 flex min-w-0 flex-col items-start gap-1 text-xs whitespace-normal">
            <StatusBadge tone={row.status === 'fired' ? 'danger' : 'warning'} className="h-5 shrink-0">
                {text}
            </StatusBadge>
            {row.status_note && (
                <span className="text-muted-foreground leading-snug break-words">
                    {row.status === 'transferred' ? `→ ${row.status_note}` : row.status_note}
                </span>
            )}
        </span>
    );
}

/**
 * The person's face in a list row. Initials mean nobody uploaded a photograph;
 * a lock means the photograph is not this viewer's to see, which is not the
 * same thing. The table and the phone list draw it at their own sizes.
 */
function Face({ row, visible, className }: { row: EmployeeRow; visible: string[]; className: string }) {
    if (!visible.includes('avatar')) {
        return (
            <span
                className={cn('bg-muted text-muted-foreground flex shrink-0 items-center justify-center rounded-full', faceEdge, className)}
                title="Фотография закрыта"
                aria-label="Фотография закрыта"
            >
                <Lock className="size-4" />
            </span>
        );
    }

    if (!row.avatar) return <PersonAvatar name={`${row.name} ${row.surname}`} className={className} />;

    return (
        // The picture opens at full size in a tab of its own; the name beside
        // it is the way to the card.
        <a href={row.avatar} target="_blank" rel="noopener" title={`Фотография: ${row.surname} ${row.name}`} className="shrink-0 rounded-full">
            <img src={row.avatar} alt="" className={cn('shrink-0 rounded-full object-cover', faceEdge, className)} />
        </a>
    );
}

/**
 * The line under the name in the phone list: why somebody left the list of
 * working staff, if they did; otherwise what they do, falling back on the
 * e-mail when the viewer reads neither positions nor roles.
 */
function mobileSubtitle(row: EmployeeRow): string | undefined {
    if (row.status !== 'active') {
        const female = row.sex === 'female';
        const label = row.status === 'fired' ? (female ? 'Уволена' : 'Уволен') : female ? 'Переведена' : 'Переведён';
        const note = row.status_note && (row.status === 'transferred' ? `→ ${row.status_note}` : row.status_note);

        return [[label, formatDate(row.status_changed_at)].filter(Boolean).join(' '), note].filter(Boolean).join(' · ');
    }

    // A phone's row is one line of text, so the department rides in the
    // brackets rather than under the cursor.
    return [row.positions?.map(positionTitle), row.roles].find((titles) => titles?.length)?.join(', ') || row.email || undefined;
}

function Empty() {
    return <span className="text-muted-foreground">—</span>;
}

function Children({ items }: { items: PrivateDetails['children'] }) {
    if (items.length === 0) return <span className="text-muted-foreground">Нет</span>;

    const names = items.map((child) => [child.full_name, formatDate(child.birth_date)].filter(Boolean).join(' — ')).join('\n');

    return (
        <span title={names} className="cursor-help underline decoration-dotted underline-offset-4">
            {items.length}
        </span>
    );
}

/* ---------------------------------------------------------------- columns */

/** The field behind a column, where the two are not called the same. */
const COLUMN_FIELDS: Partial<Record<ColumnKey, string>> = {
    role: 'roles',
    position: 'positions',
    department: 'departments',
    // Two columns over one field: a viewer who may not read where people are
    // filed gets neither of them.
    unit: 'departments',
};

/**
 * A chip of a language or a country in a cell adds it to the filter, or takes
 * it off again; several ask for people who have all of them.
 */
interface ChipFilter {
    toggle: (param: 'language' | 'citizenship', value: number | string) => void;
    has: (param: 'language' | 'citizenship', value: number | string) => boolean;
}

function buildColumns(options: EmployeesProps['options'], visible: string[], chips: ChipFilter): ColumnDef[] {
    // The name stays whatever happens: a list of rows with no names on them
    // would be no list at all.
    const shows = (key: ColumnKey) => key === 'name' || visible.includes(COLUMN_FIELDS[key] ?? key);

    /* Every job title, each one once and in order: the same title is filed in
       several departments, and the funnel asks about the title itself. */
    const titles = [...new Set(options.positions.map((position) => position.name))]
        .sort((a, b) => a.localeCompare(b, 'ru'))
        .map((name) => ({ value: name, label: name }));

    return (
        [
            // The row opens where the person sits and then says who they are:
            // the department, the unit inside it, the name, then the job and the
            // access that goes with it. The two structure columns carry the same
            // widths as the positions table, which is the other place the
            // company is read down by its structure.
            {
                key: 'department',
                label: 'Департамент',
                width: 240,
                private: false,
                // Each column asks about its own level of the tree, and the two
                // narrow together: the department names the branch — everyone in
                // its units included — and the unit the place inside it. They
                // write their own parameters, so neither throws away what the
                // other has chosen.
                filter: {
                    type: 'multi',
                    param: 'department',
                    // «Без департамента» asks for the people no department has
                    // on its books, whose cell in this column is empty.
                    options: [{ value: NONE, label: 'Без департамента' }, ...departmentOptions(options.departments)],
                },
                cell: (row) => (row.departments?.length ? <Placements departments={row.departments} of="department" /> : <Empty />),
            },
            {
                key: 'unit',
                label: 'Отдел',
                width: 200,
                private: false,
                filter: {
                    type: 'multi',
                    param: 'unit',
                    // «Без отдела» asks for the people filed straight into a
                    // department, whose cell in this column is empty.
                    options: [{ value: NONE, label: 'Без отдела' }, ...departmentOptions(options.units)],
                },
                cell: (row) => (row.departments?.length ? <Placements departments={row.departments} of="unit" /> : <Empty />),
            },
            {
                key: 'name',
                label: 'Сотрудник',
                width: 320,
                // The column that takes whatever room the others leave: a name
                // with a face and an address beside it is the one cell here glad
                // of it, and the width is the floor it fills up from.
                fill: true,
                private: false,
                filter: { type: 'text', param: 'search', placeholder: 'ФИО или почта' },
                cell: (row) => (
                    <div className="flex items-center gap-3">
                        {/* The face opens the photograph it is; the name beside
                            it opens the card. */}
                        <Face row={row} visible={visible} className="size-[38px] text-[13px]" />
                        <div className="flex min-w-0 flex-col gap-0.5">
                            <Link
                                href={route('employees.show', row.id)}
                                className="hover:text-brand-strong font-semibold break-words hover:underline dark:hover:text-[#C5E27A]"
                            >
                                {fullName(row)}
                            </Link>
                            <a
                                href={`mailto:${row.email}`}
                                className="text-brand-strong text-[13px] break-words hover:underline dark:text-[#C5E27A]"
                                title={`Написать: ${row.email}`}
                            >
                                {row.email}
                            </a>
                            {row.status !== 'active' && <LeftBadge row={row} />}
                        </div>
                    </div>
                ),
            },
            {
                key: 'position',
                label: 'Должность',
                width: 240,
                private: false,
                // By title, each one once: «Администратор» stood in the list
                // four times over, once per department, and nothing in it said
                // which of the four was being ticked. Asked by title it finds
                // them all, which is the question somebody is asking.
                filter: { type: 'multi', param: 'position_name', options: titles },
                cell: (row) => (row.positions?.length ? <PositionBadges positions={row.positions} /> : <Empty />),
            },
            {
                key: 'role',
                label: 'Роль',
                width: 240,
                private: false,
                filter: { type: 'multi', param: 'role', options: options.roles.map((r) => ({ value: r.name, label: r.title })) },
                cell: (row) => (row.roles?.length ? <RoleBadges titles={row.roles} /> : <Empty />),
            },
            {
                key: 'languages',
                label: 'Языки',
                width: 260,
                private: false,
                filter: { type: 'multi', param: 'language', options: options.languages.map((l) => ({ value: l.id, label: l.name })) },
                cell: (row) =>
                    row.languages?.length ? (
                        <LanguageBadges
                            languages={row.languages}
                            onPick={(language) => chips.toggle('language', language.id)}
                            isPicked={(language) => chips.has('language', language.id)}
                        />
                    ) : (
                        <Empty />
                    ),
            },
            {
                key: 'birth_date',
                label: 'Дата рождения',
                width: 200,
                private: true,
                filter: { type: 'dates', from: 'birth_from', to: 'birth_to' },
                cell: (_, d) => <span className="tabular-nums">{formatDate(d.birth_date) ?? <Empty />}</span>,
            },
            {
                key: 'sex',
                label: 'Пол',
                // Short as the word is, the head above it is not: the title,
                // the sorting arrows, the funnel and the menu all stand there.
                width: 170,
                private: false,
                filter: {
                    type: 'select',
                    param: 'sex',
                    options: [
                        { value: 'male', label: 'Мужской' },
                        { value: 'female', label: 'Женский' },
                    ],
                },
                cell: (row) => (row.sex ? sexLabels[row.sex] : <Empty />),
            },
            {
                key: 'nationality',
                label: 'Национальность',
                width: 210,
                private: true,
                filter: { type: 'multi', param: 'nationality', options: options.nationalities.map((n) => ({ value: n, label: capitalize(n) })) },
                cell: (_, d) => (d.nationality ? capitalize(d.nationality) : <Empty />),
            },
            {
                key: 'citizenship',
                label: 'Гражданство',
                width: 190,
                private: true,
                filter: { type: 'multi', param: 'citizenship', options: options.citizenships.map((c) => ({ value: c, label: c })) },
                cell: (_, d) =>
                    d.citizenship?.length ? (
                        <CitizenshipBadges
                            countries={d.citizenship}
                            onPick={(country) => chips.toggle('citizenship', country)}
                            isPicked={(country) => chips.has('citizenship', country)}
                        />
                    ) : (
                        <Empty />
                    ),
            },
            {
                key: 'home_address',
                label: 'Домашний адрес',
                width: 280,
                private: true,
                filter: { type: 'text', param: 'address', placeholder: 'Улица, дом…' },
                cell: (_, d) => <span className="whitespace-normal">{d.home_address ?? <Empty />}</span>,
            },
            {
                key: 'phone',
                label: 'Телефон',
                width: 200,
                private: true,
                filter: { type: 'text', param: 'phone', placeholder: 'Цифры номера' },
                cell: (_, d) => <Phones phone={d.phone} sos={d.sos_phone} sosContact={d.sos_contact} />,
            },
            {
                key: 'marital_status',
                label: 'Семейное положение',
                width: 240,
                private: true,
                filter: {
                    type: 'select',
                    param: 'marital_status',
                    options: [
                        { value: 'married', label: 'В браке' },
                        { value: 'single', label: 'Не в браке' },
                    ],
                },
                cell: (row, d) => (d.marital_status ? maritalLabels[row.sex ?? 'male'][d.marital_status] : <Empty />),
            },
            {
                key: 'children',
                label: 'Дети',
                // The same head as «Пол»: a short word with everything the head
                // carries beside it.
                width: 170,
                private: true,
                filter: {
                    type: 'multi',
                    param: 'children',
                    options: [
                        { value: 0, label: 'Нет детей' },
                        { value: 1, label: '1' },
                        { value: 2, label: '2' },
                        { value: 3, label: '3 и более' },
                    ],
                },
                cell: (_, d) => <Children items={d.children} />,
            },
            {
                key: 'hired_at',
                label: 'Начало работы',
                width: 200,
                private: true,
                filter: { type: 'dates', from: 'hired_from', to: 'hired_to' },
                cell: (_, d) => <span className="tabular-nums">{formatDate(d.hired_at) ?? <Empty />}</span>,
            },
        ] as ColumnDef[]
    ).filter((column) => shows(column.key as ColumnKey));
}

/* ------------------------------------------------------ view preferences */

// v5: the columns a viewer gets now depend on the fields they may read, so a
// view saved under the old rules would hide the wrong ones.
// v6: «Отдел / Департамент» became two columns. A view saved under v5 names a
// `department` column that is no longer the same column — it is half of it,
// and 300 pixels wide in a saved width — and knows nothing of the «Отдел»
// beside it. Applying the half of it that still fits would leave a table
// nobody arranged, so the saved views start again here.
const STORAGE_KEY = TABLE_KEYS.employees;
const QUERY_KEY = 'employees.table.query';

function defaultView() {
    return {
        // Nothing to hide by default: a column nobody may read is never built.
        hidden: [] as string[],
        // Nothing pinned either. The name used to be held to the left edge, and
        // a pinned column is drawn before every other whatever the order says —
        // which would put «Сотрудник» in front of the two columns the list is
        // now meant to open with. Whoever wants it stuck there still has the
        // column's own menu.
        pinned: { left: [] as string[], right: [] as string[] },
    };
}

/* ------------------------------------------------------------ URL params */

const DEFAULT_SORT: Sort = { key: 'created', direction: 'desc' };

type QueryValue = string | number | (string | number)[] | null;

function toParams(
    filters: Filters,
    sort: Sort,
    perPage: number,
    defaultPerPage: number,
    status: EmploymentStatus,
): Record<string, Exclude<QueryValue, null>> {
    const params: Record<string, QueryValue> = {
        ...filters,
        status: status === 'active' ? null : status,
        // The opening order is left out; any other goes whole, since the server reads
        // a column named without a direction as ascending.
        sort: isSorted(sort, DEFAULT_SORT) ? sort.key : null,
        direction: isSorted(sort, DEFAULT_SORT) ? sort.direction : null,
        per_page: perPage === defaultPerPage ? null : perPage,
    };

    return Object.fromEntries(
        Object.entries(params).filter(([, value]) => (Array.isArray(value) ? value.length > 0 : value !== null && value !== '')),
    ) as Record<string, Exclude<QueryValue, null>>;
}

/* ---------------------------------------------------------------- page */

export default function Employees({
    employees,
    filters,
    sort,
    perPage,
    perPageOptions,
    visibleFields,
    sortable,
    options,
    status,
    statusCounts,
    canCreate,
}: EmployeesProps) {
    const { auth } = usePage<SharedData>().props;
    const can = useCan();
    // The menu decides for itself which of its actions the viewer may take,
    // and renders nothing when that is none of them.
    const canManage = can('employees.transfer') || can('employees.fire') || can('employees.delete');
    // Through a ref, so the columns are built once yet a chip filters on top of whatever is in force.
    const chipsRef = useRef<ChipFilter>({ toggle: () => undefined, has: () => false });
    const columns = useMemo(
        () =>
            buildColumns(options, visibleFields, {
                toggle: (param, value) => chipsRef.current.toggle(param, value),
                has: (param, value) => chipsRef.current.has(param, value),
            }),
        [options, visibleFields],
    );
    const defaults = useMemo(() => defaultView(), []);
    const { view, setView, pin, toggleHidden, reorder, order } = useTableView(
        STORAGE_KEY,
        columns.map((c) => c.key),
        defaults,
    );
    useRememberedQuery(QUERY_KEY, STORAGE_KEY);
    // Everything the viewer has done to this list — the columns and the
    // questions both — is kept by one press and forgotten by the next.
    const memory = useTableMemory({
        viewKey: STORAGE_KEY,
        queryKey: QUERY_KEY,
        columnKeys: columns.map((c) => c.key),
        view,
        setView,
        fallback: defaults,
    });

    const visit = (next: { filters?: Partial<Filters>; sort?: Sort; perPage?: number; status?: EmploymentStatus }) => {
        router.get(
            route('employees.index'),
            toParams({ ...filters, ...next.filters }, next.sort ?? sort, next.perPage ?? perPage, perPageOptions[0], next.status ?? status),
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };
    const applyFilters = (changes: Partial<Filters>) => visit({ filters: changes });
    chipsRef.current = {
        has: (param, value) => (filters[param] as (number | string)[]).includes(value),
        toggle: (param, value) => {
            const current = filters[param] as (number | string)[];
            applyFilters({ [param]: current.includes(value) ? current.filter((v) => v !== value) : [...current, value] });
        },
    };

    // Typing searches on its own, once the typing stops.
    const [search, setSearch] = useDebouncedSearch(filters.q, (q) => applyFilters({ q }));

    const isHidden = (key: string) => view.hidden.includes(key);

    // A column may carry a filter the viewer must not use on everyone.
    // Everything on screen may be filtered by: what may not be read is not here.
    const canFilter = (column: ColumnDef) => Boolean(column.filter);
    const sortBy = (key: string, direction?: 'asc' | 'desc') =>
        visit({ sort: direction ? { key: key as Sort['key'], direction } : (cycleSort(sort, key, DEFAULT_SORT) as Sort) });

    return (
        <AppLayout breadcrumbs={breadcrumbs} fitViewport>
            <Head title="Сотрудники" />

            <div className="flex flex-1 flex-col gap-4 p-3 max-md:gap-3 md:min-h-0 md:gap-4 md:px-4 md:py-6 lg:px-6">
                <h1 className="text-xl leading-7 font-semibold tracking-[-0.01em] max-md:hidden">Сотрудники</h1>

                {/* On a phone this is one line — the search and the button behind
                which sorting and filters wait — with the lists as chips under it. */}
                <div className="flex flex-wrap items-center gap-2 max-md:flex-nowrap">
                    {/* The lists the page can show, as plain tabs. Choosing one
                        is a visit, so the value is the address's and the strip
                        only says which of them is open. */}
                    {statusCounts && (
                        <Tabs value={status} onValueChange={(next) => visit({ status: next as EmploymentStatus })} className="shrink-0 max-md:hidden">
                            <TabsList aria-label="Списки сотрудников">
                                {statusTabs.map((tab) => (
                                    <TabsTrigger key={tab.status} value={tab.status}>
                                        {tab.label}
                                        <span className="text-muted-foreground text-xs tabular-nums">{statusCounts[tab.status]}</span>
                                    </TabsTrigger>
                                ))}
                            </TabsList>
                        </Tabs>
                    )}

                    {/* The application's own field, with the magnifier laid over
                        its left padding and our own cross at the other end. */}
                    <SearchInput
                        id="employees-search"
                        value={search}
                        onChange={setSearch}
                        placeholder="Поиск по всем полям"
                        className="w-80 min-w-48 max-md:w-auto max-md:min-w-0 max-md:flex-1 md:mr-auto"
                        inputClassName="max-md:bg-card text-sm max-md:rounded-xl max-md:border-transparent max-md:shadow-none"
                    />

                    <MobileListTools
                        columns={columns as unknown as TableColumn[]}
                        filters={filters as unknown as Record<string, unknown>}
                        onFilter={(changes) => applyFilters(changes as Partial<Filters>)}
                        canFilter={(column) => canFilter(column as unknown as ColumnDef)}
                        sort={sort}
                        sortable={sortable}
                        onSort={sortBy}
                        defaultSort={DEFAULT_SORT}
                        defaultSortLabel="Дата добавления"
                        className="bg-card rounded-xl border-transparent shadow-none"
                    />

                    {/* The columns ride in the same menu as the view they are part of. */}
                    <TableMemoryButton memory={memory} className="max-md:hidden">
                        {orderedColumns(columns, order).map((column) => (
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
                    </TableMemoryButton>

                    {canCreate && (
                        // A page of its own: the form runs over several steps.
                        <Button className="h-9 max-md:hidden" asChild>
                            <Link href={route('employees.create')}>
                                <Plus />
                                Добавить сотрудника
                            </Link>
                        </Button>
                    )}
                </div>

                {statusCounts && (
                    // The same lists as chips on one line that scrolls sideways,
                    // bleeding to the screen edges like a native segmented strip.
                    <nav
                        aria-label="Списки сотрудников"
                        className="-mx-3 flex gap-2 overflow-x-auto px-3 [scrollbar-width:none] md:hidden [&::-webkit-scrollbar]:hidden"
                    >
                        {statusTabs.map((tab) => (
                            <button
                                key={tab.status}
                                type="button"
                                onClick={() => visit({ status: tab.status })}
                                aria-current={status === tab.status ? 'page' : undefined}
                                className={cn(
                                    'flex h-8 shrink-0 items-center gap-1.5 rounded-full px-3 text-sm font-medium whitespace-nowrap transition-colors',
                                    status === tab.status
                                        ? 'bg-brand-soft text-foreground font-semibold dark:bg-white/10'
                                        : 'bg-card text-foreground',
                                )}
                            >
                                {tab.label}
                                <span
                                    className={cn(
                                        'text-xs tabular-nums',
                                        status === tab.status ? 'text-muted-foreground font-semibold' : 'text-muted-foreground',
                                    )}
                                >
                                    {statusCounts[tab.status]}
                                </span>
                            </button>
                        ))}
                    </nav>
                )}

                <DataTable
                    columns={columns as unknown as TableColumn[]}
                    rows={employees.data}
                    rowKey={(row) => row.id}
                    renderCell={(column, row) => {
                        const own = column as unknown as ColumnDef;

                        // A private column is a lock for anyone who may not see it.
                        return own.private && !row.private ? (
                            <Lock className="text-muted-foreground/60 size-4" aria-label="Закрытые данные" />
                        ) : (
                            own.cell(row, row.private as PrivateDetails)
                        );
                    }}
                    sort={sort}
                    sortable={sortable}
                    onSort={sortBy}
                    filters={filters as unknown as Record<string, unknown>}
                    onFilter={(changes) => applyFilters(changes as Partial<Filters>)}
                    canFilter={(column) => canFilter(column as unknown as ColumnDef)}
                    view={view}
                    onPin={pin}
                    reorderable
                    onReorder={reorder}
                    onHide={(key) => toggleHidden(key, true)}
                    lockedKey="name"
                    actions={canManage ? (row) => <EmployeeActions employee={row} isSelf={row.id === auth.user?.id} /> : undefined}
                    mobileRow={(row) => (
                        <MobileRow
                            href={route('employees.show', row.id)}
                            leading={<Face row={row} visible={visibleFields} className="size-10 text-[13px]" />}
                            title={fullName(row)}
                            subtitle={mobileSubtitle(row)}
                            trailing={canManage ? <EmployeeActions employee={row} isSelf={row.id === auth.user?.id} /> : undefined}
                        />
                    )}
                    empty={<Empty />}
                    footer={
                        <>
                            {/* A phone keeps the paging and leaves the page size to the default. */}
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
                            <Pagination paginator={employees} className="min-w-0 flex-1" />
                        </>
                    }
                />
            </div>

            {canCreate && <MobileFab href={route('employees.create')} label="Добавить сотрудника" />}
        </AppLayout>
    );
}
