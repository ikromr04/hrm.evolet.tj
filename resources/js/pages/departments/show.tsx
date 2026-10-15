import { MobileRow } from '@/components/data-table';
import { DepartmentName, departmentTitle } from '@/components/department-name';
import { DepartmentRegulation, type Regulation } from '@/components/department-regulation';
import { CategoryChip } from '@/components/equipment-icon';
import { PhoneViewSwitch } from '@/components/org-chart';
import { PersonFace } from '@/components/person-face';
import { PersonLink } from '@/components/person-link';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { useCan } from '@/lib/access';
import { peopleLabel } from '@/lib/employee';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { ChevronRight, ClipboardList, CornerDownRight, List, Mail, Users } from 'lucide-react';
import { useEffect, useRef, useState, type KeyboardEvent, type ReactNode } from 'react';

interface Person {
    id: number;
    /** "Фамилия Имя" */
    name: string;
    avatar: string | null;
}

interface Colleague extends Person {
    email: string;
    positions: string[];
}

interface Department {
    id: number;
    /** The abbreviation, or the full name when there is none. */
    name: string;
    full_name: string;
    /** From the top of the tree down to the direct parent. */
    parents: { id: number; name: string; full_name: string }[];
    /** Working people here and in sub-departments, heads included. */
    total_count: number;
    heads: Colleague[];
    children: { id: number; name: string; full_name: string; total_count: number; heads: Person[] }[];
    /** Working members who do not lead it. */
    members: Colleague[];
}

/**
 * What the page is showing.
 *
 * On a desktop the list is not one of these — it stands in the column beside,
 * always — so there the choice is only which document the main column holds. On
 * a phone there is one screen and the three are peers, so the switch there
 * offers all three rather than hiding two of them inside the third: a second
 * strip of tabs under a segmented control is two presses and two rows to reach
 * what one press and one row can.
 */
type View = 'list' | 'regulation' | 'sfd' | 'equipment';

/** What the main column can hold, which is everything but the list. */
type Document = Exclude<View, 'list'>;

/**
 * The three things the main column holds. The equipment is not a document, but
 * it is read the same way and from the same place — what belongs to this
 * department — so it is a tab beside the other two rather than a page of its
 * own that the structure would have to link to.
 */
const DOCUMENTS: { key: Document; title: string }[] = [
    { key: 'regulation', title: 'Положение' },
    { key: 'sfd', title: 'СФД' },
    { key: 'equipment', title: 'Оборудование' },
];

/** Bumped once already with the default below, or a saved 'chart' would still win. */
const VIEW_KEY = 'department.view.v2';

/**
 * The list opens the page on a phone: it is what a department is usually opened
 * for — who works here — and the regulation is read rather than looked up, so it
 * is a step aside from that. A phone that remembers the chart remembers a view
 * this page no longer has, and opens on the list like any other.
 *
 * Only those two are remembered, as they always were. Nobody has asked for the
 * page to open on СФД, so choosing it is a choice for this visit.
 */
function savedView(): View {
    try {
        return localStorage.getItem(VIEW_KEY) === 'regulation' ? 'regulation' : 'list';
    } catch {
        return 'list';
    }
}

/**
 * Which document the main column holds, as a real set of tabs: the arrow keys
 * walk them, only the open one is in the tab order, and the one that is open is
 * underlined as well as darker, so it is not colour alone that says so.
 *
 * The look is the application's own, from the strip over «Структура компании» —
 * but not its machinery: those are links to other addresses, remembered between
 * visits because they are a section of the application. These two swap a panel
 * inside one page and are forgotten when it is left.
 *
 * A phone never sees this: its segmented control already offers the same two
 * beside the list.
 */
