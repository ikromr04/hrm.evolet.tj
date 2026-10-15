import { Photos, type Photo } from '@/components/photo-viewer';
import { statusTones } from '@/components/status-badge';
import {
    eventLabel,
    eventTone,
    fieldLabel,
    formatMoment,
    listDiff,
    readValue,
    statusLabel,
    type ChangeValue,
    type EventChanges,
    type EventKind,
    type NameLookup,
} from '@/lib/equipment';
import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import {
    ArrowDownToLine,
    Boxes,
    CircleCheck,
    ClipboardCheck,
    Eraser,
    PackagePlus,
    Pencil,
    Trash2,
    UserPlus,
    Wrench,
    type LucideIcon,
} from 'lucide-react';

/**
 * What each kind of entry leaves unsaid under its badge. A move's status only
 * repeats the badge above it; the dates a move clears or stamps — the issue
 * date it ends, the check date a return fills in — follow from the move itself
 * and are not news of their own.
 */
const unsaid: Partial<Record<EventKind, string[]>> = {
    issued: ['status', 'issued_at'],
    stocked: ['status', 'issued_at', 'checked_at'],
    written_off: ['status', 'issued_at', 'written_off_at'],
};

/**
 * What one journal entry recorded, read out field by field: "Статус: На балансе
 * → Выдано". A list-valued field — the accessories — reads as what was added
 * and what was taken away instead, so swapping one item for another says so
 * rather than printing both lists in full.
 */
export function ChangeLines({
    changes,
    kind,
    names,
    note,
    className,
}: {
    changes: EventChanges;
    /** What the entry was, for the fields that go without saying in it. */
    kind?: EventKind;
    /** Names for the ids an entry kept, so a holder reads as a person. */
    names?: NameLookup;
    note?: string | null;
    className?: string;
}) {
    // Entries written before all this was settled still hold those fields, and
    // leaving them out here means they read the same as the ones written since.
    // Nobody holding a unit is not a blank: it is the unit gone back on the
    // balance sheet, or off the books altogether if this is what struck it off.
    const nobody = kind === 'written_off' ? statusLabel.written_off : statusLabel.stock;
    const read = (field: string, value: ChangeValue) =>
        (field === 'holder_user_id' || field === 'holder_department_id') && (value === null || value === '')
            ? nobody
            : readValue(field, value, names);

    const hidden = (kind && unsaid[kind]) ?? [];
    const fields = Object.entries(changes).filter(([field]) => !hidden.includes(field));

    if (fields.length === 0) {
        return note ? <span className={cn('text-[13px]', className)}>{note}</span> : <span className="text-muted-foreground">—</span>;
    }

    return (
        <ul className={cn('flex flex-col gap-0.5 whitespace-normal', className)}>
            {fields.map(([field, [before, after]]) => {
                const diff = listDiff(before, after);
                const label = fieldLabel[field] ?? field;

                if (diff) {
                    return (
                        <li key={field} className="text-[13px]">
                            <span className="text-muted-foreground">{label}: </span>
                            {diff.added.length > 0 && <span className="font-medium">добавлено {diff.added.join(', ')}</span>}
                            {diff.added.length > 0 && diff.removed.length > 0 && <span className="text-muted-foreground">; </span>}
                            {diff.removed.length > 0 && (
                                <span className="text-muted-foreground">
                                    убрано <span className="line-through">{diff.removed.join(', ')}</span>
                                </span>
                            )}
                            {diff.added.length === 0 && diff.removed.length === 0 && <span className="text-muted-foreground">порядок изменён</span>}
                        </li>
                    );
                }

                return (
                    <li key={field} className="text-[13px]">
                        <span className="text-muted-foreground">{label}: </span>
                        <span className="text-muted-foreground line-through">{read(field, before)}</span>
                        <span className="text-muted-foreground"> → </span>
                        <span className="font-medium">{read(field, after)}</span>
                    </li>
                );
            })}

            {note && <li className="text-[13px]">{note}</li>}
        </ul>
    );
}

/** Whether an entry has anything to read out beyond its own badge. */
export function hasChangeLines(changes: EventChanges, kind?: EventKind, note?: string | null): boolean {
    const hidden = (kind && unsaid[kind]) ?? [];

    return Boolean(note) || Object.keys(changes).some((field) => !hidden.includes(field));
}

/** A picture for each kind of entry, so a phone's list can be scanned by eye. */
const eventIcons: Record<EventKind, LucideIcon> = {
    created: PackagePlus,
    stocked: ArrowDownToLine,
    issued: UserPlus,
    written_off: Trash2,
    updated: Pencil,
    condition: ClipboardCheck,
    accessories: Boxes,
    repair_added: Wrench,
    repair_ended: CircleCheck,
    repair_updated: Wrench,
    repair_removed: Eraser,
};

/**
 * One journal entry as a row of a phone's list. A table of five columns does
 * not fit a phone, and a bare title-and-subtitle row would drop what the entry
 * actually says, so the change lines and the photographs follow under it.
 */
export function EventRow({
    kind,
    at,
    actor,
    changes,
    names,
    note,
    photos,
    unit,
}: {
    kind: EventKind;
    at: string | null;
    actor: { name: string } | null;
    changes: EventChanges;
    names?: NameLookup;
    note?: string | null;
    photos: Photo[];
    /** Left out on a unit's own card, which already says which unit it is. */
    unit?: { id: number; name: string; inventory_number: string } | null;
}) {
    const Icon = eventIcons[kind];

    return (
        <li className="flex gap-3 px-4 py-3">
            <span aria-hidden="true" className={cn('flex size-10 shrink-0 items-center justify-center rounded-xl', statusTones[eventTone[kind]])}>
                <Icon className="size-[18px]" />
            </span>

            <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                <div className="flex items-baseline justify-between gap-3">
                    <span className="truncate text-[15px] leading-tight font-medium">{eventLabel[kind]}</span>
                    <span className="text-muted-foreground shrink-0 text-xs tabular-nums">{formatMoment(at)}</span>
                </div>

                {unit !== undefined &&
                    (unit ? (
                        <Link
                            href={route('equipment.show', unit.id)}
                            prefetch
                            className="text-brand-strong truncate text-[13px] leading-tight font-medium dark:text-[#C5E27A]"
                        >
                            {unit.name} · инв. № {unit.inventory_number}
                        </Link>
                    ) : (
                        <span className="text-muted-foreground text-[13px] leading-tight">Единица удалена</span>
                    ))}

                <span className="text-muted-foreground truncate text-[13px] leading-tight">{actor?.name ?? 'Система'}</span>

                {hasChangeLines(changes, kind, note) && <ChangeLines changes={changes} kind={kind} names={names} note={note} className="mt-1.5" />}
                <Photos photos={photos} className="mt-2" />
            </div>
        </li>
    );
}
