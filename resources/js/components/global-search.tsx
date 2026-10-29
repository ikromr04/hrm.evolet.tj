import { navGroups } from '@/components/app-sidebar';
import { CategoryChip } from '@/components/equipment-icon';
import { PersonFace } from '@/components/person-face';
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog';
import { directoryLists, seesEquipment, useCan } from '@/lib/access';
import { type EquipmentStatus, statusLabel } from '@/lib/equipment';
import { INVENTORY_PLACES } from '@/lib/inventory';
import { useListHref } from '@/lib/tables';
import { cn } from '@/lib/utils';
import { type SharedData } from '@/types';
import { router, usePage } from '@inertiajs/react';
import {
    BookMarked,
    Briefcase,
    Building2,
    CornerDownLeft,
    IdCard,
    Languages,
    Laptop,
    LayoutGrid,
    LoaderCircle,
    type LucideIcon,
    Network,
    PackagePlus,
    Search,
    Settings,
    UserPlus,
    Users,
    Wrench,
} from 'lucide-react';
import { type KeyboardEvent, type ReactNode, useEffect, useMemo, useRef, useState } from 'react';

interface SearchResults {
    // The address comes through empty for a viewer whose right to that line of
    // a card was taken away, exactly as the face does.
    employees: { id: number; name: string; avatar: string | null; email: string | null; positions: string[] }[];
    /** A unit of hardware, found by anything printed on it or by who has it. */
    equipment: {
        id: number;
        name: string;
        inventory_number: string;
        type: string | null;
        icon: string | null;
        status: EquipmentStatus;
        holder: string | null;
    }[];
    departments: { id: number; name: string; full_name: string }[];
    positions: { id: number; name: string }[];
    roles: { name: string; title: string }[];
    equipmentTypes: { id: number; name: string; icon: string | null }[];
    languages: { id: number; name: string }[];
}

interface Item {
    key: string;
    group: string;
    label: string;
    hint?: string;
    href: string;
    icon: ReactNode;
}

const EMPTY: SearchResults = { employees: [], equipment: [], departments: [], positions: [], roles: [], equipmentTypes: [], languages: [] };

/** Somewhere this person may go: a page of the menu, a form, a list already narrowed. */
interface Destination {
    label: string;
    /** Where it belongs — the section, or what the form is part of. */
    hint: string;
    href: string;
    icon: LucideIcon;
    /** Other words it answers to, which its own name does not contain. */
    words?: string;
}

/**
 * The words every «add something» answers to besides its own name, so that one
 * of them — whichever the hand reaches for — brings up all of them at once and
 * the reader picks what they came to add.
 */
const ADDING = 'добавить добавление создать создание новый новая новое завести';

/**
 * The destinations a query reaches, as rows of the list.
 *
 * Three things are searched: the name, the place it belongs to and whatever
 * else it answers to, because that is how these are remembered — «добавить
 * мебель» is looked for as «мебель» as often as by its own name, and «добавить»
 * on its own is looked for when the reader knows they are adding something and
 * not yet what.
 */
const reached = (group: string, destinations: Destination[], term: string): Item[] =>
    destinations
        .filter((one) => !term || one.label.toLowerCase().includes(term) || one.hint.toLowerCase().includes(term) || (one.words ?? '').includes(term))
        .map((one) => ({
            key: `${group}-${one.href}`,
            group,
            label: one.label,
            hint: one.hint,
            href: one.href,
            icon: iconBox(one.icon),
        }));

const iconBox = (Icon: LucideIcon) => (
    <span className="bg-muted text-muted-foreground flex size-8 shrink-0 items-center justify-center rounded-md">
        <Icon className="size-4" />
    </span>
);

/** Results for a query, fetched as the user types; stale answers are dropped. */
function useSearch(query: string): { results: SearchResults; loading: boolean } {
    const [results, setResults] = useState<SearchResults>(EMPTY);
    const [loading, setLoading] = useState(false);

    useEffect(() => {
        const term = query.trim();
        if (!term) {
            setResults(EMPTY);
            setLoading(false);
            return;
        }

        const controller = new AbortController();
        setLoading(true);
        const timer = setTimeout(() => {
            fetch(route('search', { q: term }), { headers: { Accept: 'application/json' }, signal: controller.signal })
                .then((response) => (response.ok ? response.json() : EMPTY))
                .then((data: SearchResults) => {
                    setResults(data);
                    setLoading(false);
                })
                .catch(() => {
                    if (!controller.signal.aborted) setLoading(false);
                });
        }, 200);

        return () => {
            clearTimeout(timer);
            controller.abort();
        };
    }, [query]);

    return { results, loading };
}