function DocumentTabs({ current, onChange }: { current: Document; onChange: (key: Document) => void }) {
    const strip = useRef<HTMLDivElement>(null);
    /** Whether the tab that just opened was reached by an arrow key. */
    const walked = useRef(false);

    // The tab an arrow key opens takes the focus with it, which is what the
    // arrow keys are expected to do — but only then, so that pressing one of
    // these, or switching on a phone, does not pull the focus across the page.
    // After the render rather than beside it: before it the tab that is to take
    // the focus is still the one being left.
    useEffect(() => {
        if (!walked.current) return;

        walked.current = false;
        strip.current?.querySelector<HTMLButtonElement>('[aria-selected="true"]')?.focus();
    }, [current]);

    const walk = (event: KeyboardEvent<HTMLDivElement>) => {
        const step = event.key === 'ArrowRight' ? 1 : event.key === 'ArrowLeft' ? -1 : 0;

        if (step === 0) return;

        event.preventDefault();
        walked.current = true;
        const at = DOCUMENTS.findIndex((tab) => tab.key === current);
        onChange(DOCUMENTS[(at + step + DOCUMENTS.length) % DOCUMENTS.length].key);
    };

    return (
        // Only the open tab is underlined; no rule runs under the strip. A line
        // across the whole column would be a second edge directly above the
        // card's own, and the card is what the tab opens onto.
        <div
            ref={strip}
            role="tablist"
            aria-label="Документы подразделения"
            onKeyDown={walk}
            className="flex gap-x-6 overflow-x-auto [scrollbar-width:none] max-md:hidden [&::-webkit-scrollbar]:hidden"
        >
            {DOCUMENTS.map((tab) => {
                const active = tab.key === current;

                return (
                    <button
                        key={tab.key}
                        type="button"
                        role="tab"
                        id={`department-tab-${tab.key}`}
                        aria-selected={active}
                        aria-controls={`department-panel-${tab.key}`}
                        tabIndex={active ? 0 : -1}
                        onClick={() => onChange(tab.key)}
                        className={cn(
                            'shrink-0 border-b-2 px-1 pt-2 pb-2.5 text-sm whitespace-nowrap transition-colors',
                            active
                                ? 'border-brand text-foreground font-semibold'
                                : 'text-muted-foreground hover:text-foreground border-transparent font-medium',
                        )}
                    >
                        {tab.title}
                    </button>
                );
            })}
        </div>
    );
}

/**
 * The sheets of this department's own positions — «СФД», a profile per job.
 *
 * A profile belongs to a position rather than to the department, so this tab is
 * a way in rather than a document: the list of what there is, each line opening
 * the sheet it names.
 *
 * Three things it may have to say, and they are three different things. Who may
 * not read job titles at all is told that, not that the department has none —
 * a department's page is open to everybody signed in, and the list of its posts
 * is not, so «nothing here» would be a lie that sends somebody looking for a
 * department that does not exist.
 */
function Profiles({ profiles }: { profiles: Profiles }) {
    if (!profiles.can_read) {
        return (
            <p className="text-muted-foreground px-4 py-4 text-sm md:px-6 md:py-5">Профили должностей доступны не всем — у вас нет доступа к ним.</p>
        );
    }

    if (profiles.positions.length === 0) {
        return <p className="text-muted-foreground px-4 py-4 text-sm md:px-6 md:py-5">В этом подразделении пока нет должностей.</p>;
    }

    return (
        <section className="flex flex-col gap-3 px-4 py-4 md:px-6 md:py-5">
            <h2 className="text-[17px] leading-snug font-semibold">Профили должностей</h2>

            <ul className="-mx-2 flex flex-col">
                {profiles.positions.map((position) => (
                    <li key={position.id}>
                        <Link
                            href={route('positions.show', position.id)}
                            className="hover:bg-muted/60 flex items-start gap-3 rounded-md px-2 py-2.5 max-md:min-h-12"
                        >
                            <ClipboardList className="text-muted-foreground mt-0.5 size-4 shrink-0" aria-hidden="true" />
                            <span className="flex min-w-0 flex-1 flex-col gap-0.5">
                                <span className="text-sm font-medium break-words">{position.name}</span>
                                <span className="text-muted-foreground text-[13px] break-words">{position.title}</span>
                            </span>
                            <ChevronRight className="text-muted-foreground/60 mt-0.5 size-4 shrink-0" aria-hidden="true" />
                        </Link>
                    </li>
                ))}
            </ul>
        </section>
    );
}

interface Profiles {
    /** Whether this viewer may see job titles at all. */
    can_read: boolean;
    can_edit: boolean;
    /** Empty both when there are none and when they are none of this viewer business. */
    positions: { id: number; name: string; title: string }[];
}

/** One unit of equipment as this tab names it; the holder only in the second half. */
interface Unit {
    id: number;
    name: string;
    inventory_number: string;
    type: string | null;
    type_icon: string | null;
    holder: { id: number; name: string } | null;
}

