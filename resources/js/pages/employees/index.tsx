import { CitizenshipBadges } from '@/components/citizenship-badges';
import {
    clearedFilter,
    countActiveFilters,
    cycleSort,
    DataTable,
    isSorted,
    MobileListTools,
    MobileRow,
    resetView,
    useDebouncedSearch,
    useRememberedQuery,
    useTableView,
    type ColumnDef as TableColumn,
    type FilterDef as TableFilter,
} from '@/components/data-table';
import { DepartmentName } from '@/components/department-name';
import { EmployeeActions, type EmploymentStatus } from '@/components/employee-actions';
import { LanguageBadges } from '@/components/language-badges';
import { MobileFab } from '@/components/mobile-fab';
import { Pagination, type Paginated } from '@/components/pagination';
import { PersonAvatar } from '@/components/person-avatar';
import { Phones } from '@/components/phones';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
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
import { useCan } from '@/lib/access';
import { capitalize, formatDate, maritalLabels, sexLabels, type Marital, type PrivateDetails, type Sex, type SpokenLanguage } from '@/lib/employee';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { ChevronDown, Columns3, Crown, Lock, Plus, RotateCcw, Search, X } from 'lucide-react';
import { useMemo, useRef, type ReactNode } from 'react';

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
    /** Access roles, shown as "Позиция". */
    roles?: string[];
    /** Positions, shown as "Должность"; an employee can hold several. */
    positions?: string[];
    departments?: { id: number; name: string; full_name: string; path: string; full_path: string; is_head: boolean }[];
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
    department: number[];
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
        positions: { id: number; name: string }[];
        /** The department tree flattened, parents first. */
        departments: { id: number; name: string; full_name: string; depth: number }[];
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
          param: 'role' | 'position' | 'department' | 'language' | 'nationality' | 'citizenship' | 'children';
          options: Option<string | number>[];
      }
    | { type: 'dates'; from: 'birth_from' | 'hired_from'; to: 'birth_to' | 'hired_to' };

interface ColumnDef {
    key: ColumnKey;
    label: string;
    width: number;
    private: boolean;
    filter?: FilterDef;
    cell: (row: EmployeeRow, details: PrivateDetails) => ReactNode;
}

/* ---------------------------------------------------------------- helpers */

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Сотрудники', href: '/employees' }];

const fullName = (row: EmployeeRow) => [row.surname, row.name].filter(Boolean).join(' ');

function RoleBadges({ titles }: { titles: string[] }) {
    return (
        <div className="flex flex-wrap gap-1 whitespace-normal">
            {titles.map((title) => (
                <StatusBadge key={title} tone="info">
                    {title}
                </StatusBadge>
            ))}
        </div>
    );
}

function PositionBadges({ titles }: { titles: string[] }) {
    return (
        <div className="flex flex-wrap gap-1 whitespace-normal">
            {titles.map((title) => (
                <StatusBadge key={title} tone="success">
                    {title}
                </StatusBadge>
            ))}
        </div>
    );
}

