import { CategoryFieldInputs, FieldInput } from '@/components/category-field-inputs';
import { countActiveFilters, DataTable, MobileListTools, useTableView, type ColumnDef, type ViewState } from '@/components/data-table';
import { ChangeLines, EventRow } from '@/components/equipment-changes';
import { CategoryChip } from '@/components/equipment-icon';
import { EquipmentMoveDialog, moveLabel, type AskedMove } from '@/components/equipment-move-dialog';
import InputError from '@/components/input-error';
import { PersonFace } from '@/components/person-face';
import { PersonLink } from '@/components/person-link';
import { PhotoInput } from '@/components/photo-input';
import { Photos, type Photo } from '@/components/photo-viewer';
import { SearchableSelect } from '@/components/searchable-select';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type EquipmentRights } from '@/lib/access';
import { formatDate } from '@/lib/employee';
import {
    eventLabel,
    eventTone,
    formatMoment,
    formatMonth,
    statusLabel,
    statusTone,
    type EquipmentStatus,
    type EventChanges,
    type EventKind,
    type NameLookup,
} from '@/lib/equipment';
import {
    blankValues,
    ownFields,
    readFieldValue,
    roleField,
    roleName,
    type CategoryField,
    type CategoryOption,
    type FieldValues,
} from '@/lib/equipment-fields';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    ArrowDownToLine,
    Check,
    ChevronLeft,
    ChevronRight,
    Eraser,
    LoaderCircle,
    Pencil,
    Plus,
    Trash2,
    UserPlus,
    X,
    type LucideIcon,
} from 'lucide-react';
import { useEffect, useMemo, useState, type FormEventHandler, type ReactNode } from 'react';

interface Holder {
    id: number;
    name: string;
    avatar: string | null;
    department: string | null;
}

interface Unit {
    id: number;
    name: string;
    equipment_type_id: number;
    type: string | null;
    type_icon: string | null;
    inventory_number: string;
    /** What this category asks about, and what this unit answers. */
    fields: (CategoryField & { value: string | null })[];
    condition: string | null;
    checked_at: string | null;
    next_inventory_at: string | null;
    /** Null where the category says its units come with nothing. */
    accessories: string[] | null;
    status: EquipmentStatus;
    issued_at: string | null;
    written_off_at: string | null;
    holder: Holder | null;
}

interface Repair {
    id: number;
    kind: string;
    started_at: string;
    ended_at: string | null;
    note: string | null;
    /** What was photographed when the work was written down. */
    photos: Photo[];
}

interface JournalEvent {
    id: number;
    photos: Photo[];
    kind: EventKind;
    changes: EventChanges;
    note: string | null;
    at: string | null;
    actor: { id: number; name: string; avatar: string | null } | null;
}

interface Props {
    unit: Unit;
    repairs: Repair[];
    /** Everything that has happened to this unit, newest first. */
    events: JournalEvent[];
    /** Names for the ids the entries kept: field => { id: name }. */
    names: NameLookup;
    holders: { id: number; name: string }[];
    /** The categories the «Характеристики» form offers; empty for a viewer. */
    /** Every category with its own fields, for the specs form. */
    types: CategoryOption[];
    neighbours: { prev: Neighbour; next: Neighbour };
    /** Which blocks of this card and which moves are open; the server decided each one. */
    can: EquipmentRights;
    /** Whether the journal of this very unit may be read; without it `events` is empty. */
    canReadJournal: boolean;
}

/** The unit before or after this one in the list; null at either end. */
type Neighbour = { id: number; name: string; inventory_number: string } | null;

const tabs = [
    { key: 'overview', title: 'Обзор' },
    { key: 'service', title: 'Обслуживание' },
    { key: 'journal', title: 'Журнал' },
] as const;

type TabKey = (typeof tabs)[number]['key'];

/**
 * The open section rides in the URL hash, so a tab can be linked and survives a
 * reload. Only the tabs this viewer has are answered to: a link to «#journal»
 * handed to somebody who may not read it opens the card on «Обзор» instead of
 * on a section that is not there.
 */
function useTab(shown: TabKey[]): [TabKey, (key: TabKey) => void] {
    const fromHash = () => {
        const key = window.location.hash.replace('#', '') as TabKey;

        return shown.includes(key) ? key : 'overview';
    };
    const [tab, setTab] = useState<TabKey>(fromHash);

    // Back and forward move between tabs, like between pages.
    useEffect(() => {
        const onHashChange = () => setTab(fromHash());
        window.addEventListener('hashchange', onHashChange);

        return () => window.removeEventListener('hashchange', onHashChange);
    });

    return [
        tab,
        (key: TabKey) => {
            setTab(key);
            window.history.replaceState(null, '', key === 'overview' ? window.location.pathname : `#${key}`);
        },
    ];
}

/* --------------------------------------------------------------- building blocks */

/**
 * A block of the card. On a phone it reads as an inset grouped list, as in the
 * settings of a phone: the title stands above a borderless card instead of in a
 * grey strip inside it, and the block's action sits beside the title as text.
 * The outer box turns transparent there and the inner one becomes the card; on
 * a desktop the inner one is `contents`, so the block is laid out as before.
 */
function Section({ title, children, action }: { title: string; children: ReactNode; action?: ReactNode }) {
    return (
        <Card className="flex flex-col gap-4 rounded-xl px-4 py-4 max-md:gap-1.5 max-md:border-0 max-md:bg-transparent max-md:p-0! max-md:shadow-none sm:px-6 sm:py-5">
            <div className="bg-muted/60 -mx-4 -mt-4 flex min-h-11 items-center justify-between gap-3 rounded-t-xl border-b px-4 py-2 max-md:m-0! max-md:min-h-5 max-md:rounded-none max-md:border-0 max-md:bg-transparent max-md:px-1! max-md:py-0 sm:-mx-6 sm:-mt-5 sm:px-6">
                <h2 className="max-md:text-muted-foreground text-base font-semibold max-md:text-[13px] max-md:tracking-wide max-md:uppercase">
                    {title}
                </h2>
                {action}
            </div>
            <div className="max-md:bg-card max-md:flex max-md:flex-col max-md:overflow-hidden max-md:rounded-2xl max-md:px-4 md:contents">
                {children}
            </div>
        </Card>
    );
}

/**
 * The lines of a block, read across and then down, up to three across.
 *
 * A grid rather than CSS columns: columns fill themselves top to bottom, so the
 * order on the page would not be the order the card is written in. On a phone
 * it is one column of rows divided by hairlines, so it has no gaps of its own.
 */
function Fields({ children, columns }: { children: ReactNode; columns?: 1 }) {
    return (
        <dl
            className={cn(
                'grid gap-x-6 gap-y-4 max-md:gap-0',
                columns === 1 ? 'grid-cols-1' : 'grid-cols-1 max-md:grid-cols-1! sm:grid-cols-2 lg:grid-cols-3',
            )}
        >
            {children}
        </dl>
    );
}

