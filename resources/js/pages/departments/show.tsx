import { CardSection } from '@/components/card-section';
import { ComingSoon } from '@/components/coming-soon';
import { MobileRow } from '@/components/data-table';
import { DepartmentEditor, type DepartmentRecord } from '@/components/department-editor';
import { DepartmentName, departmentTitle } from '@/components/department-name';
import { DepartmentOverview } from '@/components/department-overview';
import { DepartmentProfile, type Profile } from '@/components/department-profile';
import { DepartmentRegulation, type Regulation } from '@/components/department-regulation';
import { DocumentTabs } from '@/components/document-tabs';
import { EditPencil } from '@/components/edit-pencil';
import { CategoryChip } from '@/components/equipment-icon';
import { PhoneViewSwitch } from '@/components/org-chart';
import { PersonFace } from '@/components/person-face';
import { PersonLink } from '@/components/person-link';
import { PositionName, positionTitle, type PositionUnit } from '@/components/position-name';
import { PositionSheet } from '@/components/position-sheet';
import { StatusBadge, statusTones } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { companySections, useCan } from '@/lib/access';
import { peopleLabel } from '@/lib/employee';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, CornerDownRight, List, Mail, Users } from 'lucide-react';
import { useEffect, useRef, useState, type ReactNode } from 'react';

interface Person {
    id: number;
    /** "Фамилия Имя" */
    name: string;
    avatar: string | null;
    /** The upload behind the thumbnail, which is what pressing the face opens. */
    avatar_original: string | null;
}

