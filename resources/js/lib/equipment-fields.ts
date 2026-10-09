/**
 * The fields a category gives its units.
 *
 * A monitor has a diagonal and no processor, a phone has an IMEI. Which fields
 * a category has is decided in the directory, so a form asks whatever the
 * chosen category says and nothing more. Mirrors App\Models\EquipmentField.
 */

import { formatDate } from '@/lib/employee';

export type FieldType = 'text' | 'number' | 'date' | 'boolean' | 'select';

/**
 * Which of the two every unit carries a field holds, if either. A category
 * names these two as it likes and puts them where it likes among the rest, but
 * one of each stays: the list, the journal, the letters and the search all name
 * a unit by them. Null for a field that is the category's own.
 */
export type FieldRole = 'title' | 'inventory' | null;

/** One field of a category, as the server describes it. */
export interface CategoryField {
    /** Missing while the field is being added in the dialog. */
    id?: number;
    name: string;
    type: FieldType;
    /** The choices a "select" offers; empty for every other type. */
    options: string[];
    required: boolean;
    role: FieldRole;
}

/** The usual word for each of the two, for a category that has not renamed them. */
export const roleLabel: Record<'title' | 'inventory', string> = {
    title: 'Наименование',
    inventory: 'Инвентарный номер',
};

/**
 * What this category calls the field holding the role — a printer's
 * «Наименование» may read «Модель» — or the usual word for it while no category
 * has been chosen and there is nothing to ask.
 */
export function roleName(fields: CategoryField[], role: 'title' | 'inventory'): string {
    return fields.find((field) => field.role === role)?.name.trim() || roleLabel[role];
}

/**
 * The field holding the role, for drawing the box it asks for. While no
 * category has been chosen there is none, so a plain line stands in: it is
 * what every category starts its two off as.
 */
export function roleField(fields: CategoryField[], role: 'title' | 'inventory'): CategoryField {
    return fields.find((field) => field.role === role) ?? { name: roleLabel[role], type: 'text', options: [], required: true, role };
}

/**
 * The fields a form and a card handle by field id. The two a unit is named by
 * are asked for by themselves and sent as "name" and "inventory_number", so
 * they are left out here rather than asked for twice.
 */
export function ownFields<Field extends CategoryField>(fields: Field[]): Field[] {
    return fields.filter((field) => !field.role);
}

/** What a field may hold, for the dialog's own list of types. */
export interface FieldTypeOption {
    key: FieldType;
    title: string;
}

/** What a unit has in its category's fields: field id => value. */
export type FieldValues = Record<number, string | boolean | null>;

/**
 * The value a form starts a field off with. A tick box is false rather than
 * empty: "not ticked" is an answer, and the form would otherwise send nothing
 * for a field the person deliberately left unticked.
 */
export function blankValue(field: CategoryField): string | boolean {
    return field.type === 'boolean' ? false : '';
}

/** Every field of a category, blank, keyed the way the form sends them. */
export function blankValues(fields: CategoryField[]): FieldValues {
    return Object.fromEntries(ownFields(fields).map((field) => [field.id!, blankValue(field)]));
}

/** A category as a form offers it: its name, and what its units are described by. */
export interface CategoryOption {
    id: number;
    name: string;
    /** Whether a unit of this category comes with anything at all. */
    has_accessories: boolean;
    fields: CategoryField[];
}

/**
 * A stored value as the card reads it: a yes or a no spelled out, a date the way
 * this project writes dates, and nothing at all for a field left empty.
 */
export function readFieldValue(field: CategoryField & { value: string | null }): string | null {
    if (field.value === null || field.value === '') return null;
    if (field.type === 'boolean') return field.value === '1' ? 'Да' : 'Нет';
    if (field.type === 'date') return formatDate(field.value) ?? field.value;

    return field.value;
}
