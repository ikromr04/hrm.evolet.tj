import { type CardFieldGroup, type PlainRight } from '@/components/card-fields';
import { MobileRow } from '@/components/data-table';
import { DirectoryEditor, rowLabel, type DirectoryItem, type Labels } from '@/components/directory-editor';
import { IconChip } from '@/components/equipment-icon';
import { type EquipmentScope } from '@/components/equipment-scopes';
import { MobileFab } from '@/components/mobile-fab';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { useCan, type AccessRight, type AccessSection, type Permission } from '@/lib/access';
import { type CategoryField, type FieldTypeOption } from '@/lib/equipment-fields';
import { equipmentIcons, fallbackIcon } from '@/lib/equipment-icons';
import { plural } from '@/lib/plural';
import { cn } from '@/lib/utils';
import { Link, router } from '@inertiajs/react';
import { LoaderCircle, Lock, MoreHorizontal, Pencil, Plus, Search, Trash2 } from 'lucide-react';
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
     * Link to the record's own page, where it has one. A department does — with
     * its people, its heads, its sub-departments and its chart — and a language
     * or a citizenship leads nowhere, so the name is a link in that one list and
     * stays plain text in the rest.
     */
    recordUrl?: (item: DirectoryItem) => string;
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
    /**
     * When set, each record also has a short name, written here and shown in
     * brackets after the full one (departments). Everywhere else that short
     * name stands in for the record entirely, so this list is where somebody
     * can still see which department is which.
     */
    abbreviation?: boolean;
    /** Show and edit the parent/child structure (departments). */
    tree?: boolean;
    /** When given, each record is drawn by one of these (equipment categories). */
    icons?: string[];
    /**
     * When given, the dialog also ticks off what the record opens (positions).
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

type Row = DirectoryItem & { depth: number };

/**
 * The search over a list. On a phone it is a filled field without a frame, the
 * way search sits over a list in a mobile app, rather than a bordered input.
 */
export const searchBox =
    'border-input bg-background text-muted-foreground focus-within:ring-ring flex h-10 min-w-48 flex-1 items-center gap-2 rounded-md border px-3 shadow-xs focus-within:ring-2 max-md:rounded-xl max-md:border-0 max-md:bg-card max-md:shadow-none lg:h-8';

