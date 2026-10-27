import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';

/**
 * The key a table keeps its arrangements under: every viewer's own in their
 * own browser, and underneath those the one the company reads the table in.
 *
 * Written down once because two sides need the same string — the page that
 * draws the table, and the menu that links to it, which carries the sorting
 * and the filters of that arrangement in the address rather than letting the
 * page work them out after it has already drawn the list.
 */
export const TABLE_KEYS = {
    employees: 'employees.table.view.v6',
    // «Рабочее оборудование» and the «Обзор» of «Инвентаризация» draw the same
    // fleet, and they are two tables all the same: what is arranged in one, and
    // what the company reads it in, is not the other's.
    equipment: 'equipment.table.view.v4',
    inventory: 'inventory.table.view.v1',
    furniture: 'furniture.table.view.v1',
    office: 'office.table.view.v1',
    equipmentJournal: 'equipment.journal.view.v1',
    inventoryJournal: 'inventory.journal.view.v1',
    positions: 'positions.table.view.v2',
    // The directory lists are keyed by the list they keep; the departments are
    // the one of them the menu links to.
    departments: 'directory:departments',
} as const;

/** What the company set for a table, as every page is told it. */
interface Arrangement {
    query?: string;
}

/**
 * A link to a list, with the sorting and the filters the company reads it with
 * already in it: «Сотрудники» in the menu leads to the staff list as the
 * company reads it, not to a list that rearranges itself a moment later.
 *
 * Where nothing was set the address is the plain one, which is the list as the
 * page itself draws it.
 */
export function useListHref(): (url: string, key: string) => string {
    const set = usePage<SharedData>().props.tableViews as Record<string, Arrangement> | undefined;

    return (url, key) => {
        const query = set?.[key]?.query ?? '';

        return query ? `${url}?${query}` : url;
    };
}