/**
 * The equipment of this department, in the two halves it is in: what the
 * department itself answers for, and what the people who work here hold.
 *
 * A reading and not a workshop. Nothing is issued, returned or written off
 * here — every line opens the unit's own page, where those things live and
 * where the journal says what has happened to it.
 *
 * Three things it may have to say, and they are three different things, as on
 * the tab before it. Equipment is a section with rights of its own, and a
 * department's page is open to everybody signed in: «ничего нет» told to
 * somebody who may not look would be a lie about the department rather than
 * the truth about them.
 */
function DepartmentEquipment({ units, loading }: { units: Equipment | undefined; loading: boolean }) {
    const line = 'text-muted-foreground px-4 py-4 text-sm md:px-6 md:py-5';

    // Asked for when the tab was opened and not yet answered. Said in words
    // rather than drawn as a skeleton: it is one line of text either way, and
    // a shape that pretends to be a list is worse than a sentence that says
    // what is happening.
    if (units === undefined) return <p className={line}>{loading ? 'Загружаем оборудование…' : 'Оборудование не загрузилось. Обновите страницу.'}</p>;

    if (units.own.length === 0 && units.people.length === 0) {
        return <p className={line}>За этим подразделением пока не числится оборудование.</p>;
    }

    return (
        <div className="flex flex-col gap-5 px-4 py-4 md:px-6 md:py-5">
            {units.own.length > 0 && (
                <UnitList
                    title="Закреплено за отделом"
                    count={units.own.length}
                    hint="Техника, за которую отвечает подразделение, а не отдельный сотрудник."
                    units={units.own}
                />
            )}

            {units.people.length > 0 && <UnitList title="У сотрудников" count={units.people.length} units={units.people} />}
        </div>
    );
}

/** One half of the tab: a heading, how many, and a line per unit. */
function UnitList({ title, count, hint, units }: { title: string; count: number; hint?: string; units: Unit[] }) {
    return (
        <section className="flex flex-col gap-2">
            <h2 className="flex items-baseline gap-2 text-[17px] leading-snug font-semibold">
                {title}
                <span className="text-muted-foreground text-sm font-normal tabular-nums">{count}</span>
            </h2>
            {hint && <p className="text-muted-foreground text-[13px]">{hint}</p>}

            <ul className="-mx-2 flex flex-col">
                {units.map((unit) => (
                    <li key={unit.id}>
                        <Link
                            href={route('equipment.show', unit.id)}
                            className="hover:bg-muted/60 flex items-start gap-3 rounded-md px-2 py-2.5 max-md:min-h-12"
                        >
                            <CategoryChip icon={unit.type_icon} size={32} iconSize={16} className="mt-0.5 shrink-0" />
                            <span className="flex min-w-0 flex-1 flex-col gap-0.5">
                                <span className="text-sm font-medium break-words">{unit.name}</span>
                                <span className="text-muted-foreground text-[13px] break-words">
                                    {/* Whose it is leads the line where there is
                                        somebody: this half is read to find out who
                                        has what. The number closes it, as it does
                                        everywhere a unit is named in one line. */}
                                    {unit.holder !== null && <span className="text-foreground">{unit.holder.name} · </span>}
                                    {unit.type}
                                    {' · инв. № '}
                                    <span className="tabular-nums">{unit.inventory_number}</span>
                                </span>
                            </span>
                            <ChevronRight className="text-muted-foreground/60 mt-0.5 size-4 shrink-0" aria-hidden="true" />
                        </Link>
                    </li>
                ))}
            </ul>
        </section>
    );
}

/** The two halves as the server sends them. */
interface Equipment {
    own: Unit[];
    people: Unit[];
}

function Section({ title, count, children }: { title: string; count?: number; children: ReactNode }) {
    return (
        <Card className="flex flex-col gap-3 rounded-xl px-4 py-4 md:px-6 md:py-5">
            <h2 className="flex items-baseline gap-2 text-base font-semibold">
                {title}
                {count !== undefined && <span className="text-muted-foreground text-sm font-normal tabular-nums">{count}</span>}
            </h2>
            {children}
        </Card>
    );
}