/** Items in tree order (parents first, children indented), or as given when flat. */
function orderRows(items: DirectoryItem[], tree: boolean): Row[] {
    if (!tree) return items.map((item) => ({ ...item, depth: 0 }));

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

export function DirectoryManager({
    items,
    canEdit,
    field,
    abbreviation: abbreviated = false,
    route: routeName,
    labels,
    employeesUrl,
    employeesField,
    recordUrl,
    countLabel = 'Сотрудников',
    countWords = ['сотрудник', 'сотрудника', 'сотрудников'],
    tree = false,
    icons,
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
    const [deleting, setDeleting] = useState<DirectoryItem | null>(null);

    const rows = useMemo(() => orderRows(items, tree), [items, tree]);
    const visible = query.trim() ? rows.filter((row) => rowLabel(row).toLowerCase().includes(query.trim().toLowerCase())) : rows;

    return (
        <>
            <div className="flex flex-wrap items-center gap-2 md:-mb-2">
                <label className={searchBox}>
                    <Search className="size-4 shrink-0" />
                    <span className="sr-only">Поиск</span>
                    <input
                        type="search"
                        value={query}
                        onChange={(event) => setQuery(event.target.value)}
                        placeholder="Поиск по названию"
                        className="text-foreground min-w-0 flex-1 bg-transparent text-sm outline-hidden max-md:text-base"
                    />
                </label>
                {canEdit && (
                    // On a phone the round button over the tab bar takes its place.
                    <Button className="h-10 max-md:hidden lg:h-8" onClick={() => setEditing('new')}>
                        <Plus />
                        {labels.add}
                    </Button>
                )}
            </div>

            {/*
             * A phone gets a list rather than the table: three columns squeezed
             * into 320px leave the names wrapping letter by letter. A row is one
             * target there, not a name and a number side by side, so it leads
             * where the name leads on a desktop — the record's own page, for the
             * list that has one — and falls back to the people behind it
             * elsewhere; changing and deleting sit in the row's menu, outside the
             * link.
             */}
            <Card className="gap-0 overflow-hidden rounded-2xl border-0 p-0 shadow-none md:hidden">
                {visible.length > 0 ? (
                    <ul className="divide-border/70 divide-y">
                        {visible.map((row) => {
                            const count = row.total_count ?? row.users_count;
                            // Whether this record counts a branch as well as
                            // itself, which only a tree of departments does.
                            const counted = row.total_count !== undefined;
                            const indent = tree && !query ? Math.min(row.depth, 4) * 14 : 0;

                            return (
                                <MobileRow
                                    key={row.id}
                                    // An empty department is a row to open too: its page
                                    // holds the sub-departments and the chart whether
                                    // anybody is counted in it or not, which the staff
                                    // list behind a zero never did.
                                    href={recordUrl ? recordUrl(row) : employeesUrl && opensList && count > 0 ? employeesUrl(row) : null}
                                    leading={
                                        indent > 0 || icons ? (
                                            <span className="flex items-center gap-1.5" style={{ paddingLeft: indent }}>
                                                {indent > 0 && <span className="text-muted-foreground">└</span>}
                                                {icons && <IconChip icon={(row.icon && equipmentIcons[row.icon]) || fallbackIcon} />}
                                            </span>
                                        ) : undefined
                                    }
                                    title={
                                        <span className="flex min-w-0 items-center gap-1.5">
                                            <span className={cn('truncate', tree && row.depth === 0 && 'font-semibold')}>{rowLabel(row)}</span>
                                            {row.protected && (
                                                <Lock
                                                    className="text-muted-foreground size-3.5 shrink-0"
                                                    aria-label="Системная запись: удалить нельзя"
                                                />
                                            )}
                                        </span>
                                    }
                                    // A list whose records hold other records
                                    // counts twice over — the branch and the
                                    // record itself — and puts both at the
                                    // right, where the two numbers read as one
                                    // answer. A flat list has one number and
                                    // says it in words under the name.
                                    subtitle={counted ? undefined : `${count} ${plural(count, countWords)}`}
                                    meta={
                                        counted ? (
                                            <span className="flex flex-col items-end gap-0.5">
                                                <span className="text-foreground text-[15px] tabular-nums">{count}</span>
                                                {row.total_count !== row.users_count && (
                                                    <span className="tabular-nums">{row.users_count} напрямую</span>
                                                )}
                                            </span>
                                        ) : undefined
                                    }
                                    trailing={
                                        canEdit ? (
                                            <DropdownMenu>
                                                <DropdownMenuTrigger asChild>
                                                    <Button variant="ghost" size="icon" className="size-10" aria-label={`Действия: ${rowLabel(row)}`}>
                                                        <MoreHorizontal className="size-5" />
                                                    </Button>
                                                </DropdownMenuTrigger>
                                                <DropdownMenuContent align="end" className="min-w-44">
                                                    <DropdownMenuItem
                                                        className="min-h-10 text-[15px]"
                                                        aria-label={`Изменить: ${rowLabel(row)}`}
                                                        onSelect={() => setEditing(row)}
                                                    >
                                                        <Pencil />
                                                        Изменить
                                                    </DropdownMenuItem>
                                                    <DropdownMenuItem
                                                        className="min-h-10 text-[15px] text-[#B42318] focus:text-[#B42318] dark:text-[#F7A19A] dark:focus:text-[#F7A19A] [&_svg]:text-current"
                                                        aria-label={`Удалить: ${rowLabel(row)}`}
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

            <Card className="flex flex-col gap-0 overflow-hidden rounded-xl p-0 max-md:hidden md:min-h-0 md:flex-1">
                <div className="overflow-auto md:min-h-0 md:flex-1">
                    <table className="w-full border-collapse text-sm">
                        <thead className="bg-sidebar sticky top-0 z-10 shadow-[0_1px_0_var(--border)]">
                            <tr className="text-muted-foreground text-left text-[13px]">
                                {/* On a phone the fixed widths and the wide gutters give way:
                                    the columns share what there is, and whatever still does
                                    not fit scrolls inside the card rather than the page. */}
                                <th scope="col" className="px-3 py-3 font-semibold md:px-6">
                                    Название
                                </th>
                                <th scope="col" className="px-3 py-3 font-semibold md:w-40 md:px-4">
                                    {countLabel}
                                </th>
                                {canEdit && (
                                    <th scope="col" className="py-3 pr-3 pl-1 md:w-28 md:pr-6 md:pl-4">
                                        <span className="sr-only">Действия</span>
                                    </th>
                                )}
                            </tr>
                        </thead>
                        <tbody>
                            {visible.map((row) => (
                                <tr key={row.id} className="hover:bg-muted/40 border-t">
                                    <td className="px-3 py-2.5 md:px-6">
                                        <span className="flex items-center gap-2" style={{ paddingLeft: query ? 0 : row.depth * 24 }}>
                                            {tree && row.depth > 0 && !query && <span className="text-muted-foreground">└</span>}
                                            {icons && (
                                                <IconChip icon={(row.icon && equipmentIcons[row.icon]) || fallbackIcon} size={28} iconSize={15} />
                                            )}
                                            {recordUrl ? (
                                                // Brand colour and an underline under the cursor, the way this
                                                // app has always drawn a link in a list. The link is the name
                                                // and nothing else — not the cell around it, not the row — so
                                                // the count further along and the two buttons at the end keep
                                                // their own presses, and a child's name leads to the child
                                                // rather than to whatever it is indented under.
                                                <Link
                                                    href={recordUrl(row)}
                                                    className={cn(
                                                        'text-brand-strong hover:underline dark:text-[#C5E27A]',
                                                        tree && row.depth === 0 ? 'font-semibold' : 'font-medium',
                                                    )}
                                                    title={`Открыть ${labels.accusative}: ${rowLabel(row)}`}
                                                >
                                                    {rowLabel(row)}
                                                </Link>
                                            ) : (
                                                <span className={cn(tree && row.depth === 0 && 'font-semibold')}>{rowLabel(row)}</span>
                                            )}
                                            {row.protected && (
                                                <Lock className="text-muted-foreground size-3.5" aria-label="Системная запись: удалить нельзя" />
                                            )}
                                        </span>
                                    </td>
                                    <td className="px-3 py-2.5 tabular-nums md:px-4">
                                        {(row.total_count ?? row.users_count) > 0 ? (
                                            // The number is a count; following it means reading the
                                            // staff, which is a right of its own.
                                            employeesUrl && opensList ? (
                                                <Link href={employeesUrl(row)} className="text-brand-strong hover:underline dark:text-[#C5E27A]">
                                                    {row.total_count ?? row.users_count}
                                                </Link>
                                            ) : (
                                                <span>{row.total_count ?? row.users_count}</span>
                                            )
                                        ) : (
                                            <span className="text-muted-foreground">0</span>
                                        )}
                                        {row.total_count !== undefined && row.total_count !== row.users_count && (
                                            <span className="text-muted-foreground" title="Числятся в самом отделе, без подотделов">
                                                {' '}
                                                · {row.users_count} напрямую
                                            </span>
                                        )}
                                    </td>
                                    {canEdit && (
                                        <td className="py-1.5 pr-3 pl-1 md:pr-6 md:pl-4">
                                            <div className="flex justify-end gap-1">
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    className="size-9 lg:size-8"
                                                    aria-label={`Изменить: ${rowLabel(row)}`}
                                                    onClick={() => setEditing(row)}
                                                >
                                                    <Pencil className="size-4" />
                                                </Button>
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    className="size-9 text-[#B42318] hover:text-[#B42318] lg:size-8 dark:text-[#F7A19A]"
                                                    aria-label={`Удалить: ${rowLabel(row)}`}
                                                    disabled={row.protected}
                                                    onClick={() => setDeleting(row)}
                                                >
                                                    <Trash2 className="size-4" />
                                                </Button>
                                            </div>
                                        </td>
                                    )}
                                </tr>
                            ))}

                            {visible.length === 0 && (
                                <tr className="border-t">
                                    <td colSpan={2 + (canEdit ? 1 : 0)} className="text-muted-foreground px-3 py-12 text-center md:px-6">
                                        {items.length === 0 ? 'Пока пусто.' : 'Ничего не найдено.'}
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
            </Card>

            {editing && (
                <DirectoryEditor
                    key={editing === 'new' ? 'new' : editing.id}
                    item={editing === 'new' ? null : editing}
                    items={items}
                    field={field}
                    abbreviated={abbreviated}
                    routeName={routeName}
                    labels={labels}
                    tree={tree}
                    icons={icons}
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

            <DeleteDialog item={deleting} routeName={routeName} labels={labels} tree={tree} items={items} onClose={() => setDeleting(null)} />
        </>
    );
}

function DeleteDialog({
    item,
    items,
    routeName,
    labels,
    tree,
    onClose,
}: {
    item: DirectoryItem | null;
    items: DirectoryItem[];
    routeName: string;
    labels: Labels;
    tree: boolean;
    onClose: () => void;
}) {
    const [processing, setProcessing] = useState(false);
    const children = item && tree ? items.filter((other) => other.parent_id === item.id).length : 0;

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
                        Удалить {labels.accusative} «{item ? rowLabel(item) : ''}»?
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
