import {
    clearedFilter,
    countActiveFilters,
    DataTable,
    MobileListTools,
    resetView,
    useRememberedQuery,
    useTableView,
    type ColumnDef,
    type Sort,
    type ViewState,
} from '@/components/data-table';
import { ChangeLines, EventRow } from '@/components/equipment-changes';
import { CategoryChip } from '@/components/equipment-icon';
import { Pagination, type Paginated } from '@/components/pagination';
import { PersonFace } from '@/components/person-face';
import { PersonLink } from '@/components/person-link';
import { Photos, type Photo } from '@/components/photo-viewer';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { eventLabel, eventTone, formatMoment, type EventChanges, type EventKind, type NameLookup } from '@/lib/equipment';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { ChevronDown, Columns3, RotateCcw, X } from 'lucide-react';
import { useMemo, useState } from 'react';

interface JournalEvent {
    id: number;
    photos: Photo[];
    kind: EventKind;
    /** Field => [before, after], for the fields that actually changed. */
    changes: EventChanges;
    note: string | null;
    at: string | null;
    unit: { id: number; name: string; inventory_number: string; type: string | null; type_icon: string | null } | null;
    actor: { id: number; name: string; avatar: string | null } | null;
}

interface Filters {
    /** Empty until a period is asked for: the journal opens on everything. */
    from: string | null;
    to: string | null;
    kind: EventKind[];
    type: number[];
    actor: number[];
    unit: string;
}

interface Props {
    events: Paginated<JournalEvent>;
    /** Names for the ids the entries kept: field => { id: name }. */
    names: NameLookup;
    filters: Filters;
    perPage: number;
    perPageOptions: number[];
    options: {
        types: { id: number; name: string }[];
        actors: { id: number; name: string }[];
    };
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Оборудование', href: '/equipment' },
    { title: 'Журнал операций', href: '/equipment/journal' },
];

const STORAGE_KEY = 'equipment.journal.view.v1';
const DEFAULT_SORT: Sort = { key: 'at', direction: 'desc' };

/** The columns, each with the filter that narrows it. */
function buildColumns(options: Props['options']): ColumnDef[] {
    return [
        { key: 'at', label: 'Когда', width: 170, filter: { type: 'dates', from: 'from', to: 'to' } },
        {
            key: 'unit',
            label: 'Оборудование',
            width: 320,
            filter: { type: 'text', param: 'unit', placeholder: 'Название или инв. номер' },
        },
        {
            key: 'kind',
            label: 'Операция',
            width: 220,
            filter: {
                type: 'multi',
                param: 'kind',
                options: (Object.keys(eventLabel) as EventKind[]).map((kind) => ({ value: kind, label: eventLabel[kind] })),
            },
        },
        {
            key: 'type',
            label: 'Категория',
            width: 160,
            filter: { type: 'multi', param: 'type', options: options.types.map((type) => ({ value: type.id, label: type.name })) },
        },
        {
            key: 'actor',
            label: 'Кто',
            width: 230,
            filter: { type: 'multi', param: 'actor', options: options.actors.map((actor) => ({ value: actor.id, label: actor.name })) },
        },
        { key: 'changes', label: 'Что изменилось', width: 420 },
    ];
}

/** The periods asked for most often, a click away. */
const presets = [
    { days: 7, label: 'Неделя' },
    { days: 30, label: 'Месяц' },
    { days: 90, label: 'Квартал' },
    { days: 365, label: 'Год' },
];

const defaultView = (): ViewState => ({ hidden: [], pinned: { left: ['at'], right: [] } });

