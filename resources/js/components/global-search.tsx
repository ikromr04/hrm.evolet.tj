import { CategoryChip } from '@/components/equipment-icon';
import { PersonFace } from '@/components/person-face';
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog';
import { readsEquipmentJournal, seesDirectories, seesEquipment, useCan } from '@/lib/access';
import { type EquipmentStatus, statusLabel } from '@/lib/equipment';
import { cn } from '@/lib/utils';
import { router } from '@inertiajs/react';
import {
    BookMarked,
    Briefcase,
    CornerDownLeft,
    History,
    IdCard,
    Languages,
    Laptop,
    LayoutGrid,
    LoaderCircle,
    type LucideIcon,
    Network,
    Search,
    Settings,
    Users,
} from 'lucide-react';
import { type KeyboardEvent, type ReactNode, useEffect, useMemo, useRef, useState } from 'react';

interface SearchResults {
    employees: { id: number; name: string; avatar: string | null; email: string; positions: string[] }[];
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
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const [active, setActive] = useState(0);
    const { results, loading } = useSearch(open ? query : '');
    const list = useRef<HTMLUListElement>(null);

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

    const pages = useMemo(
        () =>
            [
                { label: 'Главная', href: '/dashboard', icon: LayoutGrid },
                ...(can('employees.view') ? [{ label: 'Сотрудники', href: '/employees', icon: Users }] : []),
                { label: 'Структура компании', href: '/departments', icon: Network },
                // Any part of the fleet — own, the department's or all of it — opens the section.
                ...(seesEquipment(can) ? [{ label: 'Оборудование', href: '/equipment', icon: Laptop }] : []),
                // The journal opens for whoever may read it on any part of the fleet;
                // the page itself shows only the entries of that part.
                ...(readsEquipmentJournal(can) ? [{ label: 'Журнал операций', href: '/equipment/journal', icon: History }] : []),
                ...(can('directories.view.positions') ? [{ label: 'Должности', href: '/positions', icon: Briefcase }] : []),
                // One of the five lists is enough: the section opens on the first
                // one its viewer may read.
                ...(seesDirectories(can) ? [{ label: 'Справочники', href: '/directories', icon: BookMarked }] : []),
                { label: 'Настройки', href: '/settings', icon: Settings },
            ] as const,
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
                hint: person.positions.length ? person.positions.join(', ') : person.email,
                href: route('employees.show', person.id),
                icon: <PersonFace id={person.id} name={person.name} avatar={person.avatar} className="size-8 text-[11px]" />,
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
                group: 'Позиции',
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
                href: route('equipment.index', { tab: 'all', type: [type.id] }),
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
            // With nothing typed, the pages work as quick navigation.
            ...pages
                .filter((page) => !term || page.label.toLowerCase().includes(term))
                .map((page) => ({ key: `page-${page.href}`, group: 'Разделы', label: page.label, href: page.href, icon: iconBox(page.icon) })),
        ];
    }, [results, pages, query, can]);

    // A new set of results starts from the top.
    useEffect(() => setActive(0), [items]);

    useEffect(() => {
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
            if (items.length) setActive((current) => (current + (event.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length);
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
                className="border-input bg-background text-muted-foreground hover:text-foreground focus-visible:ring-ring hidden h-9 w-72 items-center gap-2 rounded-md border px-3 text-sm shadow-xs outline-hidden focus-visible:ring-2 lg:flex"
            >
                <Search className="size-4 shrink-0" />
                <span className="flex-1 text-left">Поиск…</span>
                <kbd className="bg-muted rounded px-1.5 font-sans text-[11px] font-medium">Ctrl K</kbd>
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
                                    onMouseMove={() => setActive(index)}
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