/** A label over its value; on a phone one row, the label left and the value right. */
function Field({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="max-md:border-border/60 flex min-w-0 flex-col gap-1 max-md:flex-row max-md:items-baseline max-md:justify-between max-md:gap-4 max-md:border-b max-md:py-2.5 max-md:last:border-0">
            {/* A long label still leaves the value some room. */}
            <dt className="text-muted-foreground text-[13px] max-md:max-w-[60%] max-md:shrink-0 max-md:text-[15px]">{label}</dt>
            <dd className="text-sm font-medium break-words max-md:min-w-0 max-md:text-right max-md:text-[15px] max-md:font-normal">
                {children ?? <span className="text-muted-foreground font-normal">—</span>}
            </dd>
        </div>
    );
}

/**
 * A block's action on a phone: a word in the brand colour beside the block's
 * title. It is drawn small, but the area that takes the tap is a full 40px tall.
 */
function PhoneTextButton({ label, onClick, children }: { label?: string; onClick: () => void; children: ReactNode }) {
    return (
        <button
            type="button"
            onClick={onClick}
            aria-label={label}
            className="text-brand-strong -my-2.5 -mr-1 flex h-10 shrink-0 items-center px-1 text-[15px] font-medium normal-case active:opacity-60 md:hidden dark:text-[#C5E27A]"
        >
            {children}
        </button>
    );
}

const dash = <span className="text-muted-foreground">—</span>;

/** Errors come back as "photos.0"; a field shows its own, whichever it is. */
const at = (errors: Record<string, string | undefined>, key: string) =>
    errors[key] ?? Object.entries(errors).find(([name]) => name.startsWith(`${key}.`))?.[1];

/** The pencil in a block's header strip, as on the employee's profile. */
function EditButton({ what, onClick }: { what: string; onClick: () => void }) {
    return (
        <>
            <Button
                variant="ghost"
                size="icon"
                className="text-muted-foreground -mr-2 size-9 max-md:hidden lg:size-7"
                aria-label={`Редактировать ${what}`}
                onClick={onClick}
            >
                <Pencil className="size-4" />
            </Button>
            {/* A phone says it in a word, as its own settings screens do. */}
            <PhoneTextButton label={`Редактировать ${what}`} onClick={onClick}>
                Изменить
            </PhoneTextButton>
        </>
    );
}

/**
 * Walking the fleet without going back to the list. The open tab travels with
 * the link, so comparing two units keeps you on the same section, and the
 * arrow keys do the same as the buttons.
 */
