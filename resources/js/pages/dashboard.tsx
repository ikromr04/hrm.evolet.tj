import { DepartmentName } from '@/components/department-name';
import { MobileFab } from '@/components/mobile-fab';
import { StatusBadge, type StatusTone } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { useCan } from '@/lib/access';
import { shortMonths } from '@/lib/employee';
import { eventLabel, eventTone, type EventKind } from '@/lib/equipment';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { format, parseISO } from 'date-fns';
import { ru } from 'date-fns/locale';
import { ChevronRight, Laptop, Plus, UserMinus, UserPlus, Users, type LucideIcon } from 'lucide-react';
import { type ReactNode } from 'react';

interface JournalRow {
    id: number;
    kind: EventKind;
    at: string | null;
    unit: { id: number; name: string; inventory_number: string | null } | null;
    actor: string | null;
}

interface DashboardProps {
    /** The first day of the recent stretch the tiles count, and today. */
    since: string;
    today: string;
    staff: { active: number; hired: number; fired: number };
    equipment: { issued: number; stock: number; service: number };
    departments: { id: number; name: string; full_name: string; count: number }[];
    events: JournalRow[];
}

interface Stat {
    key: string;
    label: string;
    value: number;
    href: string;
    icon: LucideIcon;
    notes?: { text: string; tone: StatusTone }[];
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Главная', href: '/dashboard' }];

function capitalize(text: string) {
    return text.charAt(0).toUpperCase() + text.slice(1);
}

/**
 * A block of the page. On a desktop its title sits inside the card; on a phone
 * it moves above it, like a grouped list in a phone's settings, so the card
 * holds nothing but the rows and the title does not cost a line of its own.
 */
function Section({ title, aside, children, className }: { title: string; aside?: ReactNode; children: ReactNode; className?: string }) {
    return (
        <section className="flex flex-col">
            <div className="mb-1.5 flex items-center gap-3 px-1 md:hidden">
                <h2 className="text-muted-foreground flex-1 text-[13px] font-semibold tracking-wide uppercase">{title}</h2>
                {aside}
            </div>
            <Card
                className={cn(
                    'flex flex-col gap-4 rounded-xl px-4 py-4 max-md:rounded-2xl max-md:border-0 max-md:py-1 max-md:shadow-none md:flex-1 md:px-6 md:py-5',
                    className,
                )}
            >
                <div className="flex items-center gap-3 max-md:hidden">
                    <h2 className="flex-1 text-base font-semibold">{title}</h2>
                    {aside}
                </div>
                {children}
            </Card>
        </section>
    );
}

function SectionLink({ href, children }: { href: string; children: ReactNode }) {
    return (
        <Link
            href={href}
            className="text-muted-foreground hover:text-foreground max-md:text-brand-strong flex items-center gap-0.5 text-sm font-medium max-md:-my-2 max-md:py-2 max-md:text-[15px] max-md:dark:text-[#C5E27A]"
        >
            {children}
            <ChevronRight className="size-4" />
        </Link>
    );
}

function Empty({ children }: { children: ReactNode }) {
    return <p className="text-muted-foreground text-sm max-md:py-3">{children}</p>;
}

function StatCard({ stat }: { stat: Stat }) {
    const Icon = stat.icon;

    return (
        // On a phone two tiles share a row, so a tile is compact: the icon over
        // the label, the number under them, and no border on the grey page.
        <Link href={stat.href} className="group min-w-0 rounded-xl max-md:rounded-2xl max-md:active:opacity-70">
            <Card className="group-hover:border-brand/60 flex h-full flex-col gap-3.5 rounded-xl p-4 transition-colors max-md:gap-2 max-md:rounded-2xl max-md:border-0 max-md:p-3 max-md:shadow-none md:p-5">
                <div className="text-muted-foreground flex items-center gap-2.5 text-sm font-medium max-md:flex-col max-md:items-start max-md:gap-2 max-md:text-[13px] max-md:leading-tight">
                    <span className="bg-brand-soft text-brand-strong flex size-8 items-center justify-center rounded-lg max-md:size-7 dark:bg-white/10 dark:text-[#C5E27A]">
                        <Icon className="size-[18px] max-md:size-4" />
                    </span>
                    {stat.label}
                </div>
                <div className="flex flex-wrap items-baseline gap-2.5 max-md:mt-auto max-md:gap-1.5">
                    <span className="text-3xl font-bold tracking-tight tabular-nums max-md:w-full max-md:text-2xl">{stat.value}</span>
                    {stat.notes?.map((note) => (
                        <StatusBadge key={note.text} tone={note.tone}>
                            {note.text}
                        </StatusBadge>
                    ))}
                </div>
            </Card>
        </Link>
    );
}

/** "Инв. № EV-0012 · Рахимов Фарход": what a journal line adds under the unit's name. */
function eventDetails(event: JournalRow): string {
    const parts = [event.unit?.inventory_number ? `Инв. № ${event.unit.inventory_number}` : null, event.actor];

    return parts.filter(Boolean).join(' · ') || '—';
}

export default function Dashboard({ since, today, staff, equipment, departments, events }: DashboardProps) {
    // Adding a colleague is a right of its own, so the button is only offered
    // to whoever holds it rather than leading everybody else to a refusal.
    const canCreate = useCan()('employees.create');
    const heading = capitalize(format(parseISO(today), 'EEEE, d MMMM yyyy', { locale: ru }));
    const maxDepartment = Math.max(...departments.map((department) => department.count), 1);

    const equipmentNotes: Stat['notes'] = [{ text: `${equipment.stock} на балансе`, tone: 'neutral' }];
    if (equipment.service > 0) {
        equipmentNotes.push({ text: `${equipment.service} в обслуживании`, tone: 'warning' });
    }

    const stats: Stat[] = [
        { key: 'active', label: 'Работают', value: staff.active, href: '/employees', icon: Users },
        {
            key: 'hired',
            label: 'Принято за 30 дней',
            value: staff.hired,
            href: `/employees?hired_from=${since}&hired_to=${today}`,
            icon: UserPlus,
        },
        { key: 'fired', label: 'Уволено за 30 дней', value: staff.fired, href: '/employees?status=fired', icon: UserMinus },
        { key: 'equipment', label: 'Техника на руках', value: equipment.issued, href: '/equipment?tab=issued', icon: Laptop, notes: equipmentNotes },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Главная" />

            <div className="flex flex-1 flex-col gap-4 p-3 max-md:gap-3 md:px-5 md:py-4">
                {/* The phone's top bar already names the page, so there only the date stays, as a quiet line. */}
                <div className="flex flex-wrap items-end gap-4 max-md:-mb-1 max-md:px-1">
                    <div className="flex flex-1 flex-col gap-1">
                        <h1 className="text-xl font-semibold tracking-tight max-md:sr-only">Обзор</h1>
                        <p className="text-muted-foreground text-sm max-md:text-[13px]">{heading}</p>
                    </div>
                    {canCreate && (
                        <>
                            <Button className="h-10 max-md:hidden lg:h-8" asChild>
                                <Link href="/employees/create">
                                    <Plus />
                                    Добавить сотрудника
                                </Link>
                            </Button>
                            <MobileFab href="/employees/create" label="Добавить сотрудника" />
                        </>
                    )}
                </div>

                <div className="grid grid-cols-2 gap-2.5 md:gap-5 xl:grid-cols-4">
                    {stats.map((stat) => (
                        <StatCard key={stat.key} stat={stat} />
                    ))}
                </div>

                <div className="grid gap-3 md:gap-5 lg:grid-cols-[minmax(0,1.55fr)_minmax(0,1fr)]">
                    <Section title="Численность по отделам" aside={<SectionLink href="/departments">Структура</SectionLink>}>
                        {departments.length === 0 ? (
                            <Empty>Отделов пока нет.</Empty>
                        ) : (
                            // On a phone a fixed name column would squeeze both the name
                            // and the bar, so each department is a row of its own: the
                            // name and the count on one line, the bar full width under them.
                            <ul className="max-md:divide-border/60 flex flex-col gap-3.5 max-md:gap-0 max-md:divide-y">
                                {departments.map((department) => (
                                    <li
                                        key={department.id}
                                        className="grid grid-cols-[120px_minmax(0,1fr)_36px] items-center gap-3 text-sm max-md:grid-cols-[minmax(0,1fr)_auto] max-md:gap-x-3 max-md:gap-y-2 max-md:py-3"
                                    >
                                        <Link
                                            href={`/departments/${department.id}`}
                                            className="text-muted-foreground hover:text-foreground max-md:text-foreground truncate max-md:text-[15px]"
                                            title={department.full_name}
                                        >
                                            <DepartmentName name={department.name} full={department.full_name} tooltip={false} />
                                        </Link>
                                        <div className="bg-muted h-2.5 rounded-full max-md:order-last max-md:col-span-2 max-md:h-1.5">
                                            <div
                                                className="bg-brand h-2.5 rounded-full"
                                                style={{ width: `${Math.round((department.count / maxDepartment) * 100)}%` }}
                                            />
                                        </div>
                                        <span className="text-right font-semibold tabular-nums max-md:text-[15px]">{department.count}</span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Section>

                    <Section title="Операции с техникой" aside={<SectionLink href="/equipment/journal">Журнал</SectionLink>}>
                        {events.length === 0 ? (
                            <Empty>Операций с техникой пока не было.</Empty>
                        ) : (
                            <ul className="max-md:divide-border/60 flex flex-col gap-3.5 max-md:gap-0 max-md:divide-y">
                                {events.map((event) => {
                                    const date = event.at ? parseISO(event.at) : null;

                                    return (
                                        // On a phone the badge drops under the name: beside it,
                                        // «Обслуживание завершено» alone would leave the name of
                                        // the unit no room at all. From sm up it is one line again.
                                        <li
                                            key={event.id}
                                            className="grid grid-cols-[auto_minmax(0,1fr)] items-center gap-x-3 gap-y-1 max-md:py-3 sm:flex sm:items-center sm:gap-3.5"
                                        >
                                            <time
                                                dateTime={event.at ?? undefined}
                                                className="bg-muted row-span-2 flex h-[52px] w-12 shrink-0 flex-col items-center justify-center rounded-lg max-md:h-11 max-md:w-11 max-md:rounded-xl"
                                            >
                                                <span className="text-lg leading-none font-bold tabular-nums max-md:text-base">
                                                    {date ? format(date, 'dd') : '—'}
                                                </span>
                                                <span className="text-muted-foreground text-xs">{date ? shortMonths[date.getMonth()] : ''}</span>
                                            </time>
                                            <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                                                {event.unit ? (
                                                    <Link
                                                        href={`/equipment/${event.unit.id}`}
                                                        className="truncate text-sm font-semibold hover:underline max-md:text-[15px] max-md:font-medium"
                                                    >
                                                        {event.unit.name}
                                                    </Link>
                                                ) : (
                                                    <span className="text-muted-foreground truncate text-sm font-semibold">Единица удалена</span>
                                                )}
                                                <span className="text-muted-foreground truncate text-[13px]">{eventDetails(event)}</span>
                                            </div>
                                            <StatusBadge tone={eventTone[event.kind]} className="justify-self-start">
                                                {eventLabel[event.kind]}
                                            </StatusBadge>
                                        </li>
                                    );
                                })}
                            </ul>
                        )}
                    </Section>
                </div>
            </div>
        </AppLayout>
    );
}
