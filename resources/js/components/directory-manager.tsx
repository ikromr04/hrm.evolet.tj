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
import { DirectoryEditor, type DirectoryItem, type Labels } from '@/components/directory-editor';
import { IconChip } from '@/components/equipment-icon';
import { MobileFab } from '@/components/mobile-fab';
import { Pagination } from '@/components/pagination';

import { SearchInput } from '@/components/search-input';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
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
import { useAskedToAdd } from '@/hooks/use-asked-to-add';
import { useCan, type Permission } from '@/lib/access';
import { type CategoryField, type FieldTypeOption } from '@/lib/equipment-fields';
import { equipmentIcons, fallbackIcon } from '@/lib/equipment-icons';
import { plural } from '@/lib/plural';
import { Link, router } from '@inertiajs/react';
import { ChevronDown, LoaderCircle, Lock, MoreHorizontal, Pencil, Plus, Trash2 } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

interface DirectoryManagerProps {
    items: DirectoryItem[];
    /**
     * Whether this list is this person's to change. Reading a directory and
     * changing it are two different rights, and each list has its own pair of
     * them, so the page that opened says which it is: without the
     * second right the same table simply has no controls.
     */
    canEdit: boolean;
    /** Server field that holds the label. */
    field: 'title' | 'name';
    /** Route name prefix, e.g. "directories.languages". */
    route: string;
    labels: Labels;
    /** Link to the employee list filtered by this record; without it the count is plain text. */
    employeesUrl?: (item: DirectoryItem) => string;
    /**
     * The line of a card that link narrows the staff list by. Without it the list
     * refuses the filter — nobody narrows a list by what they may not read — so
     * the number is shown as a number rather than as a way into a refusal.
     */
    employeesField?: Permission;
    /** Header over the count; equipment categories count units, not people. */
    countLabel?: string;
    /** The same count in words, for the line under a name on a phone: one, few, many. */
    countWords?: [string, string, string];
    /** When given, each record is drawn by one of these (equipment categories). */
    icons?: string[];
    /** The parts of the stock a record can belong to, for a list kept in parts. */
    sections?: { value: string; label: string }[];
    /**
     * When given, the dialog also edits what units of the record are described
     * by (equipment categories), choosing from these types of field.
     */
    fieldTypes?: FieldTypeOption[];
    /** What a new record's fields start off as (equipment categories). */
    defaultFields?: CategoryField[];
    /**
     * Whether a record says how it is answered (the documents the company asks
     * for): the list then shows what may be attached to each and who is asked
     * for it, and the window writes both.
     */
    documents?: boolean;
    /**
     * Whether the list is read in an order of its own, dragged into place
     * rather than sorted into it.
     *
     * The papers a company asks for are the case: a checklist is worked
     * through from the top down, and which paper is asked for first is the
     * office's decision rather than the alphabet's.
     */
    orderable?: boolean;
}

/**
 * The search over a list. On a phone it is a filled field without a frame, the
 * way search sits over a list in a mobile app, rather than a bordered input.
 */
export const searchBox =
    'border-input bg-background text-muted-foreground focus-within:ring-ring flex h-9 min-w-48 flex-1 items-center gap-2 rounded-md border px-3 shadow-xs focus-within:ring-2 max-md:rounded-xl max-md:border-0 max-md:bg-card max-md:shadow-none';

/** How many rows a page of a reference list holds, as the staff list offers them. */
const PER_PAGE = [10, 25, 50, 100];

/** And how many it opens with: enough that a short list is read whole without paging at all. */
const PER_PAGE_OPENS = 25;

/** The order a list opens in — by name, which is how the server sends it. */
const DEFAULT_SORT: Sort = { key: 'name', direction: 'asc' };

/** A list nobody has narrowed yet. */
const NOTHING_NARROWED = {
    section: [] as string[],
    /** «Файлом» or «Оригинал сдают», for the papers a company asks for. */
    storage: [] as string[],
    /** «Да» or «Нет»: whether the paper is wanted from everybody. */
    required: [] as string[],
};

/** The two answers the storage column gives, as the funnel offers them. */
const STORAGE = [
    { value: 'file', label: 'Файлом' },
    { value: 'paper', label: 'Оригинал сдают' },
];

