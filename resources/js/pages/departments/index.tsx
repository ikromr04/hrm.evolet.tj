import {
    cycleSort,
    DataTable,
    isSorted,
    MobileRow,
    orderedColumns,
    TableMemoryButton,
    useTableMemory,
    useTableView,
    type CellRenderer,
    type ColumnDef,
    type Sort,
    type ViewState,
} from '@/components/data-table';
import { DepartmentEditor } from '@/components/department-editor';
import { departmentTitle } from '@/components/department-name';
import { MobileFab } from '@/components/mobile-fab';
import { PageContent } from '@/components/page-content';
import { PageTitle } from '@/components/page-title';
import { PositionName } from '@/components/position-name';
import { SearchInput } from '@/components/search-input';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { DropdownMenu, DropdownMenuCheckboxItem, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { useAskedToAdd } from '@/hooks/use-asked-to-add';
import AppLayout from '@/layouts/app-layout';
import { plural } from '@/lib/plural';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { LoaderCircle, MoreHorizontal, Pencil, Plus, Trash2 } from 'lucide-react';
import { useCallback, useMemo, useState } from 'react';

/**
 * One department as this list is handed it: both of its names, where it hangs,
 * and the jobs filed in it.
 *
 * The people are not here. A department is made of positions and the staff
 * follow from who holds them, so this list counts the jobs and leaves counting
 * heads to the department's own page.
 */
interface DepartmentItem {
    id: number;
    name: string;
    abbreviation: string | null;
    parent_id: number | null;
    positions_count: number;
    /** The jobs filed here; the number travels only where the viewer may open one. */
    positions: { id: number | null; name: string; unit: { name: string; full_name: string } | null }[];
}

/**
 * A department as a row: the structure read down the page, each name on the
 * level it hangs at. The depth is what the phone indents by; the table says
 * the same thing with two columns instead.
 */
type Row = DepartmentItem & { depth: number };

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Подразделения', href: '/departments' }];

/** The order the list opens in — by name, which is how the server sends it. */
const DEFAULT_SORT: Sort = { key: 'name', direction: 'asc' };

/** What the «Отдел» funnel calls «Без отдела»: a department that is one itself. */
const NO_UNIT = 'none';

/** A list nobody has narrowed yet. */
const NOTHING_NARROWED = {
    department: [] as number[],
    unit: [] as (number | typeof NO_UNIT)[],
    counted: [] as string[],
};

/** The count of this list in words, for the line under a name on a phone. */
const POSITION_WORDS: [string, string, string] = ['должность', 'должности', 'должностей'];

/**
 * Where this list's arrangements are written down. The key is older than the
 * page — the list was a tab of «Справочники» before it moved here — and the
 * filters a viewer has already kept are found under it, so it is not renamed
 * along with the file.
 */
const VIEW_KEY = 'directory:departments';

/** "Департамент маркетинга (ДМ)" — how a department reads in this one list. */
const titleOf = (unit: DepartmentItem) => departmentTitle(unit.name, unit.abbreviation);

/**
 * The departments in tree order: a department, then whatever hangs under it.
 *
 * A unit whose department is missing from the list stands as a department of
 * its own rather than falling out of the order altogether — there is none
 * today, and one orphaned by a delete would otherwise be invisible here while
 * the server goes on sending it.
 */
function orderRows(items: DepartmentItem[]): Row[] {
    const ids = new Set(items.map((item) => item.id));
    const walk = (parentId: number | null, depth: number, seen: Set<number>): Row[] =>
        items
            .filter((item) => (item.parent_id ?? null) === parentId || (parentId === null && item.parent_id != null && !ids.has(item.parent_id)))
            .filter((item) => !seen.has(item.id))
            .flatMap((item) => {
                seen.add(item.id);
                return [{ ...item, depth }, ...walk(item.id, depth + 1, seen)];
            });

    return walk(null, 0, new Set());
}

/**
 * "Подразделения": the tree of departments, written out as a list and kept
 * there. It stands on its own page, headed the way the chart beside it is —
 * the page's own name, and at the end of that line whatever adds to it.
 *
 * The table, its funnels and its kept arrangements are the ones the rest of the
 * app is read through, and the window that writes a department down is the one
 * the department's own page opens. What is this page's alone is in between: the
 * structure read down two columns, the jobs named rather than counted, and the
 * tally under it all.
 */
export default function Departments({ items, canEdit }: { items: DepartmentItem[]; canEdit: boolean }) {
    const [editing, setEditing] = useState<DepartmentItem | 'new' | null>(null);
    const [deleting, setDeleting] = useState<DepartmentItem | null>(null);

    // «Добавить отдел» from the palette lands here with the window already asked for.
    useAskedToAdd(canEdit, () => setEditing('new'));

    const [sort, setSort] = useState<Sort>(DEFAULT_SORT);
    /** Which departments, which of the units inside them, and which jobs the list is narrowed to. */
    const [narrowing, setNarrowing] = useState<typeof NOTHING_NARROWED>(NOTHING_NARROWED);
    /** What the phone's box is looking for, which is the name alone. */
    const [query, setQuery] = useState('');
    /** And what the box over the table is looking for, in everything a row says. */
    const [search, setSearch] = useState('');

    /** A department as a funnel lists it: the short name, with the full one beside it. */
    const option = (unit: DepartmentItem) => ({
        value: unit.id,
        label: unit.abbreviation ?? unit.name,
        hint: unit.abbreviation ? unit.name : undefined,
    });

    const branches = useMemo(() => items.filter((item) => item.parent_id === null).map(option), [items]);
    const inside = useMemo(() => items.filter((item) => item.parent_id !== null).map(option), [items]);

    /**
     * Every job the structure holds, by name and each name once: the same job
     * exists in several departments, and whoever is looking for the analysts is
     * looking for all of them rather than for one department's own.
     */
    const jobs = useMemo(() => {
        const held = new Set<string>();

        items.forEach((item) => item.positions.forEach((position) => held.add(position.name)));

        return [...held].sort((a, b) => a.localeCompare(b, 'ru')).map((name) => ({ value: name, label: name }));
    }, [items]);

    const columns = useMemo<ColumnDef[]>(
        () => [
            {
                key: 'department',
                label: 'Департамент',
                width: 240,
                filter: { type: 'multi', param: 'department', options: branches },
            },
            {
                key: 'unit',
                label: 'Отдел',
                width: 200,
                // «Без отдела» asks for the departments that stand on their own,
                // whose cell in this column is empty.
                filter: { type: 'multi', param: 'unit', options: [{ value: NO_UNIT, label: 'Без отдела' }, ...inside] },
            },
            {
                key: 'count',
                label: 'Должности',
                width: 320,
                ...(jobs.length > 0 ? { filter: { type: 'multi' as const, param: 'counted', options: jobs } } : {}),
            },
        ],
        [branches, inside, jobs],
    );

    const defaults = useMemo<ViewState>(() => ({ hidden: [], pinned: { left: [], right: [] } }), []);
    const columnKeys = useMemo(() => columns.map((column) => column.key), [columns]);
    const { view, setView, pin, toggleHidden, reorder, order } = useTableView(VIEW_KEY, columnKeys, defaults);
    /**
     * Everything this list is narrowed by, as one thing to write down: the
     * sorting, the three funnels and the two boxes. The list lives in the page
     * rather than in the address bar, so the view carries it.
     */
    const narrowed = useMemo(() => ({ sort, narrowing, query, search }), [sort, narrowing, query, search]);
    const opened = useMemo(() => ({ sort: DEFAULT_SORT, narrowing: NOTHING_NARROWED, query: '', search: '' }), []);

    // Kept when the viewer says so, not because they once dragged a column.
    const memory = useTableMemory({
        viewKey: VIEW_KEY,
        columnKeys,
        view,
        setView,
        fallback: defaults,
        state: narrowed,
        defaultState: opened,
        onState: (held) => {
            const next = (held ?? opened) as typeof narrowed;

            setSort(next.sort ?? DEFAULT_SORT);
            setNarrowing(next.narrowing ?? NOTHING_NARROWED);
            setQuery(next.query ?? '');
            setSearch(next.search ?? '');
        },
    });

    const rows = useMemo(() => orderRows(items), [items]);

    /** Every department by its number, for reading a row's way up the tree. */
    const byId = useMemo(() => new Map(items.map((item) => [item.id, item])), [items]);

    /**
     * The department this row hangs under at the top of the tree — itself,
     * where it hangs under nothing. The list is read down two columns, and this
     * is what stands in the first of them.
     */
    const branchOf = useCallback(
        (row: DepartmentItem): DepartmentItem => {
            let held: DepartmentItem = row;

            for (let parent = held.parent_id; parent != null && byId.has(parent); parent = held.parent_id) {
                const above = byId.get(parent)!;
                if (above.id === held.id) break;
                held = above;
            }

            return held;
        },
        [byId],
    );

    /**
     * Everything a row says, as one line to look through: both of its names,
     * both names of the department above it, and the jobs filed in it.
     */
    const said = (row: Row) =>
        [titleOf(row), row.abbreviation, titleOf(branchOf(row)), branchOf(row).abbreviation, ...row.positions.map((position) => position.name)]
            .filter(Boolean)
            .join(' ')
            .toLowerCase();

    const visible = useMemo(() => {
        const asked = query.trim().toLowerCase();
        const looking = search.trim().toLowerCase();
        const narrowed = rows.filter((row) => {
            if (asked && !titleOf(row).toLowerCase().includes(asked)) return false;
            if (looking && !said(row).includes(looking)) return false;
            // The department a row belongs to, and the row itself where it is
            // one of the units inside a department: a department standing on
            // its own is no unit, so a funnel on «Отдел» leaves it out.
            if (narrowing.department.length > 0 && !narrowing.department.includes(branchOf(row).id)) return false;
            if (narrowing.unit.length > 0 && !narrowing.unit.includes(row.parent_id ? row.id : NO_UNIT)) return false;
            // Every job asked for, not any of them: two jobs chosen means the
            // departments that have both.
            if (narrowing.counted.length > 0) {
                const held = row.positions.map((position) => position.name);
                if (!narrowing.counted.every((wanted) => held.includes(wanted))) return false;
            }

            return true;
        });
        if (!isSorted(sort, DEFAULT_SORT)) return narrowed;

        const byName = (a: Row, b: Row) => titleOf(a).localeCompare(titleOf(b), 'ru');
        // «Департамент» sorts by the department first and by the unit inside it
        // after, so the units of one department stay together under it.
        const byBranch = (a: Row, b: Row) => titleOf(branchOf(a)).localeCompare(titleOf(branchOf(b)), 'ru') || byName(a, b);
        const compare =
            sort.key === 'count'
                ? (a: Row, b: Row) => a.positions_count - b.positions_count || byName(a, b)
                : sort.key === 'department'
                  ? byBranch
                  : byName;

        return [...narrowed].sort((a, b) => (sort.direction === 'asc' ? compare(a, b) : -compare(a, b)));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [rows, query, search, sort, branchOf, narrowing]);

    /**
     * A department named the way the staff list names one: the short name on
     * the line, and the full one under it where the two differ. The name leads
     * to the department's own page, at both levels of the tree — the page is
     * open to everybody who signs in, since who works where is nobody's secret.
     */
    const plate = (unit: DepartmentItem, own: boolean) => {
        const short = unit.abbreviation ?? unit.name;
        const full = unit.abbreviation ? unit.name : null;

        return (
            <span className="flex min-w-0 items-start gap-2">
                {/*
                 * Both lines are the name, so both belong to the link — the way
                 * the chart and the staff list draw one. Only the abbreviation
                 * takes the underline: two underlined lines read as two names
                 * rather than one.
                 */}
                <Link
                    href={route('departments.show', unit.id)}
                    className="group text-brand-strong flex min-w-0 flex-col gap-0.5 dark:text-[#C5E27A]"
                    title={`Открыть отдел: ${titleOf(unit)}`}
                >
                    <span className={cn('group-hover:underline', own ? 'font-medium' : 'font-semibold')}>{short}</span>
                    {full && <span className="text-muted-foreground text-[13px] leading-tight">{full}</span>}
                </Link>
            </span>
        );
    };

    /* The table lays the columns out; what a row holds is this page's own. */
    const cell: CellRenderer<Row> = (column, row) => {
        if (column.key === 'department' || column.key === 'unit') {
            const branch = branchOf(row);
            const itself = branch.id === row.id;

            if (column.key === 'department') return plate(branch, itself);

            // A department standing on its own is no unit of anything.
            return itself ? <span className="text-muted-foreground">—</span> : plate(row, true);
        }

        // Named rather than counted: a column that says what a department is
        // made of is read off the row itself, with no cursor to hold anywhere.
        if (row.positions.length === 0) return <span className="text-muted-foreground">—</span>;

        return (
            <div className="flex flex-wrap gap-1 whitespace-normal">
                {row.positions.map((position) => {
                    // The one the funnel is set to is marked, so a list narrowed
                    // to a job says which of them it is.
                    const picked = narrowing.counted.includes(position.name);
                    const badge = (
                        <StatusBadge tone="success" className={cn('h-auto flex-wrap py-[3px] whitespace-normal', picked && 'ring-brand ring-1')}>
                            <PositionName name={position.name} unit={position.unit ?? null} />
                        </StatusBadge>
                    );

                    // The badge is the way to the job for whoever may read that
                    // list, and a word for everybody else.
                    return position.id === null ? (
                        <span key={position.name}>{badge}</span>
                    ) : (
                        <Link
                            key={position.id}
                            href={route('positions.show', position.id)}
                            title={`Открыть: ${position.name}`}
                            className="rounded-md transition-[filter] hover:brightness-95 dark:hover:brightness-125"
                        >
                            {badge}
                        </Link>
                    );
                })}
            </div>
        );
    };

    /**
     * What the list adds up to, counted the way the columns read it: the
     * departments, what hangs inside them, and the jobs filed in both. Only
     * the rows on screen are counted, so narrowing the list narrows the tally
     * with it.
     */
    const summary = (shown: Row[]) => {
        // The departments the rows stand in, each counted once however many of
        // its units are on screen.
        const departments = new Set(shown.map((row) => branchOf(row).id)).size;
        const units = shown.filter((row) => row.parent_id).length;
        const positions = shown.reduce((total, row) => total + row.positions.length, 0);

        return [
            `${departments} ${plural(departments, ['департамент', 'департамента', 'департаментов'])}`,
            `${units} ${plural(units, ['отдел', 'отдела', 'отделов'])}`,
            `${positions} ${plural(positions, POSITION_WORDS)}`,
        ].join(' · ');
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs} fitViewport>
            <Head title="Подразделения" />

            <PageContent className="md:min-h-0">
                <PageTitle>Подразделения</PageTitle>

                <div className="flex flex-wrap items-center gap-2 md:hidden">
                    <SearchInput
                        value={query}
                        onChange={setQuery}
                        placeholder="Поиск по названию"
                        className="min-w-0 flex-1"
                        inputClassName="bg-card h-10 rounded-xl border-transparent text-base shadow-none"
                    />
                </div>

                {/*
                 * A phone gets a list rather than the table: three columns
                 * squeezed into 320px leave the names wrapping letter by letter.
                 * A row is one target there, not a name and its jobs side by
                 * side, so it leads where the name leads on a desktop — the
                 * department's own page, empty or not, since that page holds the
                 * units and the chart whether anybody is counted in it or not;
                 * changing and deleting sit in the row's menu, outside the link.
                 */}
                <Card className="gap-0 overflow-hidden rounded-2xl border-0 p-0 shadow-none md:hidden">
                    {visible.length > 0 ? (
                        <ul className="divide-border/70 divide-y">
                            {visible.map((row) => {
                                // A tree reads as a tree only while its rows keep
                                // the order that puts a unit under its department;
                                // a list narrowed by name has lost it, and the
                                // names then stand flat, each on its own.
                                const indent = query ? 0 : Math.min(row.depth, 4) * 14;

                                return (
                                    <MobileRow
                                        key={row.id}
                                        href={route('departments.show', row.id)}
                                        leading={
                                            indent > 0 ? (
                                                <span className="flex items-center gap-1.5" style={{ paddingLeft: indent }}>
                                                    <span className="text-muted-foreground">└</span>
                                                </span>
                                            ) : undefined
                                        }
                                        title={
                                            <span className="flex min-w-0 items-center gap-1.5">
                                                <span className={cn('truncate', row.depth === 0 && 'font-semibold')}>{titleOf(row)}</span>
                                            </span>
                                        }
                                        subtitle={`${row.positions_count} ${plural(row.positions_count, POSITION_WORDS)}`}
                                        trailing={
                                            canEdit ? (
                                                <DropdownMenu>
                                                    <DropdownMenuTrigger asChild>
                                                        <Button
                                                            variant="ghost"
                                                            size="icon"
                                                            className="size-10"
                                                            aria-label={`Действия: ${titleOf(row)}`}
                                                        >
                                                            <MoreHorizontal className="size-5" />
                                                        </Button>
                                                    </DropdownMenuTrigger>
                                                    <DropdownMenuContent align="end" className="min-w-44">
                                                        <DropdownMenuItem
                                                            className="min-h-10 text-[15px]"
                                                            aria-label={`Изменить: ${titleOf(row)}`}
                                                            onSelect={() => setEditing(row)}
                                                        >
                                                            <Pencil />
                                                            Изменить
                                                        </DropdownMenuItem>
                                                        <DropdownMenuItem
                                                            className="min-h-10 text-[15px] text-[#B42318] focus:text-[#B42318] dark:text-[#F7A19A] dark:focus:text-[#F7A19A] [&_svg]:text-current"
                                                            aria-label={`Удалить: ${titleOf(row)}`}
                                                            onSelect={() => setDeleting(row)}
                                                        >
                                                            <Trash2 />
                                                            Удалить
                                                        </DropdownMenuItem>
                                                    </DropdownMenuContent>
                                                </DropdownMenu>
                                            ) : undefined
                                        }
                                    />
                                );
                            })}
                        </ul>
                    ) : (
                        <p className="text-muted-foreground px-4 py-12 text-center text-[15px]">
                            {items.length === 0 ? 'Пока пусто.' : 'Ничего не найдено.'}
                        </p>
                    )}
                </Card>

                {canEdit && <MobileFab onClick={() => setEditing('new')} label="Добавить отдел" />}

                {/*
                 * The desktop list is the table the rest of the app uses: its
                 * columns sort, narrow, hide, pin and drag like the ones in the
                 * employee and equipment lists, and what a viewer makes of them
                 * is remembered between visits, once they ask for it. The phone
                 * keeps the list above instead — three columns do not fit 390px
                 * however they are arranged.
                 */}
                <div className="flex items-center gap-2">
                    {/* Everything the list holds, looked through from one box. */}
                    <SearchInput
                        id="directory-search"
                        value={search}
                        onChange={setSearch}
                        placeholder="Поиск по всем полям"
                        className="mr-auto w-80"
                    />

                    {/* The columns ride in the same menu as the view they are part of. */}
                    <TableMemoryButton memory={memory}>
                        {orderedColumns(columns, order).map((column) => (
                            <DropdownMenuCheckboxItem
                                key={column.key}
                                checked={!view.hidden.includes(column.key)}
                                // The one column this list cannot be read
                                // without: whose department a row is.
                                disabled={column.key === 'department'}
                                onCheckedChange={(checked) => toggleHidden(column.key, !checked)}
                                onSelect={(event) => event.preventDefault()}
                            >
                                {column.label}
                            </DropdownMenuCheckboxItem>
                        ))}
                    </TableMemoryButton>

                    {/*
                     * This page has no line of tabs to hand the button to —
                     * the one over it belongs to «Структура компании» — so
                     * «Добавить отдел» stands at the end of the list's own
                     * line, after the button that chooses the view.
                     */}
                    {canEdit && (
                        <Button onClick={() => setEditing('new')}>
                            <Plus />
                            Добавить отдел
                        </Button>
                    )}
                </div>

                <DataTable
                    columns={columns}
                    rows={visible}
                    rowKey={(row) => row.id}
                    renderCell={cell}
                    sort={sort}
                    sortable={['department', 'unit', 'count']}
                    onSort={(key, direction) => setSort(direction ? { key, direction } : cycleSort(sort, key, DEFAULT_SORT))}
                    filters={{
                        department: narrowing.department,
                        unit: narrowing.unit,
                        counted: narrowing.counted,
                    }}
                    onFilter={(changes) => {
                        const picked = <T,>(key: 'department' | 'unit' | 'counted') =>
                            Array.isArray(changes[key]) ? (changes[key] as T[]) : undefined;
                        const department = picked<number>('department');
                        const unit = picked<number | typeof NO_UNIT>('unit');
                        const chosen = picked<string>('counted');

                        if (department || unit || chosen) {
                            setNarrowing((held) => ({
                                department: department ?? held.department,
                                unit: unit ?? held.unit,
                                counted: chosen ?? held.counted,
                            }));
                        }
                    }}
                    view={view}
                    onPin={pin}
                    reorderable
                    onReorder={reorder}
                    onHide={(key) => toggleHidden(key, true)}
                    lockedKey="department"
                    actionsWidth={112}
                    actions={
                        canEdit
                            ? (row) => (
                                  <div className="flex justify-end gap-1">
                                      <Button
                                          variant="ghost"
                                          size="icon"
                                          className="size-8"
                                          aria-label={`Изменить: ${titleOf(row)}`}
                                          onClick={() => setEditing(row)}
                                      >
                                          <Pencil className="size-4" />
                                      </Button>
                                      <Button
                                          variant="ghost"
                                          size="icon"
                                          className="size-8 text-[#B42318] hover:text-[#B42318] dark:text-[#F7A19A]"
                                          aria-label={`Удалить: ${titleOf(row)}`}
                                          onClick={() => setDeleting(row)}
                                      >
                                          <Trash2 className="size-4" />
                                      </Button>
                                  </div>
                              )
                            : undefined
                    }
                    empty={<span className="text-sm">{items.length === 0 ? 'Пока пусто.' : 'Ничего не найдено.'}</span>}
                />

                {/* What the list adds up to — the rows it is showing, so the
                        tally answers whatever was just asked of it. */}
                <p className="text-muted-foreground shrink-0 text-[13px]">{summary(visible)}</p>

                {/* The same window the department's own page opens, saving
                    through the same address: a department is written down in one
                    place, whichever of the two it is reached from. */}
                {editing && (
                    <DepartmentEditor
                        key={editing === 'new' ? 'new' : editing.id}
                        department={editing === 'new' ? null : editing}
                        departments={items}
                        onClose={() => setEditing(null)}
                    />
                )}

                <DeleteDialog unit={deleting} units={items} onClose={() => setDeleting(null)} />
            </PageContent>
        </AppLayout>
    );
}

/**
 * «Удалить отдел «…»?» — what goes with it, and what does not.
 *
 * A department goes and its units stay, each on the level above the one it was
 * on, so the window says so before the press: nobody deleting a department
 * means to lose the four real departments filed under it.
 */
function DeleteDialog({ unit, units, onClose }: { unit: DepartmentItem | null; units: DepartmentItem[]; onClose: () => void }) {
    const [processing, setProcessing] = useState(false);
    const children = unit ? units.filter((other) => other.parent_id === unit.id).length : 0;
    // The number this window has always shown: the jobs filed in the
    // department, which is what the list counts and all it is handed.
    const affected = unit?.positions_count ?? 0;

    const confirm = () => {
        if (!unit) return;

        router.delete(route('departments.destroy', unit.id), {
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
            onSuccess: onClose,
        });
    };

    return (
        <Dialog open={unit !== null} onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle className="break-words">Удалить отдел «{unit ? titleOf(unit) : ''}»?</DialogTitle>
                    <DialogDescription asChild>
                        <div className="flex flex-col gap-1.5">
                            {affected > 0 ? (
                                <p>
                                    Запись снимется у {affected} {plural(affected, ['сотрудника', 'сотрудников', 'сотрудников'])}. Самих сотрудников
                                    это не затронет.
                                </p>
                            ) : (
                                <p>Сотрудников с этой записью нет.</p>
                            )}
                            {children > 0 && <p>Вложенные отделы ({children}) останутся и станут отделами верхнего уровня.</p>}
                            <p>Отменить удаление нельзя.</p>
                        </div>
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter className="gap-2">
                    <Button type="button" variant="outline" className="max-md:h-11" onClick={onClose}>
                        Отмена
                    </Button>
                    <Button type="button" variant="destructive" className="max-md:h-11" onClick={confirm} disabled={processing}>
                        {processing && <LoaderCircle className="animate-spin" />}
                        Удалить
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
