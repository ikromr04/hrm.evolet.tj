import { type StatusTone } from '@/components/status-badge';
import { formatDate, shortMonths } from '@/lib/employee';

export type EquipmentStatus = 'issued' | 'stock' | 'written_off';

/** The colours the design gives each status; they are the app's own tones. */
export const statusTone: Record<EquipmentStatus, StatusTone> = {
    issued: 'success',
    stock: 'neutral',
    written_off: 'danger',
};

export const statusLabel: Record<EquipmentStatus, string> = {
    issued: 'Выдано',
    stock: 'На балансе',
    written_off: 'Списано',
};

/** "декабрь 2026": an inventory is due in a month, not on a day. */
export function formatMonth(date: string | null): string | null {
    if (!date) return null;

    const parsed = new Date(date);

    return `${parsed.toLocaleDateString('ru-RU', { month: 'long' })} ${parsed.getFullYear()}`;
}

/* ------------------------------------------------------------------ journal */

export type EventKind =
    | 'created'
    | 'stocked'
    | 'issued'
    | 'written_off'
    | 'updated'
    | 'condition'
    | 'accessories'
    | 'repair_added'
    | 'repair_ended'
    | 'repair_updated'
    | 'repair_removed';

export const eventLabel: Record<EventKind, string> = {
    created: 'Добавлено',
    stocked: 'Поставлено на баланс',
    issued: 'Выдано',
    written_off: 'Списано',
    updated: 'Изменены данные',
    condition: 'Инвентаризация',
    accessories: 'Комплектация',
    repair_added: 'Обслуживание',
    repair_ended: 'Обслуживание завершено',
    repair_updated: 'Обслуживание изменено',
    repair_removed: 'Обслуживание удалено',
};

export const eventTone: Record<EventKind, StatusTone> = {
    created: 'info',
    stocked: 'info',
    issued: 'success',
    written_off: 'danger',
    updated: 'neutral',
    condition: 'info',
    accessories: 'info',
    repair_added: 'warning',
    repair_ended: 'success',
    repair_updated: 'neutral',
    repair_removed: 'neutral',
};

/** The field names as the card spells them, for reading a change out loud. */
export const fieldLabel: Record<string, string> = {
    name: 'Наименование',
    equipment_type_id: 'Категория',
    maker: 'Производитель',
    model: 'Модель',
    serial_number: 'Серийный номер',
    inventory_number: 'Инвентарный номер',
    condition: 'Состояние',
    checked_at: 'Последняя проверка',
    next_inventory_at: 'След. инвентаризация',
    accessories: 'Комплектация',
    status: 'Статус',
    holder_user_id: 'Держатель',
    // The other kind of holder: a department a unit is signed out to, for the
    // categories whose things are nobody's in particular.
    holder_department_id: 'Отдел',
    // A record of service work has fields of its own.
    kind: 'Тип работ',
    started_at: 'Дата начала',
    ended_at: 'Дата окончания',
    note: 'Комментарий',
    issued_at: 'Выдано',
    written_off_at: 'Списано',
};

export type ChangeValue = string | number | boolean | string[] | null;

/** Names for the ids an entry kept: field => { id: name }. */
export type NameLookup = Record<string, Record<string, string>>;

/** What one journal entry recorded: field => [before, after]. */
export type EventChanges = Record<string, [ChangeValue, ChangeValue]>;

const dateFields = ['checked_at', 'next_inventory_at', 'issued_at', 'written_off_at', 'started_at', 'ended_at'];

/**
 * What a list-valued change came to: which items appeared and which went.
 * The accessories are the one field kept as a list, so a swap reads as one
 * item added and another taken away rather than as two unreadable lists.
 */
export function listDiff(before: ChangeValue, after: ChangeValue): { added: string[]; removed: string[] } | null {
    if (!Array.isArray(before) || !Array.isArray(after)) return null;

    return {
        added: after.filter((item) => !before.includes(item)),
        removed: before.filter((item) => !after.includes(item)),
    };
}

/** A stored value as a person would read it. */
export function readValue(field: string, value: ChangeValue, names?: NameLookup): string {
    if (Array.isArray(value)) return value.length > 0 ? value.join(', ') : '—';
    if (value === null || value === '') return '—';
    if (field === 'status') return statusLabel[String(value) as EquipmentStatus] ?? String(value);
    if (dateFields.includes(field)) return formatDate(String(value)) ?? String(value);
    // A category's own field is recorded under its name, so the journal has no
    // list to look it up in; a value that is plainly a date still reads as one.
    if (/^\d{4}-\d{2}-\d{2}$/.test(String(value))) return formatDate(String(value)) ?? String(value);
    // Holders and categories are kept by id; the name is looked up when the
    // entry is read, and an id nobody answers to falls back to the number.
    if (field.endsWith('_id')) return names?.[field]?.[String(value)] ?? `#${value}`;

    return String(value);
}

/** "04 сен. 2026": a journal entry is read by the day, not by the minute. */
export function formatMoment(iso: string | null): string {
    if (!iso) return '—';

    const at = new Date(iso);

    return `${String(at.getDate()).padStart(2, '0')} ${shortMonths[at.getMonth()]} ${at.getFullYear()}`;
}