function Neighbours({ prev, next, tab }: { prev: Neighbour; next: Neighbour; tab: TabKey }) {
    const href = (to: Neighbour) => route('equipment.show', to!.id) + (tab === 'overview' ? '' : `#${tab}`);

    useEffect(() => {
        const onKeyDown = (event: KeyboardEvent) => {
            if (event.ctrlKey || event.altKey || event.metaKey || event.shiftKey || event.defaultPrevented) return;
            const target = event.target as HTMLElement;
            if (target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT', 'BUTTON'].includes(target.tagName)) return;
            // An open window — a form, a menu, a list — owns the arrows: leaving
            // for the next unit from inside one would throw away what was typed,
            // and a visit on the way cancels the save that is still in the air.
            if (document.querySelector('[role="dialog"], [role="alertdialog"], [role="menu"], [role="listbox"]')) return;
            const to = event.key === 'ArrowLeft' ? prev : event.key === 'ArrowRight' ? next : null;
            if (to) router.visit(href(to));
        };
        window.addEventListener('keydown', onKeyDown);

        return () => window.removeEventListener('keydown', onKeyDown);
    });

    const arrow = (to: Neighbour, label: string, Icon: typeof ChevronLeft) => {
        // The arrow leads the label going back and trails it going forward.
        const back = Icon === ChevronLeft;

        return (
            <Button
                variant="outline"
                className="flex-1 sm:flex-none"
                disabled={!to}
                aria-label={to ? `${label}: ${to.name}` : label}
                title={to?.name}
                asChild={!!to}
            >
                {to ? (
                    <Link href={href(to)} prefetch>
                        {back && <Icon />}
                        {label}
                        {!back && <Icon />}
                    </Link>
                ) : (
                    <>
                        {back && <Icon />}
                        {label}
                        {!back && <Icon />}
                    </>
                )}
            </Button>
        );
    };

    /** On a phone: just the chevron, small and quiet, in the corner of the header. */
    const icon = (to: Neighbour, label: string, Icon: typeof ChevronLeft) =>
        to ? (
            <Button variant="ghost" size="icon" className="text-muted-foreground size-9" aria-label={`${label}: ${to.name}`} asChild>
                <Link href={href(to)} prefetch>
                    <Icon className="size-5" />
                </Link>
            </Button>
        ) : (
            <Button variant="ghost" size="icon" className="text-muted-foreground size-9" aria-label={label} disabled>
                <Icon className="size-5" />
            </Button>
        );

    return (
        <>
            <div className="-mt-1 -mr-1.5 flex shrink-0 md:hidden">
                {icon(prev, 'Предыдущее', ChevronLeft)}
                {icon(next, 'Следующее', ChevronRight)}
            </div>
            <div className="flex w-full gap-1 max-md:hidden sm:w-auto">
                {arrow(prev, 'Предыдущее', ChevronLeft)}
                {arrow(next, 'Следующее', ChevronRight)}
            </div>
        </>
    );
}

/**
 * Where a unit can go next, as one segmented control at the foot of the
 * sidebar — the same shape the employee's profile gives Перевести, Уволить
 * and Удалить.
 */
function MoveGroup({ moves, onPick }: { moves: { kind: AskedMove; icon: LucideIcon; danger?: boolean }[]; onPick: (kind: AskedMove) => void }) {
    return (
        <div className="bg-background flex w-full items-stretch overflow-hidden rounded-md border max-md:hidden">
            {moves.map(({ kind, icon: Icon, danger }, index) => (
                <button
                    key={kind}
                    type="button"
                    onClick={() => onPick(kind)}
                    title={moveLabel[kind]}
                    className={cn(
                        'hover:bg-accent focus-visible:ring-ring flex min-h-10 min-w-0 flex-1 items-center justify-center gap-1.5 px-2 py-2 text-sm font-medium transition-colors focus-visible:ring-2 focus-visible:outline-hidden lg:min-h-0',
                        index > 0 && 'border-l',
                        danger && 'text-[#B42318] dark:text-[#F7A19A]',
                    )}
                >
                    <Icon className="size-4 shrink-0" />
                    {/* A phone wraps a long label rather than cutting it short. */}
                    <span className="min-w-0 text-center lg:truncate">{moveLabel[kind]}</span>
                </button>
            ))}
        </div>
    );
}

/**
 * The same moves on a phone, as a grouped list of whole-width rows: a strip of
 * three buttons leaves each label a third of a narrow screen, and a row is what
 * a phone's thumb expects to tap.
 */
function PhoneActions({ actions }: { actions: { key: string; label: string; icon: LucideIcon; danger?: boolean; onClick: () => void }[] }) {
    if (actions.length === 0) return null;

    return (
        <div className="bg-card divide-border/60 flex flex-col divide-y overflow-hidden rounded-2xl md:hidden">
            {actions.map(({ key, label, icon: Icon, danger, onClick }) => (
                <button
                    key={key}
                    type="button"
                    onClick={onClick}
                    className={cn(
                        'active:bg-accent/60 flex min-h-12 items-center gap-3 px-4 text-left text-[15px] transition-colors',
                        danger ? 'text-[#B42318] dark:text-[#F7A19A]' : 'text-foreground',
                    )}
                >
                    <Icon className={cn('size-5 shrink-0', !danger && 'text-brand-strong dark:text-[#C5E27A]')} />
                    {label}
                </button>
            ))}
        </div>
    );
}

/** A plain table, for the service tab. */
function Table({ head, children }: { head: string[]; children: ReactNode }) {
    return (
        <div className="scroll-soft -mx-4 overflow-x-auto sm:-mx-6">
            <table className="w-full min-w-[640px] border-collapse text-sm">
                <thead>
                    <tr className="text-muted-foreground text-left text-[13px]">
                        {head.map((title) => (
                            <th key={title} scope="col" className="px-4 py-2 font-medium sm:px-6">
                                {title}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody>{children}</tbody>
            </table>
        </div>
    );
}

/* --------------------------------------------------------------------- dialogs */

/** The "Характеристики" block in a form: what the unit is and what it cost. */
function SpecsDialog({ unit, types, onClose }: { unit: Unit; types: CategoryOption[]; onClose: () => void }) {
    const form = useForm({
        equipment_type_id: String(unit.equipment_type_id),
        name: unit.name,
        inventory_number: unit.inventory_number,
    });

    // Kept beside the form, because they are keyed by field id rather than by a
    // name the form knows about. The two the unit is named by are asked for in
    // boxes of their own above, so they are left out of this.
    const written = (): FieldValues =>
        Object.fromEntries(ownFields(unit.fields).map((field) => [field.id!, field.type === 'boolean' ? field.value === '1' : (field.value ?? '')]));

    const [values, setValues] = useState<FieldValues>(written());

    // Everything the chosen category asks about, and the same without the two
    // asked above: the first names them, the second is drawn as inputs.
    const asked = types.find((type) => String(type.id) === form.data.equipment_type_id)?.fields ?? [];
    const fields = ownFields(asked);

    // Another category asks other things, so the answers start blank rather than
    // being written into fields nobody chose.
    const pickType = (id: string) => {
        form.setData('equipment_type_id', id);
        setValues(id === String(unit.equipment_type_id) ? written() : blankValues(types.find((type) => String(type.id) === id)?.fields ?? []));
    };

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        form.transform((data) => ({ ...data, fields: values }));
        form.put(route('equipment.specs', unit.id), { preserveScroll: true, onSuccess: onClose });
    };

    /** Every field here is a label over an input; only the value differs. */
    // The two a unit is named by, each drawn by the type its category gave it.
    const text = (key: 'name' | 'inventory_number', label: string, hint: string) => (
        <div className="grid content-start gap-2">
            <Label htmlFor={`specs-${key}`}>{label}</Label>
            <FieldInput
                field={roleField(asked, key === 'name' ? 'title' : 'inventory')}
                id={`specs-${key}`}
                value={form.data[key]}
                onChange={(value) => form.setData(key, String(value))}
                placeholder={hint}
                invalid={!!form.errors[key]}
            />
            <InputError message={form.errors[key]} />
        </div>
    );

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="scroll-soft max-h-[85vh] overflow-y-auto sm:max-w-lg">
                {/* noValidate: the server's rules are the real ones. */}
                <form onSubmit={submit} noValidate className="flex flex-col gap-5">
                    <DialogHeader>
                        <DialogTitle>Характеристики</DialogTitle>
                        <DialogDescription>Что это за единица и во сколько она обошлась.</DialogDescription>
                    </DialogHeader>

                    {/* Both are fields of the category, which names them as it
                        likes; what is sent stays "name" and "inventory_number". */}
                    {text('name', roleName(asked, 'title'), 'Ноутбук Dell Latitude 5440')}

                    <div className="grid content-start gap-2">
                        <Label htmlFor="specs-type">Категория</Label>
                        <SearchableSelect
                            id="specs-type"
                            value={form.data.equipment_type_id}
                            onChange={pickType}
                            options={types.map((type) => ({ value: String(type.id), label: type.name }))}
                            placeholder="Выберите категорию"
                            searchPlaceholder="Поиск категории"
                            empty="Категория не найдена"
                            invalid={!!form.errors.equipment_type_id}
                        />
                        <InputError message={form.errors.equipment_type_id} />
                    </div>

                    {text('inventory_number', roleName(asked, 'inventory'), 'EV-0421')}

                    {/* Whatever this category asks about; nothing at all for a
                        category with no fields of its own. */}
                    {fields.length > 0 && (
                        <div className="grid gap-4 sm:grid-cols-2">
                            <CategoryFieldInputs
                                fields={fields}
                                values={values}
                                onChange={(id, value) => setValues((held) => ({ ...held, [id]: value }))}
                                error={(key) => (form.errors as Record<string, string | undefined>)[key]}
                            />
                        </div>
                    )}

                    <DialogFooter className="gap-2">
                        <Button type="button" variant="outline" onClick={onClose}>
                            Отмена
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing && <LoaderCircle className="animate-spin" />}
                            Сохранить
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/** The "Состояние" block: what shape it is in, checked when, due when. */
function StateDialog({ unit, onClose }: { unit: Unit; onClose: () => void }) {
    const today = new Date().toISOString().slice(0, 10);
    // A check happens now and the next one is due a year from now, so the form
    // opens on those rather than on whatever the last check left behind.
    const inAYear = new Date();
    inAYear.setFullYear(inAYear.getFullYear() + 1);

    const [photos, setPhotos] = useState<File[]>([]);

    const form = useForm<{ condition: string; checked_at: string; next_inventory_at: string; photos: File[] }>({
        condition: unit.condition ?? '',
        checked_at: today,
        next_inventory_at: inAYear.toISOString().slice(0, 10),
        photos: [],
    });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        form.transform((data) => ({ ...data, photos, _method: 'put' }));
        // Multipart, so the upload is a POST that says it is a PUT.
        form.post(route('equipment.state', unit.id), { preserveScroll: true, forceFormData: true, onSuccess: onClose });
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="scroll-soft max-h-[85vh] overflow-y-auto sm:max-w-md">
                {/* noValidate: the server's rules are the real ones. */}
                <form onSubmit={submit} noValidate className="flex flex-col gap-5">
                    <DialogHeader>
                        <DialogTitle>Инвентаризация</DialogTitle>
                        <DialogDescription>Запись о проверке и её снимки останутся в журнале.</DialogDescription>
                    </DialogHeader>

                    <div className="grid content-start gap-2">
                        <Label htmlFor="state-condition">Текущее состояние</Label>
                        <Input
                            id="state-condition"
                            value={form.data.condition}
                            onChange={(event) => form.setData('condition', event.target.value)}
                            placeholder="Рабочее, без повреждений"
                            aria-invalid={!!form.errors.condition}
                        />
                        <InputError message={form.errors.condition} />
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid content-start gap-2">
                            <Label htmlFor="state-checked">Последняя проверка</Label>
                            <Input
                                id="state-checked"
                                type="date"
                                max={today}
                                value={form.data.checked_at}
                                onChange={(event) => form.setData('checked_at', event.target.value)}
                                aria-invalid={!!form.errors.checked_at}
                            />
                            <InputError message={form.errors.checked_at} />
                        </div>

                        <div className="grid content-start gap-2">
                            <Label htmlFor="state-next">След. инвентаризация</Label>
                            <Input
                                id="state-next"
                                type="date"
                                value={form.data.next_inventory_at}
                                onChange={(event) => form.setData('next_inventory_at', event.target.value)}
                                aria-invalid={!!form.errors.next_inventory_at}
                            />
                            <InputError message={form.errors.next_inventory_at} />
                            <p className="text-muted-foreground text-[13px]">На карточке покажем месяц.</p>
                        </div>
                    </div>

                    <PhotoInput
                        photos={photos}
                        onChange={setPhotos}
                        error={at(form.errors, 'photos')}
                        hint="Снимки прошлых проверок остаются в журнале — новые их не заменяют."
                    />

                    <DialogFooter className="gap-2">
                        <Button type="button" variant="outline" onClick={onClose}>
                            Отмена
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing && <LoaderCircle className="animate-spin" />}
                            Сохранить
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/** What comes with the unit, one line each; an empty line drops out on save. */
function AccessoriesDialog({ unit, onClose }: { unit: Unit; onClose: () => void }) {
    // The dialog only opens for a category that has a list at all.
    const held = unit.accessories ?? [];
    const [items, setItems] = useState<string[]>(held.length > 0 ? held : ['']);
    const form = useForm<{ accessories: string[] }>({ accessories: held });

    const change = (index: number, value: string) => setItems(items.map((item, at) => (at === index ? value : item)));

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        form.transform(() => ({ accessories: items.map((item) => item.trim()).filter(Boolean) }));
        form.put(route('equipment.accessories', unit.id), { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="scroll-soft max-h-[85vh] overflow-y-auto sm:max-w-md">
                {/* noValidate: the server's rules are the real ones. */}
                <form onSubmit={submit} noValidate className="flex flex-col gap-5">
                    <DialogHeader>
                        <DialogTitle>Комплектация</DialogTitle>
                        <DialogDescription>Что выдаётся вместе с техникой.</DialogDescription>
                    </DialogHeader>

                    <div className="flex flex-col gap-2">
                        {items.map((item, index) => (
                            <div key={index} className="flex items-center gap-2">
                                <Input
                                    value={item}
                                    onChange={(event) => change(index, event.target.value)}
                                    placeholder="Блок питания 65 Вт"
                                    aria-label={`Позиция ${index + 1}`}
                                />
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    className="text-muted-foreground size-9 shrink-0"
                                    aria-label={`Убрать позицию ${index + 1}`}
                                    onClick={() => setItems(items.length === 1 ? [''] : items.filter((_, at) => at !== index))}
                                >
                                    <Trash2 className="size-4" />
                                </Button>
                            </div>
                        ))}
                        <InputError message={form.errors.accessories} />

                        <Button type="button" variant="outline" size="sm" className="self-start" onClick={() => setItems([...items, ''])}>
                            <Plus />
                            Добавить позицию
                        </Button>
                    </div>

                    <DialogFooter className="gap-2">
                        <Button type="button" variant="outline" onClick={onClose}>
                            Отмена
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing && <LoaderCircle className="animate-spin" />}
                            Сохранить
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/**
 * A piece of work done on a unit: a cleaning, a part replaced, a trip to a
 * shop. It is a note in the history and moves nothing — an empty end date
 * only means the work is not finished yet.
 */
function RepairDialog({ unit, repair, finishing, onClose }: { unit: Unit; repair?: Repair; finishing?: boolean; onClose: () => void }) {
    const today = new Date().toISOString().slice(0, 10);

    const [photos, setPhotos] = useState<File[]>([]);

    const form = useForm({
        kind: repair?.kind ?? '',
        started_at: repair?.started_at ?? today,
        // Only "Завершить" fills the date in, because that is what it is for.
        // A correction opens on what the record says, empty included.
        ended_at: finishing ? today : (repair?.ended_at ?? ''),
        note: repair?.note ?? '',
    });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();

        // Multipart, so a correction is a POST that says it is a PUT.
        form.transform((data) => ({ ...data, photos, ...(repair ? { _method: 'put' } : {}) }));

        const url = repair ? route('equipment.repairs.update', [unit.id, repair.id]) : route('equipment.repairs.store', unit.id);

        form.post(url, { preserveScroll: true, forceFormData: true, onSuccess: onClose });
    };

    const title = finishing ? 'Завершить обслуживание' : repair ? 'Изменить запись' : 'Обслуживание';

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className={cn('scroll-soft max-h-[85vh] overflow-y-auto', finishing ? 'sm:max-w-md' : 'sm:max-w-lg')}>
                {/* noValidate: the server's rules are the real ones. */}
                <form onSubmit={submit} noValidate className="flex flex-col gap-5">
                    <DialogHeader>
                        <DialogTitle>{title}</DialogTitle>
                        <DialogDescription>
                            {finishing && repair
                                ? `${repair.kind} · с ${formatDate(repair.started_at)}`
                                : `${unit.name} · инв. № ${unit.inventory_number}`}
                        </DialogDescription>
                    </DialogHeader>

                    {/* Finishing asks one thing, so it shows one field. */}
                    {finishing ? (
                        <div className="grid content-start gap-2">
                            <Label htmlFor="repair-ended">Дата окончания</Label>
                            <Input
                                id="repair-ended"
                                type="date"
                                min={form.data.started_at || undefined}
                                max={today}
                                value={form.data.ended_at}
                                onChange={(event) => form.setData('ended_at', event.target.value)}
                                aria-invalid={!!form.errors.ended_at}
                            />
                            <InputError message={form.errors.ended_at} />
                            <p className="text-muted-foreground text-[13px]">Единица уйдёт с вкладки «На обслуживании».</p>
                        </div>
                    ) : (
                        <>
                            <div className="grid content-start gap-2">
                                <Label htmlFor="repair-kind">Тип работ</Label>
                                <Input
                                    id="repair-kind"
                                    value={form.data.kind}
                                    onChange={(event) => form.setData('kind', event.target.value)}
                                    placeholder="Чистка и замена термопасты"
                                    aria-invalid={!!form.errors.kind}
                                />
                                <InputError message={form.errors.kind} />
                            </div>

                            <div className="grid content-start gap-2">
                                <Label htmlFor="repair-note">Комментарий</Label>
                                <Input
                                    id="repair-note"
                                    value={form.data.note}
                                    onChange={(event) => form.setData('note', event.target.value)}
                                    placeholder="Плановое ТО"
                                    aria-invalid={!!form.errors.note}
                                />
                                <InputError message={form.errors.note} />
                            </div>
                            {/*
                             * Side by side and level with each other. A cell of a grid
                             * stretches to its row, and a grid inside it would spread
                             * its own rows over that height — which is what pushed the
                             * left field down beside the taller right one. `content-start`
                             * keeps each field packed at the top instead.
                             */}
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="grid content-start gap-2">
                                    <Label htmlFor="repair-started">Дата начала</Label>
                                    <Input
                                        id="repair-started"
                                        type="date"
                                        max={today}
                                        value={form.data.started_at}
                                        onChange={(event) => form.setData('started_at', event.target.value)}
                                        aria-invalid={!!form.errors.started_at}
                                    />
                                    <InputError message={form.errors.started_at} />
                                </div>

                                <div className="grid content-start gap-2">
                                    <Label htmlFor="repair-ended">Дата окончания</Label>
                                    <Input
                                        id="repair-ended"
                                        type="date"
                                        min={form.data.started_at || undefined}
                                        value={form.data.ended_at}
                                        onChange={(event) => form.setData('ended_at', event.target.value)}
                                        aria-invalid={!!form.errors.ended_at}
                                    />
                                    <InputError message={form.errors.ended_at} />
                                    <p className="text-muted-foreground text-[13px]">Пусто — работы ещё идут, и единица числится на обслуживании.</p>
                                </div>
                            </div>

                            <PhotoInput
                                photos={photos}
                                onChange={setPhotos}
                                error={at(form.errors, 'photos')}
                                hint="Останутся на записи и в журнале. Прошлые снимки не заменяются."
                            />
                        </>
                    )}

                    <DialogFooter className="gap-2">
                        <Button type="button" variant="outline" onClick={onClose}>
                            Отмена
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing && <LoaderCircle className="animate-spin" />}
                            Сохранить
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
/**
 * Striking a unit off the books for good — a duplicate, or something entered
 * by mistake. Everything filed under it goes too, so the dialog says as much
 * before it asks.
 */
function DeleteDialog({ unit, onClose }: { unit: Unit; onClose: () => void }) {
    const [busy, setBusy] = useState(false);

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Удалить запись?</DialogTitle>
                    <DialogDescription>
                        {unit.name} · инв. № {unit.inventory_number}
                    </DialogDescription>
                </DialogHeader>

                <p className="text-sm">
                    Вместе с единицей исчезнут её история передач, обслуживание и журнал. Отменить это нельзя. Если техника просто отслужила своё — её
                    нужно <span className="font-medium">списать</span>, а не удалять.
                </p>

                <DialogFooter className="gap-2">
                    <Button type="button" variant="outline" onClick={onClose}>
                        Отмена
                    </Button>
                    <Button
                        type="button"
                        variant="destructive"
                        disabled={busy}
                        onClick={() => {
                            setBusy(true);
                            router.delete(route('equipment.destroy', unit.id));
                        }}
                    >
                        {busy && <LoaderCircle className="animate-spin" />}
                        Удалить
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

/**
 * A line of the unit's service history is not struck out by a stray click on
 * the bin, so it is asked about first.
 */
function RepairDeleteDialog({ unit, repair, onClose }: { unit: Unit; repair: Repair; onClose: () => void }) {
    const [busy, setBusy] = useState(false);

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Удалить запись об обслуживании?</DialogTitle>
                    <DialogDescription>
                        {repair.kind} ·{' '}
                        {repair.ended_at ? `${formatDate(repair.started_at)} – ${formatDate(repair.ended_at)}` : `с ${formatDate(repair.started_at)}`}
                    </DialogDescription>
                </DialogHeader>

                <p className="text-sm">Запись исчезнет из вкладки «Обслуживание». В журнале останется отметка о том, что её удалили.</p>

                <DialogFooter className="gap-2">
                    <Button type="button" variant="outline" onClick={onClose}>
                        Отмена
                    </Button>
                    <Button
                        type="button"
                        variant="destructive"
                        disabled={busy}
                        onClick={() => {
                            setBusy(true);
                            router.delete(route('equipment.repairs.destroy', [unit.id, repair.id]), {
                                preserveScroll: true,
                                onFinish: onClose,
                            });
                        }}
                    >
                        {busy && <LoaderCircle className="animate-spin" />}
                        Удалить
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

/* ------------------------------------------------------------------ journal */

const JOURNAL_VIEW_KEY = 'equipment.card.journal.view.v1';

const journalView = (): ViewState => ({ hidden: [], pinned: { left: [], right: [] } });

/**
 * The same columns as the operations journal, without "Оборудование" and
 * "Категория": on a unit's own card both would say what the card already says.
 */
function journalColumns(events: JournalEvent[]): ColumnDef[] {
    const kinds = Array.from(new Set(events.map((event) => event.kind)));
    const actors = new Map(events.filter((event) => event.actor).map((event) => [event.actor!.id, event.actor!.name]));

    return [
        { key: 'at', label: 'Когда', width: 150, filter: { type: 'dates', from: 'from', to: 'to' } },
        {
            key: 'kind',
            label: 'Операция',
            width: 200,
            filter: { type: 'multi', param: 'kind', options: kinds.map((kind) => ({ value: kind, label: eventLabel[kind] })) },
        },
        {
            key: 'actor',
            label: 'Кто',
            width: 220,
            filter: { type: 'multi', param: 'actor', options: [...actors].map(([id, name]) => ({ value: id, label: name })) },
        },
        { key: 'changes', label: 'Что изменилось', width: 420 },
    ];
}

/** Everything the journal's filters are asked about, all of it optional. */
interface JournalFilters {
    from: string | null;
    to: string | null;
    kind: string[];
    actor: number[];
}

const noJournalFilters: JournalFilters = { from: null, to: null, kind: [], actor: [] };

/** Narrowing happens here rather than on the server: the card holds every entry already. */
function narrowJournal(events: JournalEvent[], filters: JournalFilters): JournalEvent[] {
    return events.filter((event) => {
        const day = event.at?.slice(0, 10) ?? '';

        if (filters.from && day < filters.from) return false;
        if (filters.to && day > filters.to) return false;
        if (filters.kind.length > 0 && !filters.kind.includes(event.kind)) return false;
        if (filters.actor.length > 0 && !filters.actor.includes(event.actor?.id ?? -1)) return false;

        return true;
    });
}

/* ------------------------------------------------------------------------ page */

export default function EquipmentShow({ unit, repairs, events, names, holders, types, neighbours, can, canReadJournal }: Props) {
    // The journal of this unit is a section of its own, and only for those who
    // may read it; the rest of the card stays as it was.
    const shownTabs = useMemo(() => tabs.filter((item) => item.key !== 'journal' || canReadJournal), [canReadJournal]);
    const [tab, setTab] = useTab(shownTabs.map((item) => item.key));
    const [asking, setAsking] = useState<AskedMove | null>(null);
    const [repairing, setRepairing] = useState(false);
    const [removing, setRemoving] = useState<Repair | null>(null);
    const [correcting, setCorrecting] = useState<Repair | null>(null);
    const [closing, setClosing] = useState<Repair | null>(null);
    const [deleting, setDeleting] = useState(false);
    // The journal tab is a table of its own, narrowed here rather than on the
    // server: every entry of this one unit is on the page already.
    const [journalFilters, setJournalFilters] = useState<JournalFilters>(noJournalFilters);
    const columns = useMemo(() => journalColumns(events), [events]);
    // Columns cannot be hidden here — there is no "Колонки" menu to bring one
    // back from — but pinning is still remembered between visits.
    const { view, pin } = useTableView(
        JOURNAL_VIEW_KEY,
        columns.map((column) => column.key),
        journalView(),
    );
    const shown = narrowJournal(events, journalFilters);

    const journalCell = (column: ColumnDef, event: JournalEvent) => {
        switch (column.key) {
            case 'at':
                return <span className="tabular-nums">{formatMoment(event.at)}</span>;
            case 'kind':
                return <StatusBadge tone={eventTone[event.kind]}>{eventLabel[event.kind]}</StatusBadge>;
            case 'actor':
                return event.actor ? (
                    <PersonLink
                        id={event.actor.id}
                        title={`Открыть профиль: ${event.actor.name}`}
                        className="text-brand-strong flex items-center gap-2 hover:underline dark:text-[#C5E27A]"
                    >
                        <PersonFace id={event.actor.id} name={event.actor.name} avatar={event.actor.avatar} className="size-7 text-[11px]" />
                        <span className="truncate">{event.actor.name}</span>
                    </PersonLink>
                ) : (
                    <span className="text-muted-foreground">Система</span>
                );
            default:
                return (
                    <div className="flex flex-col gap-2">
                        <ChangeLines changes={event.changes} kind={event.kind} names={names} note={event.note} />
                        <Photos photos={event.photos} />
                    </div>
                );
        }
    };
    const activeFilters = countActiveFilters(columns, journalFilters as unknown as Record<string, unknown>, () => true);

    /** Which block of the card is open in a form. */
    const [editing, setEditing] = useState<'specs' | 'accessories' | 'state' | null>(null);

    // The newest entry that came with photographs is the last look anyone had.
    const lastPhotos = events.find((event) => event.photos.length > 0)?.photos ?? [];

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Оборудование', href: '/equipment' },
        { title: unit.name, href: route('equipment.show', unit.id) },
    ];

    /**
     * What a unit can be moved to next, given where it is now and which of the
     * moves this viewer holds: handing a unit over, taking it back and writing it
     * off are three separate rights, so the group is built out of whichever of
     * them are open and is left out entirely when none is.
     */
    const moves: { kind: AskedMove; icon: LucideIcon; danger?: boolean }[] = [];

    if (unit.status !== 'written_off') {
        if (unit.status === 'issued') {
            if (can.take) moves.push({ kind: 'take', icon: ArrowDownToLine });
        } else if (can.issue) {
            moves.push({ kind: 'issue', icon: UserPlus });
        }

        if (can.write_off) moves.push({ kind: 'write-off', icon: Trash2, danger: true });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={unit.name} />

            <div className="flex flex-1 flex-col gap-5 p-3 max-md:gap-3 md:px-5 md:py-4">
                {/* Aligned along the bottom, so the title, the actions and the arrows sit on one line. */}
                {/* On a phone the name wraps to several lines, so the chip sits by its top instead,
                    and the arrows shrink to two chevrons in the top right corner. */}
                <div className="flex flex-wrap items-start gap-3 max-md:flex-nowrap md:items-end md:gap-5">
                    {/* Smaller on a phone, where the full-size chip would leave the name a narrow strip. */}
                    <span className="shrink-0 md:hidden">
                        <CategoryChip icon={unit.type_icon} size={56} iconSize={26} className="rounded-2xl" />
                    </span>
                    <span className="hidden shrink-0 md:block">
                        <CategoryChip icon={unit.type_icon} size={72} iconSize={32} />
                    </span>

                    <div className="flex min-w-0 flex-1 flex-col gap-2 max-md:gap-1">
                        <div className="flex flex-wrap items-center gap-3">
                            <h1 className="min-w-0 text-xl font-semibold tracking-tight break-words max-md:text-lg max-md:leading-snug">
                                {unit.name}
                            </h1>
                            <StatusBadge tone={statusTone[unit.status]} className="max-md:hidden">
                                {statusLabel[unit.status]}
                            </StatusBadge>
                        </div>

                        <p className="text-muted-foreground flex flex-wrap items-center gap-x-2 gap-y-1 text-sm">
                            {/* On a phone the status opens this line rather than trailing a wrapped name. */}
                            <StatusBadge tone={statusTone[unit.status]} className="md:hidden">
                                {statusLabel[unit.status]}
                            </StatusBadge>
                            {/* The two things every unit has, whatever it is. What
                                else it is made of depends on the category, and is
                                read in "Характеристики" below. */}
                            <span>Инв. № {unit.inventory_number}</span>
                            {unit.type && (
                                <>
                                    <span aria-hidden="true">·</span>
                                    {/* The category leads back to the list, narrowed to it. */}
                                    <Link
                                        href={route('equipment.index', { type: [unit.equipment_type_id] })}
                                        title={`Вся категория: ${unit.type}`}
                                        className="text-brand-strong hover:underline dark:text-[#C5E27A]"
                                    >
                                        {unit.type}
                                    </Link>
                                </>
                            )}
                        </p>
                    </div>

                    <Neighbours prev={neighbours.prev} next={neighbours.next} tab={tab} />
                </div>

                {/* On a phone the sections stay in reach while the card scrolls: the bar
                    sticks under the top bar, across the whole width, frosted like it. */}
                <nav
                    aria-label="Разделы"
                    className="scroll-soft max-md:bg-background/90 flex gap-6 overflow-x-auto max-md:sticky max-md:top-[calc(3rem+env(safe-area-inset-top))] max-md:z-20 max-md:-mx-3 max-md:gap-4 max-md:border-b max-md:px-4 max-md:pt-1 max-md:backdrop-blur-xl max-md:[scrollbar-width:none] max-md:[&::-webkit-scrollbar]:hidden"
                >
                    {shownTabs.map((item) => (
                        <button
                            key={item.key}
                            type="button"
                            onClick={() => setTab(item.key)}
                            aria-current={item.key === tab ? 'page' : undefined}
                            className={cn(
                                'shrink-0 border-b-2 px-1 pb-2.5 text-sm transition-colors max-md:pt-2 max-md:text-[15px]',
                                item.key === tab
                                    ? 'border-brand text-foreground font-semibold'
                                    : 'text-muted-foreground hover:text-foreground border-transparent font-medium',
                            )}
                        >
                            {item.title}
                        </button>
                    ))}
                </nav>

                {tab === 'overview' && (
                    // A phone spaces the blocks wider: their titles stand between them.
                    <div className="grid gap-4 max-md:gap-4 lg:grid-cols-[1fr_26.4rem]">
                        <div className="flex min-w-0 flex-col gap-4 max-md:gap-4">
                            <Section
                                title="Характеристики"
                                action={can.specs && <EditButton what="характеристики" onClick={() => setEditing('specs')} />}
                            >
                                <Fields>
                                    <Field label="Категория">
                                        {unit.type && (
                                            <Link
                                                href={route('equipment.index', { type: [unit.equipment_type_id] })}
                                                title={`Вся категория: ${unit.type}`}
                                                className="text-brand-strong hover:underline dark:text-[#C5E27A]"
                                            >
                                                {unit.type}
                                            </Link>
                                        )}
                                    </Field>
                                    {/* The number stands first whatever the category
                                        calls it; the rest follow in the category's
                                        own order, the name among them being the
                                        heading of the card. */}
                                    <Field label={roleName(unit.fields, 'inventory')}>{unit.inventory_number}</Field>
                                    {ownFields(unit.fields).map((field) => (
                                        <Field key={field.id} label={field.name}>
                                            {readFieldValue(field)}
                                        </Field>
                                    ))}
                                </Fields>
                            </Section>

                            {unit.accessories !== null && (
                                <Section
                                    title="Комплектация"
                                    action={can.accessories && <EditButton what="комплектацию" onClick={() => setEditing('accessories')} />}
                                >
                                    {unit.accessories.length === 0 ? (
                                        <p className="text-muted-foreground text-sm max-md:py-3 max-md:text-[15px]">Ничего не записано</p>
                                    ) : (
                                        <div className="flex flex-wrap gap-2 max-md:py-3">
                                            {unit.accessories.map((item) => (
                                                <StatusBadge
                                                    key={item}
                                                    tone="neutral"
                                                    className="h-auto min-h-[22px] py-0.5 break-words whitespace-normal"
                                                >
                                                    {item}
                                                </StatusBadge>
                                            ))}
                                        </div>
                                    )}
                                </Section>
                            )}
                        </div>

                        <div className="flex min-w-0 flex-col gap-4 max-md:gap-4">
                            <Section
                                // Not editable: where a unit is follows from the moves
                                // below, so it is changed by making one of them.
                                title={unit.holder ? 'Сейчас у сотрудника' : 'Где сейчас'}
                            >
                                {unit.holder ? (
                                    <>
                                        {/* On a phone the holder is one row of a list: the face, the name
                                            and when it was handed over, the whole row leading to them. */}
                                        <PersonLink
                                            id={unit.holder.id}
                                            title={`Открыть профиль: ${unit.holder.name}`}
                                            className="active:bg-accent/60 -mx-4 flex min-h-16 items-center gap-3 px-4 py-2.5 md:hidden"
                                        >
                                            <PersonFace
                                                id={unit.holder.id}
                                                name={unit.holder.name}
                                                avatar={unit.holder.avatar}
                                                className="size-10 shrink-0 text-[13px]"
                                            />
                                            <span className="flex min-w-0 flex-1 flex-col gap-0.5">
                                                <span className="truncate text-[15px] leading-tight font-medium">{unit.holder.name}</span>
                                                <span className="text-muted-foreground truncate text-[13px] leading-tight">
                                                    Выдано {formatDate(unit.issued_at) ?? '—'}
                                                    {unit.holder.department && ` · ${unit.holder.department}`}
                                                </span>
                                            </span>
                                        </PersonLink>

                                        <div className="flex items-center gap-3 max-md:hidden">
                                            <PersonFace
                                                id={unit.holder.id}
                                                name={unit.holder.name}
                                                avatar={unit.holder.avatar}
                                                className="size-10 text-[13px]"
                                            />
                                            <div className="flex min-w-0 flex-col">
                                                <PersonLink id={unit.holder.id} className="truncate text-sm font-semibold hover:underline">
                                                    {unit.holder.name}
                                                </PersonLink>
                                                {unit.holder.department && (
                                                    <span className="text-muted-foreground truncate text-[13px]">{unit.holder.department}</span>
                                                )}
                                            </div>
                                        </div>

                                        <div className="max-md:hidden">
                                            <Fields columns={1}>
                                                <Field label="Выдано">{formatDate(unit.issued_at)}</Field>
                                            </Fields>
                                        </div>
                                    </>
                                ) : (
                                    <Fields columns={1}>
                                        <Field label="Статус">{statusLabel[unit.status]}</Field>
                                        {unit.status === 'written_off' && <Field label="Списано">{formatDate(unit.written_off_at)}</Field>}
                                    </Fields>
                                )}
                            </Section>

                            <Section
                                title="Инвентаризация"
                                action={can.state && <EditButton what="инвентаризацию" onClick={() => setEditing('state')} />}
                            >
                                <Fields columns={1}>
                                    <Field label="Текущее состояние">{unit.condition}</Field>
                                    <Field label="Последняя проверка">{formatDate(unit.checked_at)}</Field>
                                    <Field label="След. инвентаризация">{formatMonth(unit.next_inventory_at)}</Field>
                                </Fields>

                                <Photos photos={lastPhotos} className="max-md:border-border/60 max-md:border-t max-md:py-3" />
                            </Section>

                            {moves.length > 0 && <MoveGroup moves={moves} onPick={setAsking} />}

                            <PhoneActions
                                actions={[
                                    ...moves.map(({ kind, icon, danger }) => ({
                                        key: kind,
                                        label: moveLabel[kind],
                                        icon,
                                        danger,
                                        onClick: () => setAsking(kind),
                                    })),
                                    ...(can.delete && unit.status === 'written_off'
                                        ? [{ key: 'delete', label: 'Удалить запись', icon: Eraser, danger: true, onClick: () => setDeleting(true) }]
                                        : []),
                                ]}
                            />

                            {/* Written off and nowhere left to go: only striking it off remains. */}
                            {can.delete && unit.status === 'written_off' && (
                                <Button
                                    variant="outline"
                                    className="border-[#F5C9C4] text-[#B42318] hover:text-[#B42318] max-md:hidden dark:text-[#F7A19A]"
                                    onClick={() => setDeleting(true)}
                                >
                                    <Eraser />
                                    Удалить запись
                                </Button>
                            )}
                        </div>
                    </div>
                )}

                {tab === 'service' && (
                    <Section
                        title="Обслуживание"
                        action={
                            can.service &&
                            unit.status !== 'written_off' && (
                                <>
                                    <Button variant="outline" size="sm" className="max-md:hidden" onClick={() => setRepairing(true)}>
                                        <Plus />
                                        Добавить запись
                                    </Button>
                                    <PhoneTextButton onClick={() => setRepairing(true)}>Добавить</PhoneTextButton>
                                </>
                            )
                        }
                    >
                        {repairs.length === 0 ? (
                            <p className="text-muted-foreground text-sm max-md:py-3 max-md:text-[15px]">Записей об обслуживании нет</p>
                        ) : (
                            <>
                                {/* A phone lists the records one under another instead of a table to scroll sideways. */}
                                <ul className="divide-border/60 -mx-4 divide-y md:hidden">
                                    {repairs.map((repair) => (
                                        <li key={repair.id} className="flex flex-col gap-0.5 px-4 py-3">
                                            <div className="flex items-start gap-2">
                                                <span className="min-w-0 flex-1 pt-0.5 text-[15px] leading-tight font-medium break-words">
                                                    {repair.kind}
                                                </span>
                                                {can.service && (
                                                    <div className="-my-2 -mr-2 flex shrink-0">
                                                        <Button
                                                            variant="ghost"
                                                            size="icon"
                                                            className="text-muted-foreground size-10"
                                                            aria-label={`Изменить запись: ${repair.kind}`}
                                                            onClick={() => setCorrecting(repair)}
                                                        >
                                                            <Pencil className="size-4" />
                                                        </Button>
                                                        <Button
                                                            variant="ghost"
                                                            size="icon"
                                                            className="text-muted-foreground size-10"
                                                            aria-label={`Удалить запись: ${repair.kind}`}
                                                            onClick={() => setRemoving(repair)}
                                                        >
                                                            <Trash2 />
                                                        </Button>
                                                    </div>
                                                )}
                                            </div>
                                            <span className="text-muted-foreground text-[13px] tabular-nums">
                                                {repair.ended_at
                                                    ? `${formatDate(repair.started_at)} – ${formatDate(repair.ended_at)}`
                                                    : `с ${formatDate(repair.started_at)}`}
                                            </span>
                                            {repair.note && <span className="text-[13px] break-words">{repair.note}</span>}
                                            <Photos photos={repair.photos} className="mt-1.5" />
                                            {/* Until the work has an end date the unit counts as being looked after. */}
                                            {can.service && repair.ended_at === null && (
                                                <Button variant="outline" className="mt-2 h-10 self-start" onClick={() => setClosing(repair)}>
                                                    <Check />
                                                    Завершить
                                                </Button>
                                            )}
                                        </li>
                                    ))}
                                </ul>

                                <div className="max-md:hidden">
                                    <Table head={['Тип', 'Период', 'Комментарий', 'Фото', '']}>
                                        {repairs.map((repair) => (
                                            <tr key={repair.id} className="border-t">
                                                <td className="px-4 py-2.5 font-medium sm:px-6">{repair.kind}</td>
                                                <td className="px-4 py-2.5 tabular-nums sm:px-6">
                                                    {repair.ended_at
                                                        ? `${formatDate(repair.started_at)} – ${formatDate(repair.ended_at)}`
                                                        : `с ${formatDate(repair.started_at)}`}
                                                </td>
                                                <td className="px-4 py-2.5 sm:px-6">{repair.note ?? dash}</td>
                                                <td className="px-4 py-2.5 sm:px-6">
                                                    {repair.photos.length > 0 ? <Photos photos={repair.photos} /> : dash}
                                                </td>
                                                <td className="py-2.5 pr-4 text-right sm:pr-6">
                                                    {can.service && (
                                                        <div className="flex items-center justify-end gap-1">
                                                            {/* Until the work has an end date the unit counts as being looked after. */}
                                                            {repair.ended_at === null && (
                                                                <Button
                                                                    variant="outline"
                                                                    size="sm"
                                                                    className="h-9 lg:h-8"
                                                                    onClick={() => setClosing(repair)}
                                                                >
                                                                    <Check />
                                                                    Завершить
                                                                </Button>
                                                            )}

                                                            <Button
                                                                variant="ghost"
                                                                size="icon"
                                                                className="text-muted-foreground size-9 lg:size-8"
                                                                aria-label={`Изменить запись: ${repair.kind}`}
                                                                onClick={() => setCorrecting(repair)}
                                                            >
                                                                <Pencil className="size-4" />
                                                            </Button>

                                                            <Button
                                                                variant="ghost"
                                                                size="icon"
                                                                className="text-muted-foreground size-9 lg:size-8"
                                                                aria-label={`Удалить запись: ${repair.kind}`}
                                                                onClick={() => setRemoving(repair)}
                                                            >
                                                                <Trash2 />
                                                            </Button>
                                                        </div>
                                                    )}
                                                </td>
                                            </tr>
                                        ))}
                                    </Table>
                                </div>
                            </>
                        )}
                    </Section>
                )}

                {tab === 'journal' && (
                    <div className="flex flex-col gap-3 md:min-h-0 md:flex-1">
                        {activeFilters > 0 && (
                            <div className="flex items-center max-md:hidden">
                                <Button variant="ghost" className="h-10 lg:h-8" onClick={() => setJournalFilters(noJournalFilters)}>
                                    <X />
                                    Сбросить фильтры ({activeFilters})
                                </Button>
                            </div>
                        )}

                        {/* A phone has no column headers to filter from, so the filters
                            gather behind one button, clearing included. */}
                        <div className="flex justify-end md:hidden">
                            <MobileListTools
                                columns={columns}
                                filters={journalFilters as unknown as Record<string, unknown>}
                                onFilter={(changes) => setJournalFilters((current) => ({ ...current, ...(changes as Partial<JournalFilters>) }))}
                                sort={{ key: 'at', direction: 'desc' }}
                                sortable={[]}
                                onSort={() => undefined}
                                className="max-md:bg-card max-md:rounded-xl max-md:border-transparent max-md:shadow-none"
                            />
                        </div>

                        <DataTable
                            mobileRow={(event) => (
                                <EventRow
                                    kind={event.kind}
                                    at={event.at}
                                    actor={event.actor}
                                    changes={event.changes}
                                    names={names}
                                    note={event.note}
                                    photos={event.photos}
                                />
                            )}
                            columns={columns}
                            rows={shown}
                            rowKey={(event) => event.id}
                            renderCell={journalCell}
                            sort={{ key: 'at', direction: 'desc' }}
                            sortable={[]}
                            onSort={() => undefined}
                            filters={journalFilters as unknown as Record<string, unknown>}
                            onFilter={(changes) => setJournalFilters((current) => ({ ...current, ...(changes as Partial<JournalFilters>) }))}
                            view={view}
                            onPin={pin}
                            lockedKey="at"
                            empty={
                                <span className="text-sm">
                                    {events.length === 0 ? 'Пока ничего не происходило' : 'Под эти фильтры ничего не попало'}
                                </span>
                            }
                        />
                    </div>
                )}
            </div>

            {asking && <EquipmentMoveDialog unit={unit} kind={asking} holders={holders} onClose={() => setAsking(null)} />}
            {repairing && <RepairDialog unit={unit} onClose={() => setRepairing(false)} />}
            {correcting && <RepairDialog unit={unit} repair={correcting} onClose={() => setCorrecting(null)} />}
            {closing && <RepairDialog unit={unit} repair={closing} finishing onClose={() => setClosing(null)} />}
            {removing && <RepairDeleteDialog unit={unit} repair={removing} onClose={() => setRemoving(null)} />}
            {deleting && <DeleteDialog unit={unit} onClose={() => setDeleting(false)} />}
            {editing === 'specs' && <SpecsDialog unit={unit} types={types} onClose={() => setEditing(null)} />}
            {editing === 'accessories' && <AccessoriesDialog unit={unit} onClose={() => setEditing(null)} />}
            {editing === 'state' && <StateDialog unit={unit} onClose={() => setEditing(null)} />}
        </AppLayout>
    );
}
