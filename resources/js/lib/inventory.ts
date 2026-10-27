import { TABLE_KEYS } from '@/lib/tables';
import { type BreadcrumbItem } from '@/types';

/**
 * The parts «Инвентаризация» is read in, and everything a page of one needs to
 * say about itself: its name, the trail to it, the table it keeps and the
 * words its «add» button and its form are written in.
 *
 * The same four pages draw the fleet, each for its own part of the stock, so
 * they are described once here rather than three times over in two files.
 * «Обзор» is the odd one: it is about the whole of it, which is what `section`
 * says by being null.
 */
export const INVENTORY_PLACES = {
    inventory: {
        title: 'Обзор',
        route: 'inventory.overview',
        crumbs: [
            // The section has no page of its own; this is it.
            { title: 'Инвентаризация', href: '/inventory' },
            { title: 'Обзор', href: '/inventory' },
        ] as BreadcrumbItem[],
        viewKey: TABLE_KEYS.inventory,
        queryKey: 'inventory.table.query',
        section: null,
        add: { label: 'Добавить оборудование', title: 'Новое оборудование', route: 'inventory.create', href: '/inventory/create' },
    },
    equipment: {
        title: 'Рабочее оборудование',
        route: 'equipment.index',
        crumbs: [
            { title: 'Инвентаризация', href: '/inventory' },
            { title: 'Рабочее оборудование', href: '/inventory/equipment' },
        ] as BreadcrumbItem[],
        viewKey: TABLE_KEYS.equipment,
        queryKey: 'equipment.table.query',
        section: 'equipment',
        add: {
            label: 'Добавить рабочее оборудование',
            title: 'Новое рабочее оборудование',
            route: 'equipment.create',
            href: '/inventory/equipment/create',
        },
    },
    furniture: {
        title: 'Мебель',
        route: 'inventory.furniture',
        crumbs: [
            { title: 'Инвентаризация', href: '/inventory' },
            { title: 'Мебель', href: '/inventory/furniture' },
        ] as BreadcrumbItem[],
        viewKey: TABLE_KEYS.furniture,
        queryKey: 'furniture.table.query',
        section: 'furniture',
        add: { label: 'Добавить мебель', title: 'Новая мебель', route: 'inventory.furniture.create', href: '/inventory/furniture/create' },
    },
    office: {
        title: 'Оргтехника офиса',
        route: 'inventory.office',
        crumbs: [
            { title: 'Инвентаризация', href: '/inventory' },
            { title: 'Оргтехника офиса', href: '/inventory/office' },
        ] as BreadcrumbItem[],
        viewKey: TABLE_KEYS.office,
        queryKey: 'office.table.query',
        section: 'office',
        add: {
            label: 'Добавить оргтехнику офиса',
            title: 'Новая оргтехника офиса',
            route: 'inventory.office.create',
            href: '/inventory/office/create',
        },
    },
};

/** Which of the four a page is being drawn as. */
export type InventoryPlace = keyof typeof INVENTORY_PLACES;