/** And the two the «Обязателен» column gives. */
const REQUIRED = [
    { value: 'yes', label: 'Да' },
    { value: 'no', label: 'Нет' },
];

/**
 * A reference list, drawn and kept: the lists under «Справочники» and the
 * categories of equipment beside them.
 *
 * They differ in what a record carries — a category has a drawing, a part of the
 * stock and the fields its units are described by, a language has a name and
 * nothing else — and in nothing besides, so the table, the funnels, the kept
 * arrangements and the window that writes a record down are this one
 * component. The company structure is not one of
 * them: «Подразделения» is a page of its own, since a department has a page of
 * its own, a department above it and the jobs filed in it, and none of that is
 * what a list of citizenships is.
 */
export function DirectoryManager({
    items,
    canEdit,
    field,
    route: routeName,
    labels,
    employeesUrl,
    employeesField,
    countLabel = 'Сотрудников',
    countWords = ['сотрудник', 'сотрудника', 'сотрудников'],
    icons,
    sections,
    fieldTypes,
    defaultFields,
    documents = false,
    orderable = false,
}: DirectoryManagerProps) {
    const can = useCan();
    const opensList = can('employees.view') && (employeesField === undefined || can(employeesField));
    const [query, setQuery] = useState('');
    const [editing, setEditing] = useState<DirectoryItem | 'new' | null>(null);

    // «Добавить …» from the palette lands here with the window already asked for.
    useAskedToAdd(canEdit, () => setEditing('new'));
    const [deleting, setDeleting] = useState<DirectoryItem | null>(null);

    /**
     * The order the list opens in: its own where it has one, by name where it
     * has not.
     *
     * «position» heads no column, which is the point — it is the order the
     * server sends the rows in, and a list resting in it is a list nothing has
     * been sorted by. That is what makes the third press on a column's name
     * mean something here: up, down, and back to the order the office put them
     * in. Sorted by name, the third press used to land on the order it had
     * just left.
     */
    const opening = useMemo<Sort>(() => (orderable ? { key: 'position', direction: 'asc' } : DEFAULT_SORT), [orderable]);
    const [sort, setSort] = useState<Sort>(opening);
    /** Which parts of the list it is narrowed to, where the list is kept in parts. */
    const [narrowing, setNarrowing] = useState<typeof NOTHING_NARROWED>(NOTHING_NARROWED);
    /** What the box over the table is looking for, in everything a row says. */
    const [search, setSearch] = useState('');
    /** And what the funnel on «Подсказка» is looking for, in that line alone. */
    const [hint, setHint] = useState('');

    const columns = useMemo<ColumnDef[]>(
        () => [
            // Which part of the list a record belongs to, where the list is
            // kept in parts: the page it is read on follows from it, so the
            // list is read down the parts first and the names inside them
            // after.
            ...(sections
                ? [
                      {
                          key: 'section',
                          label: 'Раздел',
                          width: 220,
                          filter: { type: 'multi' as const, param: 'section', options: sections },
                      },
                  ]
                : []),
            {
                key: 'name',
                label: 'Название',
                width: 320,
                filter: { type: 'text', param: 'name', placeholder: 'Поиск по названию' },
            },
            // A paper says what is wanted of it, where its original lives and
            // whether everybody is asked for it. How many people have handed it
            // in is a number about the asking rather than about the paper, and
            // the card is where that is read.
            ...(documents
                ? [
                      {
                          key: 'hint',
                          label: 'Подсказка',
                          width: 320,
                          filter: { type: 'text' as const, param: 'hint', placeholder: 'Поиск по подсказке' },
                      },
                      { key: 'storage', label: 'Тип хранения', width: 220, filter: { type: 'multi' as const, param: 'storage', options: STORAGE } },
                      { key: 'required', label: 'Обязателен', width: 210, filter: { type: 'multi' as const, param: 'required', options: REQUIRED } },
                  ]
                : [{ key: 'count', label: countLabel, width: 220 }]),
        ],
        [countLabel, sections, documents],
    );

    const viewKey = `directory:${routeName}`;
    const defaults = useMemo<ViewState>(() => ({ hidden: [], pinned: { left: [], right: [] } }), []);
    const columnKeys = useMemo(() => columns.map((column) => column.key), [columns]);
    const { view, setView, pin, toggleHidden, reorder, order } = useTableView(viewKey, columnKeys, defaults);
    /**
     * Everything this list is narrowed by, as one thing to write down: the
     * sorting, the funnels and the box over the table. The list lives in the
     * page rather than in the address bar, so the view carries it.
     */
    const narrowed = useMemo(() => ({ sort, narrowing, query, search, hint }), [sort, narrowing, query, search, hint]);
    const opened = useMemo(() => ({ sort: opening, narrowing: NOTHING_NARROWED, query: '', search: '', hint: '' }), [opening]);

    // Kept when the viewer says so, not because they once dragged a column.
    const memory = useTableMemory({
        viewKey,
        columnKeys,
        view,
        setView,
        fallback: defaults,
        state: narrowed,
        defaultState: opened,
        onState: (held) => {
            const next = (held ?? opened) as typeof narrowed;

            setSort(next.sort ?? opening);
            setNarrowing({ ...NOTHING_NARROWED, ...(next.narrowing ?? {}) });
            setQuery(next.query ?? '');
            setSearch(next.search ?? '');
            setHint(next.hint ?? '');
        },
    });

    /**
     * How much of the list is drawn at once, and which part of it.
     *
     * A reference list is short enough to read whole and long enough to want
     * paging — sixteen papers, forty categories — and it is all in the page
     * already, so the pages are cut here rather than asked for.
     */
    const [perPage, setPerPage] = useState(PER_PAGE_OPENS);
    const [page, setPage] = useState(1);

    /**
     * Whether a row may be dragged into another place right now.
     *
     * Only on a list that has an order of its own, only for somebody who keeps
     * it, only while it is resting in that order — a row dragged up a list
     * sorted by name would land where nobody could see it — and only while the
     * whole of it is in view: an order is the arrangement of every row, and
     * half a list cannot say what it is.
     */
    const draggable =
        orderable &&
        canEdit &&
        !isSorted(sort, opening) &&
        query.trim() === '' &&
        search.trim() === '' &&
        hint.trim() === '' &&
        narrowing.section.length === 0 &&
        narrowing.storage.length === 0 &&
        narrowing.required.length === 0;

    /**
     * Everything a row says, as one line to look through: its name and the part
     * of the list it is kept in.
     */
    const said = (row: DirectoryItem) =>
        [
            row.label,
            // Everything the row says in its own columns, so that «по всем
            // полям» means what it says: the box used to look at the name and
            // the part of the list alone, and a search for a word that was
            // plainly on the screen came back with nothing.
            row.hint ?? null,
            documents ? (row.on_paper === true ? 'Оригинал сдают' : 'Файлом') : null,
            sections?.find((one) => one.value === row.section)?.label ?? null,
        ]
            .filter(Boolean)
            .join(' ')
            .toLowerCase();

    const visible = useMemo(() => {
        const asked = query.trim().toLowerCase();
        const looking = search.trim().toLowerCase();
        const about = hint.trim().toLowerCase();

        const narrowed = items.filter((row) => {
            if (asked && !row.label.toLowerCase().includes(asked)) return false;
            if (looking && !said(row).includes(looking)) return false;
            if (about && !(row.hint ?? '').toLowerCase().includes(about)) return false;
            // Any of the parts asked for, as a funnel of several always means.
            if (narrowing.section.length > 0 && !narrowing.section.includes(row.section ?? '')) return false;
            if (narrowing.storage.length > 0 && !narrowing.storage.includes(row.on_paper === true ? 'paper' : 'file')) return false;
            if (narrowing.required.length > 0 && !narrowing.required.includes(row.is_required === false ? 'no' : 'yes')) return false;

            return true;
        });
        if (!isSorted(sort, opening)) return narrowed;

        const byName = (a: DirectoryItem, b: DirectoryItem) => a.label.localeCompare(b.label, 'ru');

        /** What a row is worth in the column being sorted by. */
        const worth = (row: DirectoryItem): string | number => {
            if (sort.key === 'count') return row.users_count;
            if (sort.key === 'hint') return (row.hint ?? '').toLowerCase();
            if (sort.key === 'storage') return row.on_paper === true ? 1 : 0;
            if (sort.key === 'required') return row.is_required === false ? 0 : 1;
            if (sort.key === 'section') return sections?.find((one) => one.value === row.section)?.label ?? '';

            return row.label;
        };

        // The name settles every tie, so a column of «Да» and «Нет» reads as
        // two runs in alphabetical order rather than in the order rows
        // happened to arrive in.
        const compare = (a: DirectoryItem, b: DirectoryItem) => {
            const left = worth(a);
            const right = worth(b);
            const by = typeof left === 'number' && typeof right === 'number' ? left - right : String(left).localeCompare(String(right), 'ru');

            return by || byName(a, b);
        };

        return [...narrowed].sort((a, b) => (sort.direction === 'asc' ? compare(a, b) : -compare(a, b)));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [items, query, search, hint, sort, narrowing, sections]);

    /**
     * The list as it reads once the row that was dragged lands where it was
     * dropped, sent whole: what is being saved is an order, and an order is
     * the arrangement of all of it.
     */
    const dropRow = (dragged: string, onto: string, place: 'before' | 'after') => {
        const moved = Number(dragged);
        const rest = visible.map((row) => row.id).filter((id) => id !== moved);
        const at = rest.indexOf(Number(onto)) + (place === 'after' ? 1 : 0);

        router.put(route(`${routeName}.order`), { ids: [...rest.slice(0, at), moved, ...rest.slice(at)] }, { preserveScroll: true });
    };

    /** The part of the list this page shows, and what the foot of the table says about it. */
    const pages = Math.max(1, Math.ceil(visible.length / perPage));
    const current = Math.min(page, pages);
    const shown = visible.slice((current - 1) * perPage, current * perPage);

    // A narrowed list is a shorter list: what was page four of everything is
    // past the end of what is left, and the first page is where somebody who
    // has just searched is looking.
    useEffect(() => {
        setPage(1);
    }, [query, search, hint, narrowing, perPage, sort]);

    /* The table lays the columns out; what a row holds is this list's own. */
    const cell: CellRenderer<DirectoryItem> = (column, row) => {
        if (column.key === 'section') {
            const part = sections?.find((one) => one.value === row.section);

            return part ? <span>{part.label}</span> : <span className="text-muted-foreground">—</span>;
        }

        if (column.key === 'count') {
            const total = row.users_count;

            return (
                <span className="tabular-nums">
                    {total > 0 ? (
                        // The number is a count; following it means reading the
                        // staff, which is a right of its own.
                        employeesUrl && opensList ? (
                            <Link href={employeesUrl(row)} className="text-brand-strong hover:underline dark:text-[#C5E27A]">
                                {total}
                            </Link>
                        ) : (
                            <span>{total}</span>
                        )
                    ) : (
                        <span className="text-muted-foreground">0</span>
                    )}
                </span>
            );
        }

        if (column.key === 'hint') {
            return row.hint ? <span className="text-muted-foreground">{row.hint}</span> : <span className="text-muted-foreground">—</span>;
        }

        if (column.key === 'storage') {
            // Every document is one or more PDFs now, so the only thing left to
            // say about how it arrives is whether it arrives at all.
            return (
                <span className={row.on_paper === true ? undefined : 'text-muted-foreground'}>
                    {row.on_paper === true ? 'Оригинал сдают' : 'Файлом'}
                </span>
            );
        }

        if (column.key === 'required') {
            // «Нет» rather than a blank: the column answers a question about
            // every row, and a row that says nothing reads as a row nobody has
            // filled in.
            return <span>{row.is_required === false ? <span className="text-muted-foreground">Нет</span> : 'Да'}</span>;
        }

        return (
            <span className="flex items-center gap-2">
                {icons && <IconChip icon={(row.icon && equipmentIcons[row.icon]) || fallbackIcon} size={28} iconSize={15} />}
                <span>{row.label}</span>
                {row.protected && <Lock className="text-muted-foreground size-3.5" aria-label="Системная запись: удалить нельзя" />}
            </span>
        );
    };

    const addButton = (
        <Button onClick={() => setEditing('new')}>
            <Plus />
            {labels.add}
        </Button>
    );

    return (
        <>
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
             * A phone gets a list rather than the table: three columns squeezed
             * into 320px leave the names wrapping letter by letter. A row is one
             * target there, not a name and a number side by side, so it leads to
             * the people behind it; changing and deleting sit in the row's menu,
             * outside the link.
             */}
            <Card className="gap-0 overflow-hidden rounded-2xl border-0 p-0 shadow-none md:hidden">
                {visible.length > 0 ? (
                    <ul className="divide-border/70 divide-y">
                        {visible.map((row) => {
                            const count = row.users_count;

                            return (
                                <MobileRow
                                    key={row.id}
                                    href={employeesUrl && opensList && count > 0 ? employeesUrl(row) : null}
                                    leading={
                                        icons ? (
                                            <span className="flex items-center gap-1.5">
                                                <IconChip icon={(row.icon && equipmentIcons[row.icon]) || fallbackIcon} />
                                            </span>
                                        ) : undefined
                                    }
                                    title={
                                        <span className="flex min-w-0 items-center gap-1.5">
                                            <span className="truncate">{row.label}</span>
                                            {row.protected && (
                                                <Lock
                                                    className="text-muted-foreground size-3.5 shrink-0"
                                                    aria-label="Системная запись: удалить нельзя"
                                                />
                                            )}
                                        </span>
                                    }
                                    subtitle={
                                        documents
                                            ? `${row.on_paper === true ? 'Оригинал сдают' : 'Файлом'} · ${count} ${plural(count, countWords)}`
                                            : `${count} ${plural(count, countWords)}`
                                    }
                                    trailing={
                                        canEdit ? (
                                            <DropdownMenu>
                                                <DropdownMenuTrigger asChild>
                                                    <Button variant="ghost" size="icon" className="size-10" aria-label={`Действия: ${row.label}`}>
                                                        <MoreHorizontal className="size-5" />
                                                    </Button>
                                                </DropdownMenuTrigger>
                                                <DropdownMenuContent align="end" className="min-w-44">
                                                    <DropdownMenuItem
                                                        className="min-h-10 text-[15px]"
                                                        aria-label={`Изменить: ${row.label}`}
                                                        onSelect={() => setEditing(row)}
                                                    >
                                                        <Pencil />
                                                        Изменить
                                                    </DropdownMenuItem>
                                                    <DropdownMenuItem
                                                        className="min-h-10 text-[15px] text-[#B42318] focus:text-[#B42318] dark:text-[#F7A19A] dark:focus:text-[#F7A19A] [&_svg]:text-current"
                                                        aria-label={`Удалить: ${row.label}`}
                                                        disabled={row.protected}
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

            {canEdit && <MobileFab onClick={() => setEditing('new')} label={labels.add} />}

            {/*
             * The desktop list is the table the rest of the app uses: its columns
             * sort, narrow, hide, pin and drag like the ones in the employee and
             * equipment lists, and what a viewer makes of them is remembered
             * between visits, once they ask for it. The phone keeps the list
             * above instead — three columns do not fit 390px however they are
             * arranged.
             */}
            <div className="flex min-h-0 flex-1 flex-col gap-4 max-md:hidden">
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
                                // The one column a list cannot be read without:
                                // the record's own name.
                                disabled={column.key === 'name'}
                                onCheckedChange={(checked) => toggleHidden(column.key, !checked)}
                                onSelect={(event) => event.preventDefault()}
                            >
                                {column.label}
                            </DropdownMenuCheckboxItem>
                        ))}
                    </TableMemoryButton>

                    {/* At the end of the line the list is read from, after the
                        funnel: the box narrows what is there, the funnel
                        narrows it further, and this adds to it. On a phone the
                        round button over the tab bar does the same. */}
                    {canEdit && addButton}
                </div>

                <DataTable
                    columns={columns}
                    rows={shown}
                    rowKey={(row) => row.id}
                    renderCell={cell}
                    sort={sort}
                    sortable={columnKeys}
                    onSort={(key, direction) => setSort(direction ? { key, direction } : cycleSort(sort, key, opening))}
                    filters={{
                        name: query,
                        hint,
                        section: narrowing.section,
                        storage: narrowing.storage,
                        required: narrowing.required,
                    }}
                    onFilter={(changes) => {
                        if (typeof changes.name === 'string') setQuery(changes.name);
                        if (typeof changes.hint === 'string') setHint(changes.hint);

                        for (const key of ['section', 'storage', 'required'] as const) {
                            const picked = changes[key];

                            if (Array.isArray(picked)) setNarrowing((held) => ({ ...held, [key]: picked as string[] }));
                        }
                    }}
                    view={view}
                    onPin={pin}
                    reorderable
                    onReorder={reorder}
                    onHide={(key) => toggleHidden(key, true)}
                    lockedKey="name"
                    onRowDrop={draggable ? dropRow : undefined}
                    actionsWidth={112}
                    actions={
                        canEdit
                            ? (row) => (
                                  <div className="flex justify-end gap-1">
                                      <Button
                                          variant="ghost"
                                          size="icon"
                                          className="size-8"
                                          aria-label={`Изменить: ${row.label}`}
                                          onClick={() => setEditing(row)}
                                      >
                                          <Pencil className="size-4" />
                                      </Button>
                                      <Button
                                          variant="ghost"
                                          size="icon"
                                          className="size-8 text-[#B42318] hover:text-[#B42318] dark:text-[#F7A19A]"
                                          aria-label={`Удалить: ${row.label}`}
                                          disabled={row.protected}
                                          onClick={() => setDeleting(row)}
                                      >
                                          <Trash2 className="size-4" />
                                      </Button>
                                  </div>
                              )
                            : undefined
                    }
                    empty={<span className="text-sm">{items.length === 0 ? 'Пока пусто.' : 'Ничего не найдено.'}</span>}
                    footer={
                        <>
                            {/* How many rows a page holds, as the staff list
                                asks it: the same words in the same corner, and
                                the choice is this page's own. */}
                            <div className="text-muted-foreground flex items-center gap-2 text-sm">
                                Строк на странице
                                <DropdownMenu>
                                    <DropdownMenuTrigger asChild>
                                        <Button variant="outline" size="sm" className="h-8 gap-1 px-2.5 tabular-nums">
                                            {perPage}
                                            <ChevronDown className="text-muted-foreground" />
                                        </Button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent align="start" className="min-w-20">
                                        <DropdownMenuRadioGroup value={String(perPage)} onValueChange={(value) => setPerPage(Number(value))}>
                                            {PER_PAGE.map((option) => (
                                                <DropdownMenuRadioItem key={option} value={String(option)} className="tabular-nums">
                                                    {option}
                                                </DropdownMenuRadioItem>
                                            ))}
                                        </DropdownMenuRadioGroup>
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            </div>

                            <Pagination
                                className="min-w-0 flex-1"
                                onPage={setPage}
                                paginator={{
                                    data: shown,
                                    current_page: current,
                                    last_page: pages,
                                    per_page: perPage,
                                    total: visible.length,
                                    from: shown.length === 0 ? null : (current - 1) * perPage + 1,
                                    to: shown.length === 0 ? null : (current - 1) * perPage + shown.length,
                                    path: '',
                                }}
                            />
                        </>
                    }
                />
            </div>

            {editing && (
                <DirectoryEditor
                    key={editing === 'new' ? 'new' : editing.id}
                    item={editing === 'new' ? null : editing}
                    items={items}
                    field={field}
                    routeName={routeName}
                    labels={labels}
                    icons={icons}
                    sections={sections}
                    fieldTypes={fieldTypes}
                    defaultFields={defaultFields}
                    documents={documents}
                    onClose={() => setEditing(null)}
                />
            )}

            <DeleteDialog item={deleting} routeName={routeName} labels={labels} onClose={() => setDeleting(null)} />
        </>
    );
}

function DeleteDialog({ item, routeName, labels, onClose }: { item: DirectoryItem | null; routeName: string; labels: Labels; onClose: () => void }) {
    const [processing, setProcessing] = useState(false);

    const confirm = () => {
        if (!item) return;

        router.delete(route(`${routeName}.destroy`, item.id), {
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
            onSuccess: onClose,
        });
    };

    const affected = item?.users_count ?? 0;

    return (
        <Dialog open={item !== null} onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle className="break-words">
                        Удалить {labels.accusative} «{item ? item.label : ''}»?
                    </DialogTitle>
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
                            <p>Отменить удаление нельзя.</p>
                        </div>
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
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