/** The header search: employees, departments, positions, roles and pages; opens with Ctrl + K. */
export function GlobalSearch() {
    const can = useCan();
    const { sysadmin } = usePage<SharedData>().props.auth;
    const listHref = useListHref();
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const [active, setActive] = useState(0);
    const { results, loading } = useSearch(open ? query : '');
    const list = useRef<HTMLUListElement>(null);
    /** Whether the mark was last moved by the arrow keys, which is what makes the list follow it. */
    const byKeys = useRef(false);

    useEffect(() => {
        const onKeyDown = (event: globalThis.KeyboardEvent) => {
            if ((event.ctrlKey || event.metaKey) && event.code === 'KeyK') {
                event.preventDefault();
                setOpen((current) => !current);
            }
        };
        window.addEventListener('keydown', onKeyDown);
        return () => window.removeEventListener('keydown', onKeyDown);
    }, []);

    /**
     * Everywhere the menu leads, read off the menu itself rather than written
     * out again here: a second list of pages is a list that goes stale, and the
     * one nobody looks at is always the stale one. A section inside a group
     * says which group it belongs to, so «Мебель» is not read as a page of its
     * own with no place in the company.
     */
    const pages = useMemo<Destination[]>(() => {
        const found: Destination[] = [];

        navGroups(can, sysadmin, listHref).forEach((group) =>
            group.items.forEach((item) => {
                if (item.url) found.push({ label: item.title, hint: group.title ?? 'Навигация', href: item.url, icon: item.icon ?? LayoutGrid });

                // A page inside a section draws no icon of its own in the menu;
                // here it borrows the section's, so a row is never iconless.
                (item.items ?? []).forEach((one) => {
                    if (one.url) found.push({ label: one.title, hint: item.title, href: one.url, icon: item.icon ?? LayoutGrid });
                });
            }),
        );

        return [
            ...found,
            // The lists of «Справочники» share one page and so stand in the menu
            // as one entry; here each is looked for by its own name — nobody
            // searches for «справочники» when what they want is «филиалы».
            ...directoryLists
                .filter((list) => can(list.view))
                .map((list) => ({ label: list.title, hint: 'Справочники', href: `/directories/${list.key}`, icon: BookMarked })),
            { label: 'Настройки', hint: 'Моя учётная запись', href: '/settings', icon: Settings },
        ];
    }, [can, sysadmin, listHref]);

    /**
     * The forms that start something new. They are pages like any other, but
     * they are looked for by what they make — «новый сотрудник» rather than
     * «сотрудники» — so they are named that way and kept apart.
     */
    const forms = useMemo<Destination[]>(
        () => [
            ...(can('employees.create')
                ? [{ label: 'Добавить сотрудника', hint: 'Приём на работу', href: '/employees/create', icon: UserPlus, words: ADDING }]
                : []),
            ...(can('equipment.create')
                ? Object.values(INVENTORY_PLACES).map((place) => ({
                      label: place.add.label,
                      hint: place.title,
                      href: place.add.href,
                      icon: PackagePlus,
                      words: ADDING,
                  }))
                : []),
            // The lists that are added to through a window on their own page:
            // the address asks for that window, so one press does what two did.
            ...(can('directories.edit.departments')
                ? [{ label: 'Добавить отдел', hint: 'Подразделения', href: '/departments?add=1', icon: Building2, words: ADDING }]
                : []),
            ...(can('directories.edit.positions')
                ? [{ label: 'Добавить должность', hint: 'Должности', href: '/positions?add=1', icon: Briefcase, words: ADDING }]
                : []),
            ...directoryLists
                .filter((list) => list.add !== null && can(list.edit))
                .map((list) => ({
                    label: list.add as string,
                    hint: `Справочники · ${list.title}`,
                    href: `/directories/${list.key}?add=1`,
                    icon: BookMarked,
                    words: ADDING,
                })),
        ],
        [can],
    );

    /**
     * The work itself, rather than the page it is done on: a list already
     * narrowed to what somebody came looking for. Each is a page plus the
     * question it is read with, which is exactly what the lists keep in their
     * addresses.
     */
    const operations = useMemo<Destination[]>(
        () => [
            ...(seesEquipment(can)
                ? [
                      { label: 'Выданное оборудование', hint: 'Инвентаризация', href: route('inventory.overview', { tab: 'issued' }), icon: Laptop },
                      { label: 'Оборудование на балансе', hint: 'Инвентаризация', href: route('inventory.overview', { tab: 'stock' }), icon: Laptop },
                      {
                          label: 'Оборудование на обслуживании',
                          hint: 'Инвентаризация',
                          href: route('inventory.overview', { tab: 'service' }),
                          icon: Wrench,
                      },
                      {
                          label: 'Списанное оборудование',
                          hint: 'Инвентаризация',
                          href: route('inventory.overview', { tab: 'written_off' }),
                          icon: Laptop,
                      },
                  ]
                : []),
            ...(can('employees.view')
                ? [
                      { label: 'Уволенные сотрудники', hint: 'Сотрудники', href: route('employees.index', { status: 'fired' }), icon: Users },
                      {
                          label: 'Переведённые сотрудники',
                          hint: 'Сотрудники',
                          href: route('employees.index', { status: 'transferred' }),
                          icon: Users,
                      },
                  ]
                : []),
        ],
        [can],
    );

    const items = useMemo<Item[]>(() => {
        const term = query.trim().toLowerCase();
        const opensPositions = can('directories.view.positions');

        return [
            ...results.employees.map((person) => ({
                key: `employee-${person.id}`,
                group: 'Сотрудники',
                label: person.name,
                hint: person.positions.length ? person.positions.join(', ') : (person.email ?? undefined),
                href: route('employees.show', person.id),
                icon: <PersonFace id={person.id} name={person.name} avatar={person.avatar} plain className="size-8 text-[11px]" />,
            })),
            ...results.equipment.map((unit) => ({
                key: `equipment-${unit.id}`,
                group: 'Оборудование',
                label: unit.name,
                hint: [`инв. № ${unit.inventory_number}`, unit.holder ?? statusLabel[unit.status]].join(' · '),
                href: route('equipment.show', unit.id),
                icon: <CategoryChip icon={unit.icon} size={32} iconSize={16} />,
            })),
            ...results.departments.map((department) => ({
                key: `department-${department.id}`,
                group: 'Отделы',
                label: department.name,
                // A result row has a line under it for the fuller text, which
                // beats a hover: it reads the same on a phone as on a desktop.
                hint: department.full_name === department.name ? undefined : department.full_name,
                href: route('departments.show', department.id),
                icon: iconBox(Network),
            })),
            // A position has a page of its own now; for whoever may not open that
            // section the hit still leads to the staff who hold it, as it used to.
            ...results.positions.map((position) => ({
                key: `position-${position.id}`,
                group: 'Должности',
                label: position.name,
                hint: opensPositions ? undefined : 'Сотрудники с этой должностью',
                href: opensPositions ? route('positions.show', position.id) : route('employees.index', { position: [position.id] }),
                icon: iconBox(Briefcase),
            })),
            ...results.roles.map((role) => ({
                key: `role-${role.name}`,
                group: 'Роли доступа',
                label: role.title,
                hint: 'Сотрудники с этой позицией',
                href: route('employees.index', { role: [role.name] }),
                icon: iconBox(IdCard),
            })),
            ...results.equipmentTypes.map((type) => ({
                key: `equipment-type-${type.id}`,
                group: 'Категории техники',
                label: type.name,
                hint: 'Техника этой категории',
                href: route('inventory.overview', { tab: 'all', type: [type.id] }),
                icon: <CategoryChip icon={type.icon} size={32} iconSize={16} />,
            })),
            ...results.languages.map((language) => ({
                key: `language-${language.id}`,
                group: 'Языки',
                label: language.name,
                hint: 'Сотрудники, которые им владеют',
                href: route('employees.index', { language: [language.id] }),
                icon: iconBox(Languages),
            })),
            // With nothing typed, these three work as quick navigation; with
            // something typed they are matched by their own words and by the
            // section they belong to, so «мебель» finds both the page and the
            // form that adds a desk to it.
            ...reached('Разделы', pages, term),
            ...reached('Формы', forms, term),
            ...reached('Операции', operations, term),
        ];
    }, [results, pages, forms, operations, query, can]);

    // A new set of results starts from the top, list and mark together — and a
    // set is new when what is in it is new, not when the array holding it is.
    // Judged by the array, a render that changed nothing would put the mark
    // back on the first row and the list back at its top, which is what a
    // cursor crossing a row does.
    const shown = items.map((one) => one.key).join('|');

    useEffect(() => {
        setActive(0);
        list.current?.scrollTo({ top: 0 });
    }, [shown]);

    useEffect(() => {
        // Only the keys scroll the list. A pointer is already where it wants to
        // be: scrolling under it would slide another row beneath the cursor,
        // that row would take the mark, and the two would chase each other
        // down the list for as long as the hand stayed still.
        if (!byKeys.current) return;

        byKeys.current = false;
        list.current?.querySelector(`[data-index="${active}"]`)?.scrollIntoView({ block: 'nearest' });
    }, [active]);

    const changeOpen = (next: boolean) => {
        setOpen(next);
        if (!next) setQuery('');
    };

    const go = (href: string) => {
        changeOpen(false);
        router.visit(href);
    };

    const onKeyDown = (event: KeyboardEvent<HTMLInputElement>) => {
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            if (!items.length) return;

            byKeys.current = true;
            setActive((current) => (current + (event.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length);
        } else if (event.key === 'Enter') {
            event.preventDefault();
            if (items[active]) go(items[active].href);
            // Nothing matched: the employee list has the full search and filters.
            else if (query.trim()) go(route('employees.index', { q: query.trim() }));
        }
    };

    return (
        <>
            <button
                type="button"
                onClick={() => setOpen(true)}
                className="border-input bg-background text-muted-foreground hover:text-foreground focus-visible:ring-ring hidden h-9 w-72 items-center gap-2 rounded-lg border px-3 text-sm shadow-xs outline-hidden focus-visible:ring-2 lg:flex"
            >
                <Search className="size-4 shrink-0" />
                <span className="flex-1 text-left">Поиск…</span>
                <kbd className="border-border bg-muted rounded border px-[5px] py-px font-sans text-[11px] font-medium">Ctrl K</kbd>
            </button>
            <button
                type="button"
                onClick={() => setOpen(true)}
                aria-label="Поиск"
                className="text-muted-foreground hover:text-foreground flex size-10 shrink-0 items-center justify-center rounded-md md:size-9 lg:hidden"
            >
                <Search className="size-5 md:size-4" />
            </button>

            <Dialog open={open} onOpenChange={changeOpen}>
                <DialogContent className="gap-0 overflow-hidden p-0 max-sm:top-0 max-sm:flex max-sm:h-dvh max-sm:max-h-none max-sm:flex-col max-sm:rounded-none max-sm:border-0 max-sm:pt-[env(safe-area-inset-top)] sm:top-[12vh] sm:max-w-xl sm:translate-y-0 [&>button:last-child]:top-1.5 sm:[&>button:last-child]:top-3.5 [&>div[aria-hidden]:first-child]:hidden">
                    <DialogTitle className="sr-only">Поиск</DialogTitle>
                    <DialogDescription className="sr-only">
                        Сотрудники, оборудование, отделы, должности, позиции, категории техники, языки и разделы.
                    </DialogDescription>

                    <label className="flex items-center gap-2 border-b px-4 pr-12">
                        {loading ? (
                            <LoaderCircle className="text-muted-foreground size-4 shrink-0 animate-spin" />
                        ) : (
                            <Search className="text-muted-foreground size-4 shrink-0" />
                        )}
                        <span className="sr-only">Что найти</span>
                        <input
                            autoFocus
                            value={query}
                            onChange={(event) => setQuery(event.target.value)}
                            onKeyDown={onKeyDown}
                            placeholder="Сотрудник, инв. номер, отдел, должность…"
                            role="combobox"
                            aria-expanded="true"
                            aria-controls="global-search-results"
                            aria-activedescendant={items[active] ? `global-search-${items[active].key}` : undefined}
                            className="h-12 min-w-0 flex-1 bg-transparent text-sm outline-hidden"
                        />
                    </label>

                    <ul
                        ref={list}
                        id="global-search-results"
                        role="listbox"
                        className="max-h-[60vh] overflow-y-auto p-2 max-sm:max-h-none max-sm:flex-1"
                    >
                        {items.map((item, index) => (
                            <li key={item.key} role="presentation">
                                {item.group !== items[index - 1]?.group && (
                                    <div className="text-muted-foreground px-2 pt-2 pb-1 text-xs font-medium">{item.group}</div>
                                )}
                                <div
                                    id={`global-search-${item.key}`}
                                    data-index={index}
                                    role="option"
                                    aria-selected={index === active}
                                    onMouseEnter={() => setActive(index)}
                                    onClick={() => go(item.href)}
                                    className={cn('flex cursor-pointer items-center gap-3 rounded-md px-2 py-1.5', index === active && 'bg-accent')}
                                >
                                    {item.icon}
                                    <span className="flex min-w-0 flex-1 flex-col">
                                        <span className="truncate text-sm font-medium">{item.label}</span>
                                        {item.hint && <span className="text-muted-foreground truncate text-xs">{item.hint}</span>}
                                    </span>
                                    {index === active && <CornerDownLeft className="text-muted-foreground size-4 shrink-0" />}
                                </div>
                            </li>
                        ))}

                        {items.length === 0 && !loading && (
                            <li className="text-muted-foreground px-2 py-8 text-center text-sm">
                                Ничего не нашлось.
                                {query.trim() && <span className="block">Enter — искать «{query.trim()}» по всем полям в списке сотрудников.</span>}
                            </li>
                        )}
                    </ul>
                </DialogContent>
            </Dialog>
        </>
    );
}