function DepartmentBadges({ departments }: { departments: NonNullable<EmployeeRow['departments']> }) {
    return (
        <div className="flex flex-wrap gap-1 whitespace-normal">
            {departments.map((department) => (
                <Link
                    key={department.id}
                    href={route('departments.show', department.id)}
                    className="group focus-visible:ring-ring rounded-md outline-hidden focus-visible:ring-2"
                >
                    <StatusBadge
                        tone="neutral"
                        title={department.is_head ? `${department.full_path} · руководитель` : department.full_path}
                        className="h-auto min-h-[22px] gap-1 py-0.5 whitespace-normal transition-colors group-hover:bg-[#E4E4E7] group-hover:text-[#18181B] dark:group-hover:bg-white/20 dark:group-hover:text-white"
                    >
                        {department.is_head && <Crown className="size-3 shrink-0 text-[#9A4A06] dark:text-[#F8C471]" aria-label="Руководитель" />}
                        <DepartmentName name={department.name} full={department.full_name} tooltip={false} />
                    </StatusBadge>
                </Link>
            ))}
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
                className={cn('bg-muted text-muted-foreground flex shrink-0 items-center justify-center rounded-full', className)}
                title="Фотография закрыта"
                aria-label="Фотография закрыта"
            >
                <Lock className="size-4" />
            </span>
        );
    }

    return row.avatar ? (
        <img src={row.avatar} alt="" className={cn('shrink-0 rounded-full object-cover', className)} />
    ) : (
        <PersonAvatar name={`${row.name} ${row.surname}`} className={className} />
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

    return [row.positions, row.roles].find((titles) => titles?.length)?.join(', ') || row.email || undefined;
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

    return (
        [
            {
                key: 'name',
                label: 'Сотрудник',
                width: 320,
                private: false,
                filter: { type: 'text', param: 'search', placeholder: 'ФИО или почта' },
                cell: (row) => (
                    <div className="flex items-center gap-3">
                        <Face row={row} visible={visible} className="size-[38px] text-[13px]" />
                        <div className="flex min-w-0 flex-col gap-0.5">
                            <Link
                                href={route('employees.show', row.id)}
                                className="hover:text-brand-strong truncate font-semibold hover:underline dark:hover:text-[#C5E27A]"
                            >
                                {fullName(row)}
                            </Link>
                            <a
                                href={`mailto:${row.email}`}
                                className="text-brand-strong truncate text-[13px] hover:underline dark:text-[#C5E27A]"
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
                key: 'role',
                label: 'Позиция',
                width: 240,
                private: false,
                filter: { type: 'multi', param: 'role', options: options.roles.map((r) => ({ value: r.name, label: r.title })) },
                cell: (row) => (row.roles?.length ? <RoleBadges titles={row.roles} /> : <Empty />),
            },
            {
                key: 'department',
                label: 'Отдел / Департамент',
                width: 300,
                private: false,
                filter: {
                    type: 'multi',
                    param: 'department',
                    options: options.departments.map((d) => ({
                        value: d.id,
                        label: d.name,
                        hint: d.full_name === d.name ? undefined : d.full_name,
                        depth: d.depth,
                    })),
                },
                cell: (row) => (row.departments?.length ? <DepartmentBadges departments={row.departments} /> : <Empty />),
            },
            {
                key: 'position',
                label: 'Должность',
                width: 240,
                private: false,
                filter: { type: 'multi', param: 'position', options: options.positions.map((t) => ({ value: t.id, label: t.name })) },
                cell: (row) => (row.positions?.length ? <PositionBadges titles={row.positions} /> : <Empty />),
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
                width: 130,
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
                width: 130,
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
const STORAGE_KEY = 'employees.table.view.v5';

function defaultView() {
    return {
        // Nothing to hide by default: a column nobody may read is never built.
        hidden: [] as string[],
        pinned: { left: ['name'], right: [] as string[] },
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
    const { view, setView, pin, toggleHidden } = useTableView(
        STORAGE_KEY,
        columns.map((c) => c.key),
        defaults,
    );
    useRememberedQuery('employees.table.query');

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
    const activeFilters = countActiveFilters(columns as unknown as TableColumn[], filters as unknown as Record<string, unknown>, (column) =>
        canFilter(column as unknown as ColumnDef),
    );

    // A sorting of the viewer's own counts as one more change to reset.
    const changed = activeFilters + (isSorted(sort, DEFAULT_SORT) ? 1 : 0);
    const resetAll = () =>
        visit({
            filters: columns.filter(canFilter).reduce<Partial<Filters>>((acc, c) => ({ ...acc, ...clearedFilter(c.filter!) }), {}),
            sort: DEFAULT_SORT,
        });

    const sortBy = (key: string, direction?: 'asc' | 'desc') =>
        visit({ sort: direction ? { key: key as Sort['key'], direction } : (cycleSort(sort, key, DEFAULT_SORT) as Sort) });

    return (
        <AppLayout breadcrumbs={breadcrumbs} fitViewport>
            <Head title="Сотрудники" />

            <div className="flex flex-1 flex-col gap-4 p-3 max-md:gap-3 md:min-h-0 md:px-5 md:py-4">
                {/* The phone's top bar already carries the page title. */}
                <h1 className="text-xl font-semibold tracking-tight max-md:sr-only">Сотрудники</h1>

                {/* On a phone this is one line — the search and the button behind
                which sorting and filters wait — with the lists as chips under it. */}
                <div className="-mb-2 flex flex-wrap items-center gap-2 max-md:mb-0 max-md:flex-nowrap">
                    <label className="border-input bg-background text-muted-foreground focus-within:ring-ring max-md:bg-card flex h-10 min-w-48 flex-1 items-center gap-2 rounded-md border px-3 shadow-xs focus-within:ring-2 max-md:min-w-0 max-md:rounded-xl max-md:border-transparent max-md:shadow-none lg:h-8">
                        <Search className="size-4 shrink-0" />
                        <span className="sr-only">Поиск по всем полям</span>
                        <input
                            type="search"
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder="Поиск по всем полям"
                            className="text-foreground min-w-0 flex-1 bg-transparent text-sm outline-hidden"
                        />
                    </label>

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
                        onReset={resetAll}
                        className="bg-card rounded-xl border-transparent shadow-none"
                    />

                    {statusCounts && (
                        <nav aria-label="Списки сотрудников" className="flex flex-wrap items-center gap-1 text-sm max-md:hidden">
                            {statusTabs.map((tab) => (
                                <button
                                    key={tab.status}
                                    type="button"
                                    onClick={() => visit({ status: tab.status })}
                                    aria-current={status === tab.status ? 'page' : undefined}
                                    className={cn(
                                        'flex h-10 items-center gap-1.5 rounded-md px-2.5 whitespace-nowrap transition-colors lg:h-8',
                                        status === tab.status
                                            ? 'bg-brand-soft text-foreground font-semibold dark:bg-white/10'
                                            : 'text-muted-foreground hover:bg-accent hover:text-foreground',
                                    )}
                                >
                                    {tab.label}
                                    <span className="text-muted-foreground text-xs font-semibold tabular-nums">{statusCounts[tab.status]}</span>
                                </button>
                            ))}
                        </nav>
                    )}

                    {/* The phone's filter sheet has its own "reset all". */}
                    {changed > 0 && (
                        <Button variant="ghost" className="h-10 max-md:hidden lg:h-8" onClick={resetAll}>
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

                    {canCreate && (
                        // A page of its own: the form runs over several steps.
                        <Button className="h-10 max-md:hidden lg:h-8" asChild>
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
                    wideFilter={(column) => {
                        // The department tree needs more room than a short list.
                        const filter = column.filter as TableFilter | undefined;

                        return filter?.type === 'multi' && filter.param === 'department';
                    }}
                    view={view}
                    onPin={pin}
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