function Avatar({ person, className }: { person: Person; className?: string }) {
    return <PersonFace id={person.id} name={person.name} avatar={person.avatar} className={className} />;
}

/** One colleague: avatar, name linking to the profile, positions and email; `compact` fits a narrow column. */
function ColleagueRow({ person, compact }: { person: Colleague; compact?: boolean }) {
    const email = (
        <a
            href={`mailto:${person.email}`}
            className={cn(
                'text-brand-strong truncate hover:underline dark:text-[#C5E27A]',
                compact ? 'self-start text-[13px]' : 'hidden shrink-0 text-sm sm:block',
            )}
        >
            {person.email}
        </a>
    );

    return (
        <li className="flex items-center gap-3 border-t py-3 first:border-t-0 first:pt-0 last:pb-0">
            <Avatar person={person} />
            <div className="flex min-w-0 flex-1 flex-col gap-1">
                <PersonLink id={person.id} className="self-start text-sm font-medium hover:underline">
                    {person.name}
                </PersonLink>
                {compact && email}
                {person.positions.length > 0 && (
                    <div className="flex flex-wrap gap-1">
                        {person.positions.map((position) => (
                            <StatusBadge key={position} tone="success" className="h-auto min-h-[22px] whitespace-normal">
                                {position}
                            </StatusBadge>
                        ))}
                    </div>
                )}
            </div>
            {!compact && email}
        </li>
    );
}

/** A small heading over a grouped card, the way a phone's settings name their sections. */
function PhoneSection({ title, count, children }: { title: string; count?: number; children: ReactNode }) {
    return (
        <section className="flex flex-col">
            <h2 className="text-muted-foreground mb-1.5 flex items-baseline gap-1.5 px-1 text-[13px] font-semibold tracking-wide uppercase">
                {title}
                {count !== undefined && <span className="font-normal tabular-nums">{count}</span>}
            </h2>
            <Card className="gap-0 overflow-hidden rounded-2xl border-0 py-0 shadow-none">{children}</Card>
        </section>
    );
}

/** A note in place of an empty list inside a grouped card. */
function PhoneEmpty({ children }: { children: ReactNode }) {
    return <p className="text-muted-foreground px-4 py-3.5 text-[15px]">{children}</p>;
}

/**
 * Where a colleague's name leads, or nowhere: the rule of {@link PersonLink},
 * repeated because a phone row is one link as a whole and cannot hold another.
 */
function usePersonHref(): (id: number) => string | null {
    const { auth } = usePage<SharedData>().props;
    const can = useCan();

    return (id) => (auth.user?.id === id ? route('profile') : can('employees.view') ? route('employees.show', id) : null);
}

/** A colleague as a phone row; the envelope at the right writes to them without opening the card. */
function PhoneColleagueRow({ person, href }: { person: Colleague; href: string | null }) {
    return (
        <MobileRow
            href={href}
            leading={<Avatar person={person} className="size-10" />}
            title={person.name}
            subtitle={person.positions.length > 0 ? person.positions.join(', ') : person.email}
            trailing={
                <Button variant="ghost" size="icon" className="text-brand-strong size-10 dark:text-[#C5E27A]" asChild>
                    <a href={`mailto:${person.email}`} aria-label={`Написать: ${person.email}`} title={person.email}>
                        <Mail className="size-5" />
                    </a>
                </Button>
            }
        />
    );
}

