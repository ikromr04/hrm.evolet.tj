import {
    CardFields,
    countCardFields,
    PlainRights,
    readsAnyCardLine,
    type CardFieldGroup,
    type CardFieldsMode,
    type PlainRight,
} from '@/components/card-fields';
import { CategoryFieldsEditor } from '@/components/category-fields-editor';
import { MobileRow } from '@/components/data-table';
import { IconChip } from '@/components/equipment-icon';
import { countEquipmentScopes, EquipmentScopes, type EquipmentScope } from '@/components/equipment-scopes';
import InputError from '@/components/input-error';
import { MobileFab } from '@/components/mobile-fab';
import { PersonLink } from '@/components/person-link';
import { PeoplePicker, type PickablePerson } from '@/components/person-picker';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { useCan, type AccessRight, type AccessSection, type Permission } from '@/lib/access';
import { type CategoryField, type FieldTypeOption } from '@/lib/equipment-fields';
import { equipmentIcons, fallbackIcon } from '@/lib/equipment-icons';
import { plural } from '@/lib/plural';
import { cn } from '@/lib/utils';
import { Link, router, useForm } from '@inertiajs/react';
import { LoaderCircle, Lock, MoreHorizontal, Pencil, Plus, Search, Trash2 } from 'lucide-react';
import { useMemo, useState, type FormEventHandler, type ReactNode } from 'react';

export interface DirectoryItem {
    id: number;
    label: string;
    /** Working employees who hold it; for departments, heads included. */
    users_count: number;
    /** Departments only: working employees here and in sub-departments, each counted once. */
    total_count?: number;
    /** System records that can be renamed but not deleted. */
    protected?: boolean;
    /** Passes every check whatever the list says, so there is nothing to tick. */
    everything?: boolean;
    /** Departments only: the tree is built from these. */
    parent_id?: number | null;
    /** Departments only: who leads it; there can be several. */
    heads?: { id: number; name: string }[];
    /** Departments only: working members who do not lead it. */
    member_ids?: number[];
    /** Equipment categories only: which drawing stands for it. */
    icon?: string | null;
    /** Positions only: the rights the position carries, by key. */
    permissions?: string[];
    /** Equipment categories only: what units of this category are described by. */
    fields?: CategoryField[];
    /** Equipment categories only: whether its units come with anything at all. */
    has_accessories?: boolean;
    /** A few lines about the record, such as a position's duties. */
    description?: string | null;
}

/** A field of free text the record carries besides its name (a position's duties). */
interface DescriptionField {
    /** Server field that holds it. */
    field: string;
    label: string;
    placeholder?: string;
}

interface Labels {
    /** "Добавить позицию" */
    add: string;
    /** "Новая позиция" */
    create: string;
    /** "Изменить позицию" */
    edit: string;
    /** Accusative, for "Удалить позицию «…»?" */
    accusative: string;
}

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
    /** Show and edit the parent/child structure (departments). */
    tree?: boolean;
    /** When given, each record has heads and members chosen from these people (departments). */
    people?: PickablePerson[];
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
    /** When given, each record also carries a few lines of text (a position's duties). */
    description?: DescriptionField;
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