interface Colleague extends Person {
    email: string;
    /** The id travels only for somebody who may open the position's page. */
    positions: { id: number | null; name: string; unit: PositionUnit | null }[];
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
    /** The jobs of this department and of every unit under it, each named with its own. */
    positions: { id: number; name: string; unit: PositionUnit | null; users_count: number }[];
    heads: Colleague[];
    children: { id: number; name: string; full_name: string; total_count: number; heads: Person[] }[];
    /** Everybody working here and in the units under it, whoever leads one first. */
    members: (Colleague & { unit: string | null; is_head: boolean })[];
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
/** The department before or after this one, or nothing at either end of the list. */
type Neighbour = { id: number; name: string } | null;

/**
 * Walking the structure without going back to the list: the department before
 * and after this one, in the order «Подразделения» writes them down. The ← and
 * → keys do the same, as they do on a colleague's card.
 *
 * The pair is the desktop's: on a phone the two documents are a switch of their
 * own and the bar at the top is the way back, so there is no line to put these
 * on.
 */
function Neighbours({ prev, next }: { prev: Neighbour; next: Neighbour }) {
    const href = (to: Neighbour) => route('departments.show', to!.id);

    useEffect(() => {
        const onKeyDown = (event: KeyboardEvent) => {
            if (event.ctrlKey || event.altKey || event.metaKey || event.shiftKey || event.defaultPrevented) return;
            const target = event.target as HTMLElement;
            if (target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT', 'BUTTON'].includes(target.tagName)) return;
            // An open window — the editor, a menu — owns the arrows: leaving for
            // the next department from inside one would throw away what was typed.
            if (document.querySelector('[role="dialog"], [role="alertdialog"], [role="menu"], [role="listbox"]')) return;
            const to = event.key === 'ArrowLeft' ? prev : event.key === 'ArrowRight' ? next : null;
            if (to) router.visit(href(to));
        };
        window.addEventListener('keydown', onKeyDown);

        return () => window.removeEventListener('keydown', onKeyDown);
    });

    const arrow = (to: Neighbour, label: string, Icon: typeof ChevronLeft) => {
        // The arrow leads the label going back and trails it going forward.
        const back = Icon === ChevronLeft;

        return (
            <Button
                variant="outline"
                // The same height as the tabs they share the line with.
                className="h-8 shrink-0"
                disabled={!to}
                aria-label={to ? `${label}: ${to.name}` : label}
                title={to?.name}
                asChild={!!to}
            >
                {to ? (
                    <Link href={href(to)} prefetch>
                        {back && <Icon />}
                        {label}
                        {!back && <Icon />}
                    </Link>
                ) : (
                    <>
                        {back && <Icon />}
                        {label}
                        {!back && <Icon />}
                    </>
                )}
            </Button>
        );
    };

    return (
        <div className="ml-auto flex shrink-0 gap-1">
            {arrow(prev, 'Предыдущее', ChevronLeft)}
            {arrow(next, 'Следующее', ChevronRight)}
        </div>
    );
}

type View = 'list' | 'overview' | 'regulation' | 'sfd' | 'staff' | 'equipment';

/** What the main column can hold, which is everything but the phone's list. */
type Document = Exclude<View, 'list'>;

/**
 * The strip over the page. Neither the overview, the staff nor the equipment is
 * a document, but all three are read the same way and from the same place —
 * what this department is — so they are tabs beside the two papers rather than
 * pages of their own that the structure would have to link to.
 *
 * «Обзор» opens the page: it is what a department is looked up for most often,
 * and the papers are read rather than looked up.
 */
const DOCUMENTS: { key: Document; title: string }[] = [
    { key: 'overview', title: 'Обзор' },
    { key: 'regulation', title: 'Положение' },
    { key: 'sfd', title: 'СФД' },
    { key: 'staff', title: 'Штат' },
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
 * «Профиль подразделения» — «СФД», the department's own nine-section sheet,
 * kept and written here the way a position's is kept on its own page.
 *
 * It arrives after the page does, so the tab can be opened before it is here;
 * until then it says so rather than drawing an empty document, which would read
 * as a sheet that failed instead of one still on its way.
 */
function DepartmentSheet({ profile, department }: { profile?: Profile; department: number }) {
    if (!profile) return <p className="text-muted-foreground px-4 py-4 text-sm md:px-6 md:py-5">Профиль загружается…</p>;

    return <DepartmentProfile department={department} profile={profile} />;
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

/**
 * The pencil that opens «Изменить отдел», wherever the page puts it.
 *
 * The same button the document beside it is written with, because it is the
 * same act: the page carries both at once, and a second kind of edit button
 * would read as a second kind of editing. Wordless, so it says out loud what it
 * opens — «Изменить» alone would be two different things on one screen.
 */
function DepartmentPencil({ busy, onClick, className }: { busy: boolean; onClick: () => void; className?: string }) {
    return <EditPencil label="Изменить подразделение" tip="Изменить подразделение" busy={busy} className={className} onClick={onClick} />;
}

function Avatar({ person, className }: { person: Person; className?: string }) {
    return <PersonFace id={person.id} name={person.name} avatar={person.avatar} original={person.avatar_original} className={className} />;
}

/** One colleague: avatar, name linking to the profile, positions and email; `compact` fits a narrow column. */
function ColleagueRow({
    person,
    compact,
    withEmail = true,
    unit,
}: {
    person: Colleague;
    compact?: boolean;
    /** The address, for the blocks that are read in order to write to somebody. */
    withEmail?: boolean;
    /** Which unit under this department the person works in, where that is not this one. */
    unit?: string | null;
}) {
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
        <li className="flex items-center gap-3 py-2 first:pt-0 last:pb-0">
            {/* The face opens the photograph it is; the name beside it opens
                the card. */}
            <Avatar person={person} />
            <div className="flex min-w-0 flex-1 flex-col gap-1">
                <span className="flex min-w-0 flex-wrap items-baseline gap-x-1.5">
                    <PersonLink id={person.id} className="text-sm font-medium hover:underline">
                        {person.name}
                    </PersonLink>
                    {/* Where somebody holds no job at all, their unit is said here
                        instead; everybody else carries it in the job's own brackets. */}
                    {unit && person.positions.length === 0 && <span className="text-muted-foreground text-xs">{unit}</span>}
                </span>
                {compact && withEmail && email}
                {person.positions.length > 0 && (
                    <div className="flex flex-wrap gap-1">
                        {person.positions.map((position) => {
                            const badge = (
                                <StatusBadge tone="success" className="h-auto min-h-[22px] whitespace-normal">
                                    <PositionName name={position.name} unit={position.unit} />
                                </StatusBadge>
                            );

                            return position.id === null ? (
                                <span key={position.name}>{badge}</span>
                            ) : (
                                <Link
                                    key={position.id}
                                    href={route('positions.show', position.id)}
                                    title={`Открыть должность: ${positionTitle(position)}`}
                                    // The same answer to the cursor the list of jobs above gives:
                                    // the plate darkens a shade. An underline under a badge
                                    // reads as a line under a chip rather than a link.
                                    className="rounded-md transition-[filter] hover:brightness-95 dark:hover:brightness-125"
                                >
                                    {badge}
                                </Link>
                            );
                        })}
                    </div>
                )}
            </div>
            {!compact && withEmail && email}
        </li>
    );
}

/** A small heading over a grouped card, the way a phone's settings name their sections. */
function PhoneSection({ title, count, action, children }: { title: string; count?: number; action?: ReactNode; children: ReactNode }) {
    return (
        <section className="flex flex-col">
            {/* Above the card, where a phone keeps the name of a group — and
                where anything that acts on the group goes with it: inside the
                card the pencil would stand over the first row and whatever that
                row says. The negative margin keeps a finger-wide button from
                pushing the card a line further down. */}
            <div className="mb-1.5 flex items-center gap-1.5 px-1">
                <h2 className="text-muted-foreground flex min-w-0 flex-1 items-baseline gap-1.5 text-[13px] font-semibold tracking-wide uppercase">
                    {title}
                    {count !== undefined && <span className="font-normal tabular-nums">{count}</span>}
                </h2>
                {action && <span className="-my-2 -mr-1.5">{action}</span>}
            </div>
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
            subtitle={person.positions.length > 0 ? person.positions.map((position) => position.name).join(', ') : person.email}
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

/**
 * What «Изменить отдел» is given when it is opened: this department as the
 * window reads a record, and the departments it could be put under. Nobody is
 * chosen in that window — who belongs to a department and who leads it both
 * follow from its positions — so no list of people is sent.
 */
interface EditorOptions {
    department: DepartmentRecord;
    departments: DepartmentRecord[];
}

export default function DepartmentPage({
    department,
    regulation,
    profile,
    equipment,
    equipmentUnits,
    editor,
    editorOptions,
    neighbours,
}: {
    department: Department;
    regulation: Regulation;
    /** «Профиль подразделения»: the sheet this department is described by. */
    profile?: Profile;
    /** Whether this viewer may read equipment at all; the list is fetched apart. */
    equipment: { can_read: boolean };
    /** Absent until the tab is opened, which is when the page asks for it. */
    equipmentUnits?: Equipment;
    /** Whether the department is this person's to change, and nothing more. */
    editor: { can_edit: boolean };
    /** Absent until the window is opened, which is when the page asks for it. */
    editorOptions?: EditorOptions;
    /** The departments either side of this one in the list, for the pair of buttons. */
    neighbours: { prev: Neighbour; next: Neighbour };
}) {
    const can = useCan();
    const [view, setView] = useState<View>(savedView);
    // Whether the list has been asked for on this visit, so that opening the
    // tab twice does not ask twice, and a visit that never opens it never asks
    // at all. The page is opened by everybody, mostly to read the regulation.
    const asked = useRef(false);
    const [loading, setLoading] = useState(false);
    const [editing, setEditing] = useState(false);
    // The window waits for the record it edits, so the control says it is
    // working rather than answering a press with nothing.
    const [opening, setOpening] = useState(false);

    const askUnits = () => {
        asked.current = true;
        setLoading(true);
        // Only that one prop, so the regulation, the people and the
        // profiles are not read again to answer a question about printers.
        router.reload({ only: ['equipmentUnits'], onFinish: () => setLoading(false) });
    };

    const changeView = (next: View) => {
        setView(next);

        if (next === 'equipment' && equipment.can_read && !asked.current) askUnits();

        // Only the two the page has always remembered are written down; СФД,
        // the staff and the equipment are asked for afresh each visit, because
        // nobody has asked for a page that opens on one of them.
        if (next === 'sfd' || next === 'staff' || next === 'equipment') return;

        try {
            localStorage.setItem(VIEW_KEY, next);
        } catch {
            // Blocked storage: the choice just is not remembered.
        }
    };

    /**
     * «Изменить отдел»: the window «Подразделения» opens, opened from here.
     *
     * What it chooses from is not on the page — it is asked for by name, the
     * way the equipment is — so the first press waits for the answer and the
     * window opens on it. A second press has it already.
     */
    const openEditor = () => {
        if (editorOptions) {
            setEditing(true);

            return;
        }

        setOpening(true);
        router.reload({ only: ['editorOptions'], onSuccess: () => setEditing(true), onFinish: () => setOpening(false) });
    };

    /**
     * Saved: the page is already redrawn from the answer, so the heads and the
     * staff beside the document are the new ones.
     *
     * An answer to a save carries no optional prop, though, and the equipment
     * was one: a tab left standing open would read as a list that failed to
     * load, so it asks again.
     */
    const saved = () => {
        asked.current = false;

        if (view === 'equipment' && equipment.can_read) askUnits();
    };

    // Which tab the main column holds. On a phone the list is a view of its own
    // and the column is hidden under it; on a desktop the list has a column of
    // its own, so «Список» there means the overview, which is where a desktop
    // opens a department unless the last visit left the положение open.
    const open: Document = view === 'list' ? 'overview' : view;

    const personHref = usePersonHref();
    // The people of this department itself and not the ones who lead it, which
    // is the list a phone shows: there one screen is one department, the units
    // have screens of their own and «Руководитель» is a block above this one.
    const ownMembers = department.members.filter((member) => member.unit === null && !member.is_head);
    // The structure of the company is open to everybody; the list of the staff is
    // not, and narrowing it by department takes reading that line of a card. A link
    // into a refusal is worse than none.
    const canOpenList = department.total_count > 0 && can('employees.view') && can('employees.field.departments');

    // This is the one page besides the directory that says both names: it is
    // where somebody lands to find out what an abbreviation elsewhere meant.
    const title = departmentTitle(department.full_name, department.name);

    // The trail says the whole way down to the department rather than the last
    // step alone: the section it belongs to, the list it is a row of, and then
    // every department above this one. Abbreviations, because a trail is read
    // across rather than down, and the heading right below spells this
    // department's name out.
    // The list of departments and not the chart in the second step: this page is
    // one of that list's rows, and the way back from a department is to the list
    // it was opened from.
    const units = companySections.find((section) => section.key === 'units');

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Структура компании', href: companySections[0].href },
        { title: units?.title ?? 'Подразделении', href: units?.href ?? '/departments' },
        ...department.parents.map((parent) => ({ title: parent.name, href: route('departments.show', parent.id) })),
        { title: department.name, href: route('departments.show', department.id) },
    ];

    return (
        // The page scrolls: the document is as long as the department wrote it
        // and the blocks beside it are as long as the staff is.
        // The bar says the trail and nothing else; the initials it ends on are
        // what the rest of the system knows this department by, and «Название»
        // in the card below spells them out.
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />

            <div className="flex flex-1 flex-col gap-4 p-3 max-md:gap-3 md:gap-6 md:px-4 md:py-6 lg:px-6">
                {/* A phone: the top bar already names the department, so the head of
                    the page is a grouped card of facts, the way into the staff list is
                    a row of its own and the two views share one segmented control. */}
                <div className="flex flex-col gap-3 md:hidden">
                    <PhoneSection title="Подразделение" action={editor.can_edit && <DepartmentPencil busy={opening} onClick={openEditor} />}>
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

                {/* The department's documents are what the page shows, and who
                    works in the department stands beside them rather than in place
                    of them. Both columns are told they may be narrower than their
                    contents: a grid track otherwise stands as wide as the longest
                    line in it, and a job title would push a phone screen sideways.

                    The strip of tabs is a row of the grid in its own right, above
                    both columns, so that the document and the column beside it
                    begin at the same line. Inside the left column it stood above
                    the document only, and the column at the right started a tab
                    strip higher than the thing it is beside. A row costs nothing
                    to a longer strip: one that wraps to two lines pushes both
                    columns down together, which a margin measured off today's
                    strip would not. */}
                {/* The page scrolls as a whole, head and tabs included, the way a
                    colleague's card does: one record, read from the top down. */}
                <div className="grid grid-cols-1 gap-4 lg:grid-cols-[1fr_24rem]">
                    {/* The page writes its own name, both ways at once: the bar
                        above says the way down to this department and ends on
                        the initials, and this is where they are spelled out.
                        The phone has it in the bar instead — one line there is
                        all there is room for. */}
                    <h1 className="text-xl leading-7 font-semibold tracking-[-0.01em] max-md:hidden lg:col-span-2">{title}</h1>

                    {/* The documents on the left, the way through the structure on the right. */}
                    <div className="flex items-center gap-3 max-md:hidden lg:col-span-2">
                        <DocumentTabs
                            tabs={DOCUMENTS}
                            current={open}
                            onChange={changeView}
                            label="Документы подразделения"
                            name="department"
                            className="min-w-0 flex-1"
                        />

                        <Neighbours prev={neighbours.prev} next={neighbours.next} />
                    </div>

                    <div
                        className={cn(
                            'flex min-w-0 flex-col',
                            // On a phone a document is one half of the switch: a
                            // block of a dozen clauses and the staff list are two
                            // different reasons to be here, and neither is worth
                            // scrolling past the other.
                            view === 'list' && 'max-md:hidden',
                            // The overview carries a column of its own at the
                            // right, so it takes the width of both.
                            open === 'overview' && 'lg:col-span-2',
                        )}
                    >
                        {open === 'overview' ? (
                            <div
                                role="tabpanel"
                                id="department-panel-overview"
                                aria-labelledby="department-tab-overview"
                                className="min-w-0 max-md:hidden"
                            >
                                <DepartmentOverview />
                            </div>
                        ) : (
                            <Card
                                role="tabpanel"
                                id={`department-panel-${open}`}
                                aria-labelledby={`department-tab-${open}`}
                                className="min-w-0 rounded-xl max-md:rounded-2xl max-md:border-0 max-md:shadow-none"
                            >
                                {open === 'regulation' && <DepartmentRegulation department={department.id} regulation={regulation} />}
                                {open === 'sfd' && (
                                    <>
                                        {/* The design draws this tab as the profile
                                        of a job — the department's jobs in a
                                        strip and the sheet of the one that is
                                        open — so that is what a desktop shows.
                                        A phone keeps what this tab has always
                                        held: the department's own sheet, which
                                        is a document somebody wrote and can
                                        still write. */}
                                        <div className="max-md:hidden">
                                            <PositionSheet positions={department.positions} />
                                        </div>
                                        <div className="md:hidden">
                                            <DepartmentSheet profile={profile} department={department.id} />
                                        </div>
                                    </>
                                )}
                                {/* The staff is the next drawing to be built; the tab
                                is here so the strip is the one the design has, and it
                                says so the way every unbuilt section does. */}
                                {open === 'staff' && <ComingSoon what="Штатное расписание" back={false} className="m-4 md:m-6" />}
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
                        )}
                    </div>

                    {/* The column is narrow, so a colleague's address goes under
                        their name rather than at the right of their row. The
                        overview has a column of its own, so this one stands down
                        while it is open. */}
                    <aside className={cn('flex min-w-0 flex-col gap-4 max-md:hidden', open === 'overview' && 'hidden')}>
                        {/* What the sheet used to open with. «01 ОБЩАЯ
                            ИНФОРМАЦИЯ» was where the unit sits, who leads it,
                            how many work there and what is under it — and three
                            of those are the cards below this one, so only the
                            first and the count had nowhere on a desktop to be
                            read. They are read here, in the block the phone has
                            had all along. */}
                        {/* One way in, and not one per card: the window writes
                            the name, the placement, the heads and the staff in
                            one go, so three buttons would be three ways to the
                            same form. It stands on the card the department
                            itself is read from — the one above the heads and
                            the staff it edits. */}
                        <CardSection
                            title="Подразделение"
                            action={
                                editor.can_edit && (
                                    <DepartmentPencil busy={opening} onClick={openEditor} className="-my-1 -mr-2.5 lg:my-0 lg:-mr-2 lg:size-7" />
                                )
                            }
                        >
                            <dl className="flex flex-col gap-2 text-sm">
                                {/* No «Название» row: the phone has one because
                                    its top bar says «ОД» and nothing else, while
                                    the heading of this page spells the name out
                                    above the tabs. Every fact once. */}
                                {department.parents.length > 0 && (
                                    <div className="flex items-baseline justify-between gap-3">
                                        <dt className="text-muted-foreground shrink-0">Входит в</dt>
                                        <dd className="min-w-0 text-right">
                                            <nav aria-label="Входит в" className="flex flex-wrap items-center justify-end gap-x-1">
                                                {department.parents.map((parent, index) => (
                                                    <span key={parent.id} className="flex items-center gap-1">
                                                        {index > 0 && <ChevronRight className="text-muted-foreground size-3.5" aria-hidden="true" />}
                                                        <Link
                                                            href={route('departments.show', parent.id)}
                                                            className="text-brand-strong break-words hover:underline dark:text-[#C5E27A]"
                                                        >
                                                            <DepartmentName name={parent.name} full={parent.full_name} />
                                                        </Link>
                                                    </span>
                                                ))}
                                            </nav>
                                        </dd>
                                    </div>
                                )}
                                {/* Both counts are read over the branch: the department
                                    and everything filed under it. A number and not a
                                    link — «Должности» does not read a department out of
                                    the address, so a press would open the list whole. */}
                                <div className="flex items-baseline justify-between gap-3">
                                    <dt className="text-muted-foreground shrink-0">Должности</dt>
                                    <dd className="min-w-0 text-right tabular-nums">{department.positions.length}</dd>
                                </div>
                                <div className="flex items-baseline justify-between gap-3">
                                    <dt className="text-muted-foreground shrink-0">Сотрудники</dt>
                                    <dd className="min-w-0 text-right tabular-nums">{department.total_count}</dd>
                                </div>
                            </dl>
                        </CardSection>

                        {department.positions.length > 0 && (
                            <CardSection title="Должности" count={department.positions.length}>
                                {/*
                                 * Badges rather than rows: a job is drawn this way
                                 * everywhere else it is named — on a colleague's card,
                                 * in the list of the staff — and in the same brand
                                 * green. One to a line, because a title with its
                                 * department in brackets is a long thing to wrap, and
                                 * because the number of people holding it stands at the
                                 * end of that line — drawn as the units under this one
                                 * are drawn, a head count at the right-hand edge.
                                 */}
                                <ul className="flex flex-col gap-1.5">
                                    {department.positions.map((position) => (
                                        <li key={position.id} className="flex items-center gap-2">
                                            <Link
                                                href={route('positions.show', position.id)}
                                                title={peopleLabel(position.users_count)}
                                                // Laid out as a line of text rather than a row of
                                                // boxes, so a long title wraps inside the badge.
                                                className={cn(
                                                    'inline-block max-w-full rounded-md px-2 py-0.5 text-xs font-medium transition-[filter]',
                                                    statusTones.success,
                                                    'hover:brightness-95 dark:hover:brightness-125',
                                                )}
                                            >
                                                <PositionName name={position.name} unit={position.unit} />
                                            </Link>

                                            {/* Nobody holds it yet: said in the quieter colour the
                                                rest of the page says an empty count in. */}
                                            <span
                                                className={cn(
                                                    'ml-auto flex shrink-0 items-center gap-1 text-[13px] tabular-nums',
                                                    position.users_count > 0 ? 'text-muted-foreground' : 'text-muted-foreground/60',
                                                )}
                                                title={peopleLabel(position.users_count)}
                                            >
                                                <Users className="size-3.5" />
                                                {position.users_count}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            </CardSection>
                        )}

                        {/* Everybody who works here, the units under this one included:
                            a face, a name and the jobs they hold. The address is left
                            out — this is a list to read the department by, and a dozen
                            e-mails under a dozen names is a wall of brand colour. */}
                        <CardSection title="Сотрудники" count={department.members.length}>
                            {department.members.length === 0 ? (
                                <p className="text-muted-foreground text-sm">В отделе пока нет сотрудников.</p>
                            ) : (
                                <ul className="flex flex-col">
                                    {department.members.map((member) => (
                                        <ColleagueRow key={member.id} person={member} compact withEmail={false} unit={member.unit} />
                                    ))}
                                </ul>
                            )}
                        </CardSection>

                        {department.children.length > 0 && (
                            <CardSection title="Подотделы" count={department.children.length}>
                                <ul className="-mx-2 flex flex-col">
                                    {department.children.map((child) => (
                                        <li key={child.id}>
                                            <Link
                                                href={route('departments.show', child.id)}
                                                className="hover:bg-muted/60 flex items-start gap-3 rounded-md px-2 py-2"
                                            >
                                                <span className="flex min-w-0 flex-1 flex-col gap-0.5">
                                                    {/* The initials the rest of the system knows the unit
                                                        by, and under them what they stand for: this
                                                        column is where an abbreviation is spelled out,
                                                        and a hover is no way to read a list. The name
                                                        is left out where it is the abbreviation itself. */}
                                                    <span className="text-sm font-medium">{child.name}</span>
                                                    {child.full_name !== child.name && (
                                                        <span className="text-muted-foreground text-xs">{child.full_name}</span>
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
                            </CardSection>
                        )}
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

                            <PhoneSection title="Сотрудники" count={ownMembers.length}>
                                {ownMembers.length === 0 ? (
                                    <PhoneEmpty>
                                        {department.children.length > 0 ? 'Все сотрудники работают в подотделах.' : 'В отделе пока нет сотрудников.'}
                                    </PhoneEmpty>
                                ) : (
                                    <ul className="divide-border/70 divide-y">
                                        {ownMembers.map((member) => (
                                            <PhoneColleagueRow key={member.id} person={member} href={personHref(member.id)} />
                                        ))}
                                    </ul>
                                )}
                            </PhoneSection>
                        </div>
                    )}
                </div>
            </div>

            {/* The same window «Подразделения» opens, saving through the same
                address, and this page re-implements none of it. */}
            {editing && editorOptions && (
                <DepartmentEditor
                    department={editorOptions.department}
                    departments={editorOptions.departments}
                    onSaved={saved}
                    onClose={() => setEditing(false)}
                />
            )}
        </AppLayout>
    );
}