export default function EquipmentJournal({ events, names, filters, perPage, perPageOptions, options }: Props) {
    const columns = useMemo(() => buildColumns(options), [options]);
    const defaults = useMemo(defaultView, []);
    const { view, setView, pin, toggleHidden } = useTableView(
        STORAGE_KEY,
        columns.map((column) => column.key),
        defaults,
    );
    const isHidden = (key: string) => view.hidden.includes(key);

    useRememberedQuery('equipment.journal.query');
    const [from, setFrom] = useState(filters.from ?? '');
    const [to, setTo] = useState(filters.to ?? '');

    /** Everything the journal is looking at, as one query string. */
    const visit = (next: { filters?: Partial<Filters>; perPage?: number }) => {
        const merged = { ...filters, ...next.filters };

        type Value = string | number | (string | number)[] | null;
        const params: Record<string, Value> = {
            ...merged,
            per_page: (next.perPage ?? perPage) === perPageOptions[0] ? null : (next.perPage ?? perPage),
        };

        const query = Object.fromEntries(
            Object.entries(params).filter(([, value]) => (Array.isArray(value) ? value.length > 0 : value !== null && value !== '')),
        ) as Record<string, Exclude<Value, null>>;

        router.get(route('equipment.journal'), query, { preserveState: true, preserveScroll: true, replace: true });
    };

    /** The periods asked for most often, a click away. */
    const period = (days: number) => {
        const end = new Date();
        const start = new Date();
        start.setDate(end.getDate() - (days - 1));

        const iso = (date: Date) => date.toISOString().slice(0, 10);
        setFrom(iso(start));
        setTo(iso(end));
        visit({ filters: { from: iso(start), to: iso(end) } });
    };

    const today = new Date().toISOString().slice(0, 10);
    // A stretch of exactly a month that ended last year is not "Месяц", so a
    // preset counts as in force only when the period runs up to today. With no
    // period at all, none of them does.
    const inForce = (days: number) => {
        if (filters.from === null || filters.to !== today) return false;

        return Math.round((new Date(filters.to).getTime() - new Date(filters.from).getTime()) / 86400000) + 1 === days;
    };

    // The period is a filter like any other now that the page can open without
    // one, so it is counted and cleared along with the rest.
    const narrowing = columns.filter((column) => column.filter);
    const activeFilters = countActiveFilters(narrowing, filters as unknown as Record<string, unknown>, () => true);

    const cell = (column: ColumnDef, event: JournalEvent) => {
        switch (column.key) {
            case 'at':
                return <span className="tabular-nums">{formatMoment(event.at)}</span>;
            case 'unit':
                return event.unit ? (
                    <Link
                        href={route('equipment.show', event.unit.id)}
                        className="group flex items-center gap-3"
                        title={`Открыть: ${event.unit.name}`}
                    >
                        <CategoryChip icon={event.unit.type_icon} size={32} iconSize={16} />
                        <div className="flex min-w-0 flex-col gap-0.5">
                            <span className="text-brand-strong truncate font-medium group-hover:underline dark:text-[#C5E27A]">
                                {event.unit.name}
                            </span>
                            <span className="text-muted-foreground truncate text-[13px] tabular-nums">инв. № {event.unit.inventory_number}</span>
                        </div>
                    </Link>
                ) : (
                    <span className="text-muted-foreground">Единица удалена</span>
                );
            case 'kind':
                return <StatusBadge tone={eventTone[event.kind]}>{eventLabel[event.kind]}</StatusBadge>;
            case 'type':
                return <span className="text-muted-foreground">{event.unit?.type ?? '—'}</span>;
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

    return (
        <AppLayout breadcrumbs={breadcrumbs} fitViewport>
            <Head title="Журнал операций" />

            <div className="flex flex-1 flex-col gap-4 p-3 max-md:gap-3 md:min-h-0 md:px-5 md:py-4">
                {/* The phone's top bar already carries the page's name. */}
                <h1 className="text-xl font-semibold tracking-tight max-md:sr-only">Журнал операций</h1>

                {/* On a phone one line: the quick periods as chips and the button to
                    the sheet, where an exact period is picked along with the rest. */}
                <div className="flex flex-wrap items-center gap-2 max-md:flex-nowrap md:-mb-2">
                    {/* The period keeps to one line: on a phone its two boxes share the width. */}
                    <div className="flex w-full items-center gap-2 max-md:hidden sm:w-auto">
                        <Input
                            type="date"
                            aria-label="Период с"
                            value={from}
                            max={to || undefined}
                            onChange={(event) => setFrom(event.target.value)}
                            onBlur={() => from !== (filters.from ?? '') && visit({ filters: { from: from || null } })}
                            className="h-10 min-w-0 flex-1 sm:w-40 sm:flex-none lg:h-8"
                        />

                        <span className="text-muted-foreground text-sm">–</span>

                        <Input
                            type="date"
                            aria-label="Период по"
                            value={to}
                            min={from || undefined}
                            onChange={(event) => setTo(event.target.value)}
                            onBlur={() => to !== (filters.to ?? '') && visit({ filters: { to: to || null } })}
                            className="h-10 min-w-0 flex-1 sm:w-40 sm:flex-none lg:h-8"
                        />
                    </div>

                    <div className="grid w-full grid-cols-4 gap-1 max-md:hidden sm:flex sm:w-auto">
                        {presets.map((preset) => (
                            <Button
                                key={preset.days}
                                variant={inForce(preset.days) ? 'default' : 'outline'}
                                aria-pressed={inForce(preset.days)}
                                className="h-10 min-w-0 px-2 sm:px-4 lg:h-8"
                                onClick={() => period(preset.days)}
                            >
                                {preset.label}
                            </Button>
                        ))}
                    </div>

                    {/* The same periods on a phone, as a row of chips that scrolls sideways if it must. */}
                    <div className="flex min-w-0 flex-1 items-center gap-1.5 overflow-x-auto [scrollbar-width:none] md:hidden [&::-webkit-scrollbar]:hidden">
                        {presets.map((preset) => (
                            <button
                                key={preset.days}
                                type="button"
                                aria-pressed={inForce(preset.days)}
                                onClick={() => period(preset.days)}
                                className={cn(
                                    'h-8 shrink-0 rounded-full px-3 text-sm whitespace-nowrap transition-colors',
                                    inForce(preset.days) ? 'bg-brand-soft text-foreground font-semibold dark:bg-white/10' : 'bg-card',
                                )}
                            >
                                {preset.label}
                            </button>
                        ))}
                    </div>

                    <MobileListTools
                        columns={columns}
                        filters={filters as unknown as Record<string, unknown>}
                        onFilter={(changes) => {
                            // The desktop's period boxes follow, so they show what is in force.
                            const next = changes as Partial<Filters>;
                            if ('from' in next) setFrom(next.from ?? '');
                            if ('to' in next) setTo(next.to ?? '');
                            visit({ filters: next });
                        }}
                        sort={DEFAULT_SORT}
                        sortable={[]}
                        onSort={() => undefined}
                        className="max-md:bg-card max-md:rounded-xl max-md:border-transparent max-md:shadow-none"
                    />

                    {activeFilters > 0 && (
                        <Button
                            variant="ghost"
                            // On a phone the filters are cleared in their own sheet.
                            className="h-10 max-md:hidden lg:h-8"
                            onClick={() => {
                                // The boxes empty with it, or they would keep
                                // showing a period that is no longer in force.
                                setFrom('');
                                setTo('');
                                visit({
                                    filters: narrowing.reduce<Partial<Filters>>((acc, column) => ({ ...acc, ...clearedFilter(column.filter!) }), {}),
                                });
                            }}
                        >
                            <X />
                            Сбросить фильтры ({activeFilters})
                        </Button>
                    )}

                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button variant="outline" className="h-10 font-normal max-md:hidden sm:ml-auto lg:h-8">
                                <Columns3 />
                                Колонки
                                <ChevronDown className="text-muted-foreground" />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" className="max-h-96 w-60 overflow-y-auto">
                            <DropdownMenuLabel>Показывать колонки</DropdownMenuLabel>
                            {columns.map((column) => (
                                <DropdownMenuCheckboxItem
                                    key={column.key}
                                    checked={!isHidden(column.key)}
                                    disabled={column.key === 'at'}
                                    onCheckedChange={(checked) => toggleHidden(column.key, !checked)}
                                    onSelect={(event) => event.preventDefault()}
                                >
                                    {column.label}
                                </DropdownMenuCheckboxItem>
                            ))}
                            <DropdownMenuSeparator />
                            <DropdownMenuItem
                                onSelect={() => {
                                    resetView(STORAGE_KEY);
                                    setView(defaults);
                                }}
                            >
                                <RotateCcw />
                                Сбросить вид
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>

                    <Button variant="outline" className="h-10 max-md:hidden lg:h-8" asChild>
                        {/* The list of units, which is what the button says — the page opens on who holds what. */}
                        <Link href={route('equipment.index', { view: 'list' })}>К списку оборудования</Link>
                    </Button>
                </div>

                <DataTable
                    columns={columns}
                    rows={events.data}
                    rowKey={(event) => event.id}
                    renderCell={cell}
                    sort={DEFAULT_SORT}
                    sortable={[]}
                    onSort={() => undefined}
                    filters={filters as unknown as Record<string, unknown>}
                    onFilter={(changes) => visit({ filters: changes as Partial<Filters> })}
                    view={view}
                    onPin={pin}
                    onHide={(key) => toggleHidden(key, true)}
                    lockedKey="at"
                    empty={<span className={cn('text-sm')}>За этот период операций не было.</span>}
                    mobileRow={(event) => (
                        <EventRow
                            kind={event.kind}
                            at={event.at}
                            actor={event.actor}
                            changes={event.changes}
                            names={names}
                            note={event.note}
                            photos={event.photos}
                            unit={event.unit}
                        />
                    )}
                    footer={
                        <>
                            <div className="text-muted-foreground flex items-center gap-2 text-sm max-md:hidden">
                                Строк на странице
                                <DropdownMenu>
                                    <DropdownMenuTrigger asChild>
                                        <Button variant="outline" size="sm" className="h-8 gap-1 px-2.5 tabular-nums">
                                            {perPage}
                                            <ChevronDown className="text-muted-foreground" />
                                        </Button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent align="start" className="min-w-20">
                                        <DropdownMenuRadioGroup value={String(perPage)} onValueChange={(value) => visit({ perPage: Number(value) })}>
                                            {perPageOptions.map((option) => (
                                                <DropdownMenuRadioItem key={option} value={String(option)} className="tabular-nums">
                                                    {option}
                                                </DropdownMenuRadioItem>
                                            ))}
                                        </DropdownMenuRadioGroup>
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            </div>
                            <Pagination paginator={events} className="min-w-0 flex-1" />
                        </>
                    }
                />
            </div>
        </AppLayout>
    );
}