function descendantIds(items: DirectoryItem[], id: number): Set<number> {
    const result = new Set([id]);
    let level = [id];

    while (level.length) {
        level = items.filter((item) => item.parent_id != null && level.includes(item.parent_id) && !result.has(item.id)).map((item) => item.id);
        level.forEach((child) => result.add(child));
    }

    return result;
}

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
    tree = false,
    people,
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
    description,
}: DirectoryManagerProps) {
    const can = useCan();
    const opensList = can('employees.view') && (employeesField === undefined || can(employeesField));
    const [query, setQuery] = useState('');
    const [editing, setEditing] = useState<DirectoryItem | 'new' | null>(null);
    const [deleting, setDeleting] = useState<DirectoryItem | null>(null);

    const rows = useMemo(() => orderRows(items, tree), [items, tree]);
    const visible = query.trim() ? rows.filter((row) => row.label.toLowerCase().includes(query.trim().toLowerCase())) : rows;

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
             * into 320px leave the names wrapping letter by letter. Tapping a row
             * opens the people behind it, the same way the count does on a
             * desktop; changing and deleting sit in the row's menu.
             */}
            <Card className="gap-0 overflow-hidden rounded-2xl border-0 p-0 shadow-none md:hidden">
                {visible.length > 0 ? (
                    <ul className="divide-border/70 divide-y">
                        {visible.map((row) => {
                            const count = row.total_count ?? row.users_count;
                            const indent = tree && !query ? Math.min(row.depth, 4) * 14 : 0;

                            return (
                                <MobileRow
                                    key={row.id}
                                    href={employeesUrl && opensList && count > 0 ? employeesUrl(row) : null}
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
                                            <span className={cn('truncate', tree && row.depth === 0 && 'font-semibold')}>{row.label}</span>
                                            {row.protected && (
                                                <Lock
                                                    className="text-muted-foreground size-3.5 shrink-0"
                                                    aria-label="Системная запись: удалить нельзя"
                                                />
                                            )}
                                        </span>
                                    }
                                    subtitle={
                                        people
                                            ? row.heads?.length
                                                ? row.heads.map((head) => head.name).join(', ')
                                                : 'Руководитель не назначен'
                                            : `${count} ${plural(count, countWords)}`
                                    }
                                    meta={
                                        people ? (
                                            <span className="flex flex-col items-end gap-0.5">
                                                <span className="text-foreground text-[15px] tabular-nums">{count}</span>
                                                {row.total_count !== undefined && row.total_count !== row.users_count && (
                                                    <span className="tabular-nums">{row.users_count} напрямую</span>
                                                )}
                                            </span>
                                        ) : undefined
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
                                {people && (
                                    <th scope="col" className="px-3 py-3 font-semibold md:w-72 md:px-4">
                                        Руководители
                                    </th>
                                )}
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
                                            <span className={cn(tree && row.depth === 0 && 'font-semibold')}>{row.label}</span>
                                            {row.protected && (
                                                <Lock className="text-muted-foreground size-3.5" aria-label="Системная запись: удалить нельзя" />
                                            )}
                                        </span>
                                        {row.description && (
                                            <p className="text-muted-foreground mt-0.5 line-clamp-2 max-w-2xl text-xs whitespace-pre-line">
                                                {row.description}
                                            </p>
                                        )}
                                    </td>
                                    {people && (
                                        <td className="px-3 py-2.5 md:px-4">
                                            {row.heads?.length ? (
                                                <span className="flex flex-col gap-0.5">
                                                    {row.heads.map((head) => (
                                                        <PersonLink
                                                            key={head.id}
                                                            id={head.id}
                                                            className="hover:text-brand-strong hover:underline dark:hover:text-[#C5E27A]"
                                                        >
                                                            {head.name}
                                                        </PersonLink>
                                                    ))}
                                                </span>
                                            ) : (
                                                <span className="text-muted-foreground">Не назначен</span>
                                            )}
                                        </td>
                                    )}
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
                                                    aria-label={`Изменить: ${row.label}`}
                                                    onClick={() => setEditing(row)}
                                                >
                                                    <Pencil className="size-4" />
                                                </Button>
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    className="size-9 text-[#B42318] hover:text-[#B42318] lg:size-8 dark:text-[#F7A19A]"
                                                    aria-label={`Удалить: ${row.label}`}
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
                                    <td
                                        colSpan={(people ? 3 : 2) + (canEdit ? 1 : 0)}
                                        className="text-muted-foreground px-3 py-12 text-center md:px-6"
                                    >
                                        {items.length === 0 ? 'Пока пусто.' : 'Ничего не найдено.'}
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
            </Card>

            {editing && (
                <EditorDialog
                    key={editing === 'new' ? 'new' : editing.id}
                    item={editing === 'new' ? null : editing}
                    items={items}
                    field={field}
                    routeName={routeName}
                    labels={labels}
                    tree={tree}
                    people={people}
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
                    description={description}
                    onClose={() => setEditing(null)}
                />
            )}

            <DeleteDialog item={deleting} routeName={routeName} labels={labels} tree={tree} items={items} onClose={() => setDeleting(null)} />
        </>
    );
}

/** A heading over one group inside the "Доступы" block. */
const groupHeading = 'text-muted-foreground text-xs font-semibold tracking-wide uppercase';

/**
 * Whether the employee section opens at all is not ticked but worked out: a
 * position that reads no line of a card would find nothing but blank rows there.
 */
const viewRight = 'employees.view';

/**
 * The same lines are asked about twice over — a colleague's card and one's own —
 * so whose card it is goes first in the heading: the two lists look alike and
 * mean quite different things.
 */
const cardScopeTitles: Record<string, Record<CardFieldsMode, string>> = {
    employees: { view: 'Чужая карточка: просмотр', edit: 'Чужая карточка: изменение' },
    profile: { view: 'Своя карточка: просмотр', edit: 'Своя карточка: изменение' },
};

/** Said plainly where the list alone would read as the one above it. */
const cardScopeNotes: Record<string, string> = {
    profile: 'Речь о собственной карточке сотрудника этой позиции, а не о карточках коллег.',
};

/** What is left of a section once the lists above have taken their part of it. */
const sectionTitles: Record<string, string> = { employees: 'Действия с сотрудником' };

const cardModes: CardFieldsMode[] = ['view', 'edit'];

/** How much of a plain list of rights the position already holds. */
const countRights = (rights: PlainRight[], held: string[]) => ({
    chosen: rights.filter((right) => held.includes(right.key)).length,
    total: rights.length,
});

/** One list of rights under a heading that counts what is chosen in it. */
function RightsBlock({ title, note, chosen, total, children }: { title: string; note?: string; chosen: number; total: number; children: ReactNode }) {
    return (
        <div className="grid gap-1.5">
            <p className={groupHeading}>
                {title}
                <span className="font-normal normal-case tabular-nums">
                    {' · '}
                    {chosen} из {total}
                </span>
            </p>
            {note && <p className="text-muted-foreground text-[13px]">{note}</p>}
            {children}
        </div>
    );
}

/** One list of card lines under such a heading. */
function CardFieldsBlock({
    title,
    note,
    mode,
    groups,
    held,
    onChange,
}: {
    title: string;
    note?: string;
    mode: CardFieldsMode;
    groups: CardFieldGroup[];
    held: string[];
    onChange: (permissions: string[]) => void;
}) {
    return (
        <RightsBlock title={title} note={note} {...countCardFields(groups, held, mode)}>
            <CardFields mode={mode} groups={groups} held={held} onChange={onChange} />
        </RightsBlock>
    );
}

function EditorDialog({
    item,
    items,
    field,
    routeName,
    labels,
    tree,
    people,
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
    description,
    onClose,
}: {
    item: DirectoryItem | null;
    items: DirectoryItem[];
    field: 'title' | 'name';
    routeName: string;
    labels: Labels;
    tree: boolean;
    people?: PickablePerson[];
    icons?: string[];
    rights?: AccessSection[];
    fieldTypes?: FieldTypeOption[];
    defaultFields?: CategoryField[];
    cardFields?: CardFieldGroup[];
    profileFields?: CardFieldGroup[];
    equipmentScopes?: EquipmentScope[];
    equipmentBlocks?: AccessRight[];
    equipmentActions?: AccessRight[];
    directoryLists?: PlainRight[];
    directoryEdits?: PlainRight[];
    defaultRights?: string[];
    description?: DescriptionField;
    onClose: () => void;
}) {
    // A position that is new to the system may look around, like every other
    // one; what it opens beyond that is ticked here.
    const form = useForm<{
        label: string;
        parent_id: number | null;
        head_ids: number[];
        member_ids: number[];
        icon: string | null;
        permissions: string[];
        description: string;
    }>({
        label: item?.label ?? '',
        parent_id: item?.parent_id ?? null,
        head_ids: item?.heads?.map((head) => head.id) ?? [],
        member_ids: item?.member_ids ?? [],
        icon: item?.icon ?? null,
        permissions: item?.permissions ?? [...(defaultRights ?? [])],
        description: item?.description ?? '',
    });

    const [fields, setFields] = useState<CategoryField[]>(item?.fields ?? defaultFields ?? []);
    // Most hardware comes with something, so a new category says it does until
    // told otherwise.
    const [hasAccessories, setHasAccessories] = useState(item?.has_accessories ?? true);

    const togglePermission = (key: string) =>
        form.setData(
            'permissions',
            form.data.permissions.includes(key) ? form.data.permissions.filter((held) => held !== key) : [...form.data.permissions, key],
        );

    /**
     * The same, for rights others lean on: closing a list takes the right to
     * change it away as well. Left behind it would be a right that never answers
     * yes — the dialog greys it out, and the save would drop it anyway.
     */
    const toggleDirectoryRight = (key: string) => {
        if (!form.data.permissions.includes(key)) {
            togglePermission(key);

            return;
        }

        const dependent = (directoryEdits ?? []).filter((right) => right.requires?.key === key).map((right) => right.key);

        form.setData(
            'permissions',
            form.data.permissions.filter((held) => held !== key && !dependent.includes(held)),
        );
    };

    // A head is a member too, listed once: new heads leave the member list,
    // former heads stay in the department as ordinary members.
    const setHeads = (ids: number[]) => {
        const former = form.data.head_ids.filter((id) => !ids.includes(id));
        form.setData((data) => ({ ...data, head_ids: ids, member_ids: [...data.member_ids.filter((id) => !ids.includes(id)), ...former] }));
    };
    const members = useMemo(() => people?.filter((person) => !form.data.head_ids.includes(person.id)) ?? [], [people, form.data.head_ids]);

    // A department cannot move under itself or its own sub-departments.
    const blocked = useMemo(() => (item && tree ? descendantIds(items, item.id) : new Set<number>()), [item, items, tree]);
    const parents = useMemo(() => orderRows(items, true).filter((row) => !blocked.has(row.id)), [items, blocked]);

    /**
     * The right to the employee section, put in or left out by the card lines
     * chosen for viewing: it is the one right nobody ticks by hand.
     */
    const withViewRight = (permissions: string[]) => {
        if (!cardFields) return permissions;

        const rest = permissions.filter((right) => right !== viewRight);

        return readsAnyCardLine(cardFields, permissions) ? [...rest, viewRight] : rest;
    };

    /**
     * Which lines belong to which section, so the dialog follows the sections
     * themselves: a colleague's card under "Сотрудники", one's own under
     * "Профиль", each right where the rest of that section is.
     */
    const cardGroups: Record<string, CardFieldGroup[] | undefined> = { employees: cardFields, profile: profileFields };

    const submit: FormEventHandler = (event) => {
        event.preventDefault();

        form.transform((data) => ({
            [field]: data.label.trim(),
            ...(tree ? { parent_id: data.parent_id } : {}),
            ...(people ? { head_ids: data.head_ids, member_ids: data.member_ids } : {}),
            ...(icons ? { icon: data.icon } : {}),
            // Emptied, the text goes rather than staying behind as blank lines.
            ...(description ? { [description.field]: data.description.trim() || null } : {}),
            ...(rights && !item?.everything ? { permissions: withViewRight(data.permissions) } : {}),
            ...(fieldTypes
                ? {
                      has_accessories: hasAccessories,
                      // A line left blank is a line somebody started and thought
                      // better of, so it drops out rather than failing the save.
                      fields: fields
                          .filter((field) => field.name.trim() !== '')
                          .map((field) => ({
                              ...field,
                              name: field.name.trim(),
                              options: field.type === 'select' ? field.options.map((option) => option.trim()).filter(Boolean) : [],
                          })),
                  }
                : {}),
        }));
        const options = { preserveScroll: true, onSuccess: onClose };

        if (item) form.put(route(`${routeName}.update`, item.id), options);
        else form.post(route(`${routeName}.store`), options);
    };

    const errors = form.errors as Record<string, string | undefined>;
    /** The first error for a list and its items ("head_ids", "head_ids.0", ...). */
    const listError = (key: string) => errors[key] ?? Object.entries(errors).find(([name]) => name.startsWith(`${key}.`))?.[1];

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            {/* The lines of a card are a list of their own, so the dialog that holds
                them asks for more room than a name and a couple of checkboxes. */}
            <DialogContent
                className={cn(
                    // svh rather than vh: on a phone the browser's own bars would
                    // otherwise hide the bottom of a long list of rights.
                    'max-h-[90svh] overflow-y-auto sm:max-w-md',
                    (cardFields || profileFields) && 'sm:max-w-lg',
                    fieldTypes && 'sm:max-w-2xl',
                )}
            >
                <form onSubmit={submit} className="flex flex-col gap-5">
                    <DialogHeader>
                        <DialogTitle>{item ? labels.edit : labels.create}</DialogTitle>
                        <DialogDescription className="sr-only">Заполните поля и сохраните.</DialogDescription>
                    </DialogHeader>

                    <div className="grid content-start gap-2">
                        <Label htmlFor="directory-label">Название</Label>
                        <Input
                            id="directory-label"
                            className="max-md:h-11"
                            autoFocus
                            value={form.data.label}
                            onChange={(event) => form.setData('label', event.target.value)}
                        />
                        <InputError message={errors[field]} />
                    </div>

                    {description && (
                        <div className="grid content-start gap-2">
                            <Label htmlFor="directory-description">{description.label}</Label>
                            <Textarea
                                id="directory-description"
                                rows={6}
                                placeholder={description.placeholder}
                                value={form.data.description}
                                onChange={(event) => form.setData('description', event.target.value)}
                                aria-invalid={Boolean(errors[description.field]) || undefined}
                            />
                            <InputError message={errors[description.field]} />
                        </div>
                    )}

                    {rights && (
                        <div className="grid content-start gap-2">
                            <Label>Доступы</Label>
                            {item?.everything ? (
                                <p className="text-muted-foreground text-[13px]">
                                    У этой позиции есть все доступы: она проходит любую проверку, и список здесь ничего не решает.
                                </p>
                            ) : (
                                <>
                                    <div className="grid gap-4">
                                        {rights.map((section) => {
                                            const shown = section.rights.filter((right) => right.key !== viewRight);
                                            const groups = cardGroups[section.key];
                                            // The fleet is opened part by part, and only here: nowhere
                                            // else does a section keep its own list of scopes.
                                            const scopes = section.key === 'equipment' ? equipmentScopes : undefined;
                                            // The fleet keeps its rights in lists of its own too: the
                                            // blocks of a unit's card, and the moves one makes with it.
                                            const blocks = section.key === 'equipment' ? equipmentBlocks : undefined;
                                            const actions = section.key === 'equipment' ? equipmentActions : undefined;
                                            // The reference lists keep no rights of the section's own:
                                            // all ten of them live in these two lists, read and change
                                            // side by side.
                                            const lists = section.key === 'directories' ? directoryLists : undefined;
                                            const edits = section.key === 'directories' ? directoryEdits : undefined;

                                            if (!groups && !scopes && !blocks && !actions && !lists && !edits && shown.length === 0) return null;

                                            return (
                                                <div key={section.key} className="grid gap-4">
                                                    {/* A card is read line by line, so the lines come first
                                                        and what one does to the person follows them. */}
                                                    {groups &&
                                                        cardModes.map((mode) => (
                                                            <CardFieldsBlock
                                                                key={mode}
                                                                title={cardScopeTitles[section.key]?.[mode] ?? section.title}
                                                                note={cardScopeNotes[section.key]}
                                                                mode={mode}
                                                                groups={groups}
                                                                held={form.data.permissions}
                                                                onChange={(permissions) => form.setData('permissions', permissions)}
                                                            />
                                                        ))}

                                                    {/* Which units are visible decides what the actions
                                                        below are about, so it is settled first. */}
                                                    {scopes && (
                                                        <RightsBlock
                                                            title="Оборудование: что видно"
                                                            {...countEquipmentScopes(scopes, form.data.permissions)}
                                                        >
                                                            <EquipmentScopes
                                                                scopes={scopes}
                                                                held={form.data.permissions}
                                                                onChange={(permissions) => form.setData('permissions', permissions)}
                                                            />
                                                        </RightsBlock>
                                                    )}

                                                    {/* A unit's card is edited in blocks, the way a person's
                                                        is edited line by line, so the blocks come next. */}
                                                    {blocks && blocks.length > 0 && (
                                                        <RightsBlock
                                                            title="Оборудование: что можно менять"
                                                            note="Карточка единицы правится блоками, и каждый блок — отдельное право: кто ведёт характеристики, не обязан трогать инвентаризацию."
                                                            {...countRights(blocks, form.data.permissions)}
                                                        >
                                                            <PlainRights rights={blocks} held={form.data.permissions} onToggle={togglePermission} />
                                                        </RightsBlock>
                                                    )}

                                                    {actions && actions.length > 0 && (
                                                        <RightsBlock
                                                            title="Оборудование: действия"
                                                            note="Это операции над единицей, а не строки её карточки: где она стоит на балансе, у кого на руках и жива ли ещё."
                                                            {...countRights(actions, form.data.permissions)}
                                                        >
                                                            <PlainRights rights={actions} held={form.data.permissions} onToggle={togglePermission} />
                                                        </RightsBlock>
                                                    )}

                                                    {/* Which of the five lists open, and only then which of
                                                        the open ones may be written in. */}
                                                    {lists && lists.length > 0 && (
                                                        <RightsBlock
                                                            title="Справочники: что видно"
                                                            note="Раздел собран из пяти списков, и каждый открывается сам по себе: должности ведёт кадровик, категории техники — тот, кто отвечает за парк."
                                                            {...countRights(lists, form.data.permissions)}
                                                        >
                                                            <PlainRights
                                                                rights={lists}
                                                                held={form.data.permissions}
                                                                onToggle={toggleDirectoryRight}
                                                            />
                                                        </RightsBlock>
                                                    )}

                                                    {edits && edits.length > 0 && (
                                                        <RightsBlock
                                                            title="Справочники: что можно менять"
                                                            note="Менять можно только то, что видно: закрытый выше список здесь недоступен, а если закрыть его потом, право на изменение снимется вместе с ним."
                                                            {...countRights(edits, form.data.permissions)}
                                                        >
                                                            <PlainRights rights={edits} held={form.data.permissions} onToggle={togglePermission} />
                                                        </RightsBlock>
                                                    )}

                                                    {shown.length > 0 && (
                                                        <div className="grid gap-1.5">
                                                            <p className={groupHeading}>{sectionTitles[section.key] ?? section.title}</p>
                                                            <PlainRights rights={shown} held={form.data.permissions} onToggle={togglePermission} />
                                                        </div>
                                                    )}
                                                </div>
                                            );
                                        })}
                                    </div>
                                    <InputError message={listError('permissions')} />
                                </>
                            )}
                        </div>
                    )}

                    {icons && (
                        <div className="grid content-start gap-2">
                            <Label>Иконка</Label>
                            {/* A grid of them: a list of names would say nothing about how each looks. */}
                            <div className="flex flex-wrap gap-2">
                                {icons.map((key) => {
                                    const Icon = equipmentIcons[key] ?? fallbackIcon;
                                    const chosen = form.data.icon === key;

                                    return (
                                        <button
                                            key={key}
                                            type="button"
                                            aria-pressed={chosen}
                                            aria-label={`Иконка ${key}`}
                                            onClick={() => form.setData('icon', chosen ? null : key)}
                                            className={cn(
                                                'flex size-10 items-center justify-center rounded-lg border transition-colors',
                                                chosen
                                                    ? 'border-transparent bg-[#EEF5DC] text-[#4A6410] dark:bg-[#A8CF45]/15 dark:text-[#C5E27A]'
                                                    : 'text-muted-foreground hover:bg-accent',
                                            )}
                                        >
                                            <Icon className="size-5" />
                                        </button>
                                    );
                                })}
                            </div>
                            <InputError message={errors.icon} />
                            <p className="text-muted-foreground text-[13px]">Без выбора — обычная коробка.</p>
                        </div>
                    )}

                    {fieldTypes && (
                        <div className="grid content-start gap-2">
                            <Label>Комплектация</Label>
                            <label className="flex items-start gap-2 text-sm">
                                <Checkbox checked={hasAccessories} onCheckedChange={() => setHasAccessories(!hasAccessories)} className="mt-0.5" />
                                <span className="min-w-0">
                                    Единицы этой категории идут в комплекте
                                    <span className="text-muted-foreground block text-[13px]">
                                        У ноутбука есть блок питания и сумка, у мыши — ничего. Без галочки поля «Комплектация» не будет ни в форме, ни
                                        на карточке.
                                    </span>
                                </span>
                            </label>
                        </div>
                    )}

                    {fieldTypes && (
                        <CategoryFieldsEditor
                            fields={fields}
                            types={fieldTypes}
                            onChange={setFields}
                            error={(key) => errors[key] ?? listError(key)}
                        />
                    )}

                    {tree && (
                        <div className="grid content-start gap-2">
                            <Label htmlFor="directory-parent">Входит в</Label>
                            <Select
                                value={form.data.parent_id === null ? 'root' : String(form.data.parent_id)}
                                onValueChange={(value) => form.setData('parent_id', value === 'root' ? null : Number(value))}
                            >
                                <SelectTrigger id="directory-parent" className="max-md:h-11">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent className="max-h-80">
                                    <SelectItem value="root">— Верхний уровень —</SelectItem>
                                    {parents.map((row) => (
                                        <SelectItem key={row.id} value={String(row.id)}>
                                            <span style={{ paddingLeft: row.depth * 16 }}>{row.label}</span>
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={errors.parent_id} />
                        </div>
                    )}

                    {people && (
                        <div className="grid content-start gap-2">
                            <Label htmlFor="directory-head">Руководители</Label>
                            <PeoplePicker
                                id="directory-head"
                                people={people}
                                value={form.data.head_ids}
                                onChange={setHeads}
                                emptyLabel="Не назначены"
                            />
                            <InputError message={listError('head_ids')} />
                        </div>
                    )}

                    {people && (
                        <div className="grid content-start gap-2">
                            <Label htmlFor="directory-members">
                                Сотрудники
                                {form.data.member_ids.length > 0 && (
                                    <span className="text-muted-foreground font-normal"> · {form.data.member_ids.length}</span>
                                )}
                            </Label>
                            <PeoplePicker
                                id="directory-members"
                                people={members}
                                value={form.data.member_ids}
                                onChange={(ids) => form.setData('member_ids', ids)}
                                emptyLabel="Никого нет"
                            />
                            <InputError message={listError('member_ids')} />
                        </div>
                    )}

                    <DialogFooter className="gap-2">
                        <Button type="button" variant="outline" className="max-md:h-11" onClick={onClose}>
                            Отмена
                        </Button>
                        <Button type="submit" className="max-md:h-11" disabled={form.processing || form.data.label.trim() === ''}>
                            {form.processing && <LoaderCircle className="animate-spin" />}
                            Сохранить
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
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
                        Удалить {labels.accusative} «{item?.label}»?
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
                            {children > 0 && <p>Вложенные отделы ({children}) переместятся на уровень выше.</p>}
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
