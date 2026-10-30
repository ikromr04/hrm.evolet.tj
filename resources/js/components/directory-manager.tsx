import { type CardFieldGroup, type PlainRight } from '@/components/card-fields';
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
import { type EquipmentScope } from '@/components/equipment-scopes';
import { MobileFab } from '@/components/mobile-fab';
import { PageActions } from '@/components/page-actions';

import { SearchInput } from '@/components/search-input';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { DropdownMenu, DropdownMenuCheckboxItem, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { useAskedToAdd } from '@/hooks/use-asked-to-add';
import { useCan, type AccessRight, type AccessSection, type Permission } from '@/lib/access';
import { type CategoryField, type FieldTypeOption } from '@/lib/equipment-fields';
import { equipmentIcons, fallbackIcon } from '@/lib/equipment-icons';
import { plural } from '@/lib/plural';
import { Link, router } from '@inertiajs/react';
import { LoaderCircle, Lock, MoreHorizontal, Pencil, Plus, Trash2 } from 'lucide-react';
import { useMemo, useState } from 'react';

interface DirectoryManagerProps {
    items: DirectoryItem[];
    /**
     * Whether this list is this person's to change. Reading a directory and
     * changing it are two different rights, and each of the five lists has its
     * own pair of them, so the page that opened says which it is: without the
     * second right the same table simply has no controls.
     */
    canEdit: boolean;
    /** Server field that holds the label. */
    field: 'title' | 'name';
    /** Route name prefix, e.g. "directories.roles". */
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
     * When set, the dialog also ticks off what the record opens (positions).
     * Absent for anybody who may not decide on access, and the rights are then
     * left exactly as they are.
     */
    rights?: AccessSection[];
    /**
     * When given, the dialog also edits what units of the record are described
     * by (equipment categories), choosing from these types of field.
     */
    fieldTypes?: FieldTypeOption[];
    /**
     * When given, the dialog also chooses which lines of an employee card the
     * record reads and may change (positions). Absent for anybody who may not
     * decide on access.
     */
    cardFields?: CardFieldGroup[];
    /**
     * The same lines again, but as the rights to one's own card: what a person
     * holding the record sees and may change on themselves (positions).
     */
    profileFields?: CardFieldGroup[];
    /**
     * When given, the dialog also chooses how much of the fleet the record sees
     * — one's own, the department's, everything — and whose journal it reads
     * (positions).
     */
    equipmentScopes?: EquipmentScope[];
    /**
     * When given, the dialog also chooses which blocks of a unit's card the
     * record may change (positions).
     */
    equipmentBlocks?: AccessRight[];
    /**
     * When given, the dialog also chooses which moves the record makes with a
     * unit — putting it on the balance, handing it over, writing it off
     * (positions).
     */
    equipmentActions?: AccessRight[];
    /**
     * When given, the dialog also chooses which of the five reference lists the
     * record opens (positions). "Справочники" is not one right but five, so the
     * section is ticked list by list rather than as a whole.
     */
    directoryLists?: PlainRight[];
    /**
     * The same five lists again, but as the right to change them: each names the
     * viewing right it depends on, so one cannot be ticked without the other.
     */
    directoryEdits?: PlainRight[];
    /** What a new record's fields start off as (equipment categories). */
    defaultFields?: CategoryField[];
    /** What a new record starts with, right by right (positions); from the server. */
    defaultRights?: string[];
}

/**
 * The search over a list. On a phone it is a filled field without a frame, the
 * way search sits over a list in a mobile app, rather than a bordered input.
 */
export const searchBox =
    'border-input bg-background text-muted-foreground focus-within:ring-ring flex h-9 min-w-48 flex-1 items-center gap-2 rounded-md border px-3 shadow-xs focus-within:ring-2 max-md:rounded-xl max-md:border-0 max-md:bg-card max-md:shadow-none';

/** The order a list opens in — by name, which is how the server sends it. */
const DEFAULT_SORT: Sort = { key: 'name', direction: 'asc' };

/** A list nobody has narrowed yet. */
const NOTHING_NARROWED = {
    section: [] as string[],
};

/**
 * A reference list, drawn and kept: the five lists under «Справочники» and the
 * categories of equipment beside them.
 *
 * They differ in what a record carries — a category has a drawing and a part of
 * the stock, a position carries the rights it opens — and in nothing else, so
 * the table, the funnels, the kept arrangements and the window that writes a
 * record down are this one component. The company structure is not one of
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
    rights,
    fieldTypes,
    defaultFields,
    cardFields,
    profileFields,
    equipmentScopes,
    equipmentBlocks,
    equipmentActions,
    directoryLists,
    directoryEdits,
    defaultRights,
}: DirectoryManagerProps) {
    const can = useCan();
    const opensList = can('employees.view') && (employeesField === undefined || can(employeesField));
    const [query, setQuery] = useState('');
    const [editing, setEditing] = useState<DirectoryItem | 'new' | null>(null);

    // «Добавить …» from the palette lands here with the window already asked for.
    useAskedToAdd(canEdit, () => setEditing('new'));
    const [deleting, setDeleting] = useState<DirectoryItem | null>(null);

    const [sort, setSort] = useState<Sort>(DEFAULT_SORT);
    /** Which parts of the list it is narrowed to, where the list is kept in parts. */
    const [narrowing, setNarrowing] = useState<typeof NOTHING_NARROWED>(NOTHING_NARROWED);
    /** What the box over the table is looking for, in everything a row says. */
    const [search, setSearch] = useState('');

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
            { key: 'count', label: countLabel, width: 200 },
        ],
        [countLabel, sections],
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
    const narrowed = useMemo(() => ({ sort, narrowing, query, search }), [sort, narrowing, query, search]);
    const opened = useMemo(() => ({ sort: DEFAULT_SORT, narrowing: NOTHING_NARROWED, query: '', search: '' }), []);

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

            setSort(next.sort ?? DEFAULT_SORT);
            setNarrowing(next.narrowing ?? NOTHING_NARROWED);
            setQuery(next.query ?? '');
            setSearch(next.search ?? '');
        },
    });

    /**
     * Everything a row says, as one line to look through: its name and the part
     * of the list it is kept in.
     */
    const said = (row: DirectoryItem) =>
        [row.label, sections?.find((one) => one.value === row.section)?.label ?? null].filter(Boolean).join(' ').toLowerCase();

    const visible = useMemo(() => {
        const asked = query.trim().toLowerCase();
        const looking = search.trim().toLowerCase();
        const narrowed = items.filter((row) => {
            if (asked && !row.label.toLowerCase().includes(asked)) return false;
            if (looking && !said(row).includes(looking)) return false;
            // Any of the parts asked for, as a funnel of several always means.
            if (narrowing.section.length > 0 && !narrowing.section.includes(row.section ?? '')) return false;

            return true;
        });
        if (!isSorted(sort, DEFAULT_SORT)) return narrowed;

        const byName = (a: DirectoryItem, b: DirectoryItem) => a.label.localeCompare(b.label, 'ru');
        const compare = sort.key === 'count' ? (a: DirectoryItem, b: DirectoryItem) => a.users_count - b.users_count || byName(a, b) : byName;

        return [...narrowed].sort((a, b) => (sort.direction === 'asc' ? compare(a, b) : -compare(a, b)));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [items, query, search, sort, narrowing, sections]);

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
            {/*
             * On a desktop the button sits at the end of the tab line, where the
             * design puts it, so it is handed to the shell that draws that line;
             * on a phone the round button over the tab bar takes its place, and
             * the search is the phone's alone.
             */}
            {canEdit && <PageActions>{addButton}</PageActions>}

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
                                    subtitle={`${count} ${plural(count, countWords)}`}
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
                </div>

                <DataTable
                    columns={columns}
                    rows={visible}
                    rowKey={(row) => row.id}
                    renderCell={cell}
                    sort={sort}
                    sortable={sections ? ['section', 'name', 'count'] : ['name', 'count']}
                    onSort={(key, direction) => setSort(direction ? { key, direction } : cycleSort(sort, key, DEFAULT_SORT))}
                    filters={{
                        name: query,
                        section: narrowing.section,
                    }}
                    onFilter={(changes) => {
                        if (typeof changes.name === 'string') setQuery(changes.name);

                        const part = Array.isArray(changes.section) ? (changes.section as string[]) : undefined;

                        if (part) setNarrowing((held) => ({ ...held, section: part }));
                    }}
                    view={view}
                    onPin={pin}
                    reorderable
                    onReorder={reorder}
                    onHide={(key) => toggleHidden(key, true)}
                    lockedKey="name"
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
                    rights={rights}
                    fieldTypes={fieldTypes}
                    defaultFields={defaultFields}
                    cardFields={cardFields}
                    profileFields={profileFields}
                    equipmentScopes={equipmentScopes}
                    equipmentBlocks={equipmentBlocks}
                    equipmentActions={equipmentActions}
                    directoryLists={directoryLists}
                    directoryEdits={directoryEdits}
                    defaultRights={defaultRights}
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