export default function DepartmentPage({
    department,
    regulation,
    profiles,
    equipment,
    equipmentUnits,
}: {
    department: Department;
    regulation: Regulation;
    profiles: Profiles;
    /** Whether this viewer may read equipment at all; the list is fetched apart. */
    equipment: { can_read: boolean };
    /** Absent until the tab is opened, which is when the page asks for it. */
    equipmentUnits?: Equipment;
}) {
    const can = useCan();
    const [view, setView] = useState<View>(savedView);
    // Whether the list has been asked for on this visit, so that opening the
    // tab twice does not ask twice, and a visit that never opens it never asks
    // at all. The page is opened by everybody, mostly to read the regulation.
    const asked = useRef(false);
    const [loading, setLoading] = useState(false);

    const changeView = (next: View) => {
        setView(next);

        if (next === 'equipment' && equipment.can_read && !asked.current) {
            asked.current = true;
            setLoading(true);
            // Only that one prop, so the regulation, the people and the
            // profiles are not read again to answer a question about printers.
            router.reload({ only: ['equipmentUnits'], onFinish: () => setLoading(false) });
        }

        // Only the two the page has always remembered are written down; СФД and
        // the equipment are asked for afresh each visit, because nobody has
        // asked for a page that opens on either.
        if (next === 'sfd' || next === 'equipment') return;

        try {
            localStorage.setItem(VIEW_KEY, next);
        } catch {
            // Blocked storage: the choice just is not remembered.
        }
    };

    // Which document the main column holds. On a phone the list is a view of its
    // own and the column is hidden under it; on a desktop the list has a column
    // of its own, so «Список» there simply means the document last open.
    const open: Document = view === 'list' ? 'regulation' : view;

    const personHref = usePersonHref();
    // The structure of the company is open to everybody; the list of the staff is
    // not, and narrowing it by department takes reading that line of a card. A link
    // into a refusal is worse than none.
    const canOpenList = department.total_count > 0 && can('employees.view') && can('employees.field.departments');

    // This is the one page besides the directory that says both names: it is
    // where somebody lands to find out what an abbreviation elsewhere meant.
    const title = departmentTitle(department.full_name, department.name);

    // The trail is now the only thing on the page that says where the department
    // sits, so it says the whole way down to it rather than the last step alone.
    // Abbreviations, because a trail is read across rather than down, and the
    // heading right below spells this department's name out.
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Структура компании', href: '/departments' },
        ...department.parents.map((parent) => ({ title: parent.name, href: route('departments.show', parent.id) })),
        { title: department.name, href: route('departments.show', department.id) },
    ];

    return (
        // The page scrolls: the document is as long as the department wrote it
        // and the blocks beside it are as long as the staff is.
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />

            <div className="flex flex-1 flex-col gap-4 p-3 max-md:gap-3 md:px-5 md:py-4">
                {/* A phone: the top bar already names the department, so the head of
                    the page is a grouped card of facts, the way into the staff list is
                    a row of its own and the two views share one segmented control. */}
                <div className="flex flex-col gap-3 md:hidden">
                    <h1 className="sr-only">{title}</h1>
                    <PhoneSection title="Подразделение">
                        <dl className="px-4">
                            {/* The bar above says «ОД» and so does every screen that
                                sent somebody here; a finger has no hover, so this is
                                where a phone says what those two letters stand for. */}
                            {department.full_name !== department.name && (
                                <div className="border-border/60 flex items-baseline justify-between gap-4 border-b py-3 last:border-0">
                                    <dt className="text-muted-foreground shrink-0 text-[15px]">Название</dt>
                                    <dd className="min-w-0 text-right text-[15px]">{department.full_name}</dd>
                                </div>
                            )}
                            {department.parents.length > 0 && (
                                <div className="border-border/60 flex items-baseline justify-between gap-4 border-b py-3 last:border-0">
                                    <dt className="text-muted-foreground shrink-0 text-[15px]">Входит в</dt>
                                    <dd className="min-w-0 text-right text-[15px]">
                                        <nav aria-label="Входит в" className="flex flex-wrap items-center justify-end gap-x-1">
                                            {department.parents.map((parent, index) => (
                                                <span key={parent.id} className="flex items-center gap-1">
                                                    {index > 0 && <ChevronRight className="text-muted-foreground size-3.5" aria-hidden="true" />}
                                                    <Link
                                                        href={route('departments.show', parent.id)}
                                                        className="text-brand-strong break-words dark:text-[#C5E27A]"
                                                    >
                                                        <DepartmentName name={parent.name} full={parent.full_name} />
                                                    </Link>
                                                </span>
                                            ))}
                                        </nav>
                                    </dd>
                                </div>
                            )}
                            <div className="border-border/60 flex items-baseline justify-between gap-4 border-b py-3 last:border-0">
                                <dt className="text-muted-foreground shrink-0 text-[15px]">Сотрудников</dt>
                                <dd className="flex flex-col items-end text-right text-[15px]">
                                    {peopleLabel(department.total_count)}
                                    {department.children.length > 0 && (
                                        <span className="text-muted-foreground text-[13px]">вместе с подотделами</span>
                                    )}
                                </dd>
                            </div>
                        </dl>
                    </PhoneSection>

                    {canOpenList && (
                        <Card className="gap-0 overflow-hidden rounded-2xl border-0 py-0 shadow-none">
                            <Link
                                href={route('employees.index', { department: [department.id] })}
                                className="active:bg-accent/60 flex min-h-12 items-center gap-3 px-4 py-2.5 text-[15px] font-medium"
                            >
                                <List className="text-brand-strong size-5 shrink-0 dark:text-[#C5E27A]" aria-hidden="true" />
                                <span className="text-brand-strong flex-1 dark:text-[#C5E27A]">Открыть в списке сотрудников</span>
                                <ChevronRight className="text-muted-foreground/60 size-4 shrink-0" aria-hidden="true" />
                            </Link>
                        </Card>
                    )}

                    {/* Four peers on one screen, rather than three with the
                        fourth hidden inside one of them: who works here, the
                        положение, СФД and what the department has are four
                        reasons to open a department, and each is one press
                        away. «Техника» rather than «Оборудование» in this one
                        place: four segments share 390px between them, and the
                        longer word would cut all four short to fit itself. */}
                    <PhoneViewSwitch
                        value={view}
                        onChange={changeView}
                        options={[
                            { value: 'list', label: 'Список' },
                            { value: 'regulation', label: 'Положение' },
                            { value: 'sfd', label: 'СФД' },
                            { value: 'equipment', label: 'Техника' },
                        ]}
                    />
                </div>

                {/* The name, and nothing around it. Where the department sits is
                    the trail in the bar above, and who works here is the column
                    beside the document. */}
                <h1 className="text-xl font-semibold tracking-tight break-words max-md:hidden">{title}</h1>

                {/* The department's documents are what the page shows, and who
                    works in the department stands beside them rather than in place
                    of them: the tabs are inside the left column, so changing one
                    leaves the column at the right exactly as it was. Both columns
                    are told they may be narrower than their contents: a grid track
                    otherwise stands as wide as the longest line in it, and a job
                    title would push a phone screen sideways. */}
                <div className="grid grid-cols-1 items-start gap-4 lg:grid-cols-[1fr_24rem]">
                    <div
                        className={cn(
                            'flex min-w-0 flex-col gap-3',
                            // On a phone a document is one half of the switch: a
                            // block of a dozen clauses and the staff list are two
                            // different reasons to be here, and neither is worth
                            // scrolling past the other.
                            view === 'list' && 'max-md:hidden',
                        )}
                    >
                        <DocumentTabs current={open} onChange={changeView} />

                        <Card
                            role="tabpanel"
                            id={`department-panel-${open}`}
                            aria-labelledby={`department-tab-${open}`}
                            className="min-w-0 rounded-xl max-md:rounded-2xl max-md:border-0 max-md:shadow-none"
                        >
                            {open === 'regulation' && <DepartmentRegulation department={department.id} regulation={regulation} />}
                            {open === 'sfd' && <Profiles profiles={profiles} />}
                            {open === 'equipment' &&
                                (equipment.can_read ? (
                                    <DepartmentEquipment units={equipmentUnits} loading={loading} />
                                ) : (
                                    // Said of the viewer and not of the
                                    // department: the fleet is a section with
                                    // rights of its own, and «ничего нет» here
                                    // would send somebody looking for equipment
                                    // that is standing right in front of them.
                                    <p className="text-muted-foreground px-4 py-4 text-sm md:px-6 md:py-5">
                                        Оборудование доступно не всем — у вас нет доступа к нему.
                                    </p>
                                ))}
                        </Card>
                    </div>

                    {/* The column is narrow, so a colleague's address goes under
                        their name rather than at the right of their row. */}
                    <aside className="flex min-w-0 flex-col gap-4 max-md:hidden">
                        <Section title={department.heads.length > 1 ? 'Руководители' : 'Руководитель'}>
                            {department.heads.length === 0 ? (
                                <p className="text-muted-foreground text-sm">Не назначен</p>
                            ) : (
                                <ul className="flex flex-col">
                                    {department.heads.map((head) => (
                                        <ColleagueRow key={head.id} person={head} compact />
                                    ))}
                                </ul>
                            )}
                        </Section>

                        {department.children.length > 0 && (
                            <Section title="Подотделы" count={department.children.length}>
                                <ul className="-mx-2 flex flex-col">
                                    {department.children.map((child) => (
                                        <li key={child.id}>
                                            <Link
                                                href={route('departments.show', child.id)}
                                                className="hover:bg-muted/60 flex items-start gap-3 rounded-md px-2 py-2"
                                            >
                                                <span className="flex min-w-0 flex-1 flex-col gap-0.5">
                                                    <DepartmentName name={child.name} full={child.full_name} className="text-sm font-medium" />
                                                    {child.heads.length > 0 && (
                                                        <span className="text-muted-foreground truncate text-xs">
                                                            {child.heads.map((head) => head.name).join(', ')}
                                                        </span>
                                                    )}
                                                </span>
                                                <span
                                                    className="text-muted-foreground flex shrink-0 items-center gap-1 pt-0.5 text-[13px] tabular-nums"
                                                    title={peopleLabel(child.total_count)}
                                                >
                                                    <Users className="size-3.5" />
                                                    {child.total_count}
                                                </span>
                                            </Link>
                                        </li>
                                    ))}
                                </ul>
                            </Section>
                        )}

                        <Section title="Сотрудники" count={department.members.length}>
                            {department.members.length === 0 ? (
                                <p className="text-muted-foreground text-sm">
                                    {department.children.length > 0 ? 'Все сотрудники работают в подотделах.' : 'В отделе пока нет сотрудников.'}
                                </p>
                            ) : (
                                <ul className="flex flex-col">
                                    {department.members.map((member) => (
                                        <ColleagueRow key={member.id} person={member} compact />
                                    ))}
                                </ul>
                            )}
                        </Section>
                    </aside>

                    {/* A phone has no sidebar: there the three blocks stay the near
                        half of the switch, grouped the way a phone groups settings. */}
                    {view === 'list' && (
                        <div className="flex min-w-0 flex-col gap-3 md:hidden">
                            <PhoneSection title={department.heads.length > 1 ? 'Руководители' : 'Руководитель'}>
                                {department.heads.length === 0 ? (
                                    <PhoneEmpty>Не назначен</PhoneEmpty>
                                ) : (
                                    <ul className="divide-border/70 divide-y">
                                        {department.heads.map((head) => (
                                            <PhoneColleagueRow key={head.id} person={head} href={personHref(head.id)} />
                                        ))}
                                    </ul>
                                )}
                            </PhoneSection>

                            {department.children.length > 0 && (
                                <PhoneSection title="Подотделы" count={department.children.length}>
                                    <ul className="divide-border/70 divide-y">
                                        {department.children.map((child) => (
                                            <MobileRow
                                                key={child.id}
                                                href={route('departments.show', child.id)}
                                                leading={
                                                    <span className="bg-muted text-muted-foreground flex size-10 items-center justify-center rounded-[10px]">
                                                        <CornerDownRight className="size-4" aria-hidden="true" />
                                                    </span>
                                                }
                                                title={<DepartmentName name={child.name} full={child.full_name} className="whitespace-normal" />}
                                                subtitle={
                                                    child.heads.length > 0
                                                        ? child.heads.map((head) => head.name).join(', ')
                                                        : 'Руководитель не назначен'
                                                }
                                                meta={
                                                    <span
                                                        className="flex items-center gap-1 text-[13px] tabular-nums"
                                                        aria-label={peopleLabel(child.total_count)}
                                                        title={peopleLabel(child.total_count)}
                                                    >
                                                        <Users className="size-3.5" aria-hidden="true" />
                                                        {child.total_count}
                                                    </span>
                                                }
                                            />
                                        ))}
                                    </ul>
                                </PhoneSection>
                            )}

                            <PhoneSection title="Сотрудники" count={department.members.length}>
                                {department.members.length === 0 ? (
                                    <PhoneEmpty>
                                        {department.children.length > 0 ? 'Все сотрудники работают в подотделах.' : 'В отделе пока нет сотрудников.'}
                                    </PhoneEmpty>
                                ) : (
                                    <ul className="divide-border/70 divide-y">
                                        {department.members.map((member) => (
                                            <PhoneColleagueRow key={member.id} person={member} href={personHref(member.id)} />
                                        ))}
                                    </ul>
                                )}
                            </PhoneSection>
                        </div>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
