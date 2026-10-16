import { departmentTitle } from '@/components/department-name';
import { DepartmentRegulation } from '@/components/department-regulation';
import { DocumentTabs } from '@/components/document-tabs';
import { type EmploymentStatus } from '@/components/employee-actions';
import { PersonFace } from '@/components/person-face';
import { PersonLink } from '@/components/person-link';
import { PositionProfile, type Profile } from '@/components/position-profile';
import { StatusBadge, type StatusTone } from '@/components/status-badge';
import { Card } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { type DocumentSection } from '@/lib/document';
import { type Sex } from '@/lib/employee';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { Building2 } from 'lucide-react';
import { useState, type ReactNode } from 'react';

interface PositionRef {
    id: number;
    name: string;
    /** «Аналитик (ОПР)»: what tells one of two positions sharing a name from the other. */
    display_name: string;
}

/** A department as every screen but the directory names it: short, full within reach. */
interface DepartmentRef {
    id: number;
    name: string;
    full_name: string;
}

interface DutyRef {
    id: number;
    name: string;
}

interface Duty extends DutyRef {
    /** Which position the duty comes from — how this page tells its own from the rest. */
    position_id: number;
}

interface Position extends PositionRef {
    /** Where the position sits; null for the few nobody has filed yet. */
    department: DepartmentRef | null;
    /** What the position answers for, one list in the order it was written. */
    duties: DutyRef[];
}

interface Holder {
    id: number;
    /** "Фамилия Имя" */
    name: string;
    avatar: string | null;
    status: EmploymentStatus;
    /** Null until anybody filled that line of the card in. */
    sex: Sex | null;
    /** Every position the person holds, this one among them. */
    positions: PositionRef[];
    /** The duties picked out for them, from the lists of all those positions. */
    duties: Duty[];
}

/**
 * A duty can be a whole sentence — "Ведёт табель учёта рабочего времени" — so its
 * chip wraps onto a second line instead of running out of the row it sits in.
 */
const chip = 'h-auto min-h-[22px] max-w-full py-0.5 whitespace-normal';

/**
 * Somebody who has left still held the position, so the list says so, in the
 * words the card and the staff list use: the ending follows the person's own
 * gender, and a line nobody answered falls to the masculine as it does there.
 */
function leftLabel(status: EmploymentStatus, sex: Sex | null): string | null {
    if (status === 'active') return null;

    const female = sex === 'female';

    return status === 'fired' ? (female ? 'Уволена' : 'Уволен') : female ? 'Переведена' : 'Переведён';
}

/** The furniture of a department's page, so the two read as one kind of record. */
function Section({ title, count, children }: { title: string; count?: number; children: ReactNode }) {
    return (
        <Card className="flex flex-col gap-3 rounded-xl px-4 py-4 max-md:rounded-2xl md:px-6 md:py-5">
            <h2 className="flex items-baseline gap-2 text-base font-semibold">
                {title}
                {count !== undefined && <span className="text-muted-foreground text-sm font-normal tabular-nums">{count}</span>}
            </h2>
            {children}
        </Card>
    );
}

/**
 * Where the position sits, under its name, with both names at once: this page is
 * one of the two with room to say what an abbreviation stands for, and it leads
 * to the department's own page as every abbreviation on screen does.
 *
 * One that nobody has filed says so outright, in the colour of something to be
 * dealt with: the name alone is what the rest of the app has to tell two
 * positions apart by, so an unplaced one is unfinished rather than ordinary, and
 * whoever may keep the list is told where it is finished.
 */
function Placement({ department, canEdit }: { department: DepartmentRef | null; canEdit: boolean }) {
    if (department === null) {
        return (
            <div className="flex flex-wrap items-center gap-2 text-sm">
                <Building2 className="text-muted-foreground size-4" aria-hidden="true" />
                <StatusBadge tone="warning">Без отдела</StatusBadge>
                <span className="text-muted-foreground">
                    Должность не закреплена за отделом
                    {canEdit && <>. Укажите отдел в списке должностей</>}.
                </span>
            </div>
        );
    }

    return (
        <p className="text-muted-foreground flex items-center gap-1.5 text-sm">
            <Building2 className="size-4" aria-hidden="true" />
            <Link href={route('departments.show', department.id)} className="hover:text-foreground hover:underline">
                {departmentTitle(department.full_name, department.name)}
            </Link>
        </p>
    );
}

/** One duty a person carries, and — for one carried elsewhere — where from. */
function DutyChip({ duty, tone, position }: { duty: Duty; tone: StatusTone; position?: string }) {
    return (
        <StatusBadge tone={tone} className={cn(chip, tone === 'success' && 'font-semibold')} title={position}>
            <span className="min-w-0 break-words">{duty.name}</span>
        </StatusBadge>
    );
}

/**
 * What one person is responsible for.
 *
 * The duties of this position are what the page was opened for, so they come
 * first and in the pale brand green that marks a chosen value everywhere else:
 * among everything the person carries, the green is what says "this is what the
 * page is about". The others are responsibilities they carry elsewhere; they stay
 * plain and name, on hover, the position they come from.
 *
 * Carrying nothing of this position is not the same as the position asking for
 * nothing, and the two read differently: holding a position hands nobody its
 * whole list — six analysts share one list out between them — so an empty green
 * row means the sharing out has not reached this person yet, while a position
 * with no list at all has nothing to share.
 */
function Duties({ person, position }: { person: Holder; position: Position }) {
    const here = person.duties.filter((duty) => duty.position_id === position.id);
    const elsewhere = person.duties.filter((duty) => duty.position_id !== position.id);
    const positionNames = new Map(person.positions.map((held) => [held.id, held.display_name]));

    return (
        <div className="flex flex-col gap-1.5">
            {here.length > 0 ? (
                <div role="group" aria-label="Обязанности этой должности" className="flex flex-wrap gap-1">
                    {here.map((duty) => (
                        <DutyChip key={duty.id} duty={duty} tone="success" />
                    ))}
                </div>
            ) : (
                <p className="text-muted-foreground text-[13px]">
                    {position.duties.length > 0 ? 'Обязанности должности пока не закреплены' : 'У должности не указаны обязанности'}
                </p>
            )}
            {elsewhere.length > 0 && (
                <div className="flex flex-wrap items-baseline gap-x-2 gap-y-1">
                    <span className="text-muted-foreground text-[13px]">Другие обязанности:</span>
                    {elsewhere.map((duty) => (
                        <DutyChip key={duty.id} duty={duty} tone="neutral" position={positionNames.get(duty.position_id)} />
                    ))}
                </div>
            )}
        </div>
    );
}

/** One holder: the face, the name, and the duties they carry. */
function HolderRow({
    person,
    position,
    canOpenCards,
    showsPositions,
}: {
    person: Holder;
    position: Position;
    canOpenCards: boolean;
    showsPositions: boolean;
}) {
    const others = person.positions.filter((held) => held.id !== position.id);
    const left = leftLabel(person.status, person.sex);

    return (
        <li className="flex items-start gap-3 border-t py-3 first:border-t-0 first:pt-0 last:pb-0">
            <PersonFace id={person.id} name={person.name} avatar={person.avatar} />
            <div className="flex min-w-0 flex-1 flex-col gap-1.5">
                <div className="flex flex-wrap items-center gap-2">
                    {/* A name is not a secret; the card behind it is, and the server has
                        already decided whether this viewer may knock on one. */}
                    {canOpenCards ? (
                        <PersonLink id={person.id} className="text-sm font-medium hover:underline">
                            {person.name}
                        </PersonLink>
                    ) : (
                        <span className="text-sm font-medium">{person.name}</span>
                    )}
                    {left && (
                        <StatusBadge tone={person.status === 'fired' ? 'danger' : 'warning'} className={chip}>
                            {left}
                        </StatusBadge>
                    )}
                </div>

                {/* Whose positions somebody holds is that line of their card talking, and
                    without it nothing about them travelled — so the page keeps quiet
                    rather than drawing a row of empty lists. */}
                {showsPositions && (
                    <>
                        {others.length > 0 && (
                            <p className="text-muted-foreground flex flex-wrap items-baseline gap-x-1 text-[13px]">
                                <span>Другие должности:</span>
                                {others.map((held, index) => (
                                    <span key={held.id}>
                                        <Link
                                            href={route('positions.show', held.id)}
                                            className="text-brand-strong hover:underline dark:text-[#C5E27A]"
                                        >
                                            {held.display_name}
                                        </Link>
                                        {index < others.length - 1 && ','}
                                    </span>
                                ))}
                            </p>
                        )}
                        <Duties person={person} position={position} />
                    </>
                )}
            </div>
        </li>
    );
}

/**
 * The положение of the department this position is filed in, as this page needs
 * it: whose it is, so the tab can say so and link there, and the document
 * itself, which is the same payload the department's own page is given.
 *
 * No `can_edit`: it is read here and written there, and the page draws it
 * read-only itself rather than relying on a flag to arrive false.
 */
export interface PositionRegulation {
    department: { id: number; name: string; full_name: string };
    paper: { title: string; sections: DocumentSection[] };
}

/** What the main column of a position's page can hold. */
type Document = 'profile' | 'regulation';

const DOCUMENTS: { key: Document; title: string }[] = [
    { key: 'profile', title: 'СФД' },
    { key: 'regulation', title: 'Положение' },
];

/**
 * «Положение о работе» of the department this position is filed in.
 *
 * A position has no положение of its own, and nothing here invents one: that
 * document describes a подразделение, and a second copy per position would be
 * two texts that must agree and will not. So the tab reads the department's —
 * and says whose it is before the first line of it, with the way to the page
 * where it is kept, because a document one cannot change should say where one
 * can rather than simply offering no pencils.
 */
function DepartmentsRegulation({ regulation }: { regulation: PositionRegulation | null }) {
    // Nothing to read, which today has one cause and will keep having it: a
    // position filed in no department belongs to no подразделение, so there is
    // no document of one to show it.
    if (!regulation) {
        return (
            <p className="text-muted-foreground px-4 py-4 text-sm md:px-6 md:py-5">
                Должность не закреплена за отделом, поэтому читать нечего: положение о работе есть у подразделения, а не у должности.
            </p>
        );
    }

    const { department } = regulation;

    return (
        <div className="flex flex-col gap-4 px-4 py-4 md:px-6 md:py-5">
            <p className="text-muted-foreground max-w-[72ch] text-[13px] leading-relaxed">
                Это положение подразделения{' '}
                <Link href={route('departments.show', department.id)} className="text-brand-strong hover:underline dark:text-[#C5E27A]">
                    {departmentTitle(department.full_name, department.name)}
                </Link>
                . Здесь его можно только прочитать — изменяют его на странице подразделения.
            </p>

            {/* Read-only is said here as well as by the payload: the card hides
                every affordance behind this one flag, and this page must not
                offer a second place to write a text that is kept elsewhere. */}
            <DepartmentRegulation
                department={department.id}
                regulation={{ ...regulation.paper, can_edit: false, names: { nominative: department.full_name }, placeholders: [] }}
            />
        </div>
    );
}

export default function PositionPage({
    position,
    profile,
    regulation,
    employees,
    canEdit,
    canOpenCards,
    showsEmployees,
    showsPositions,
}: {
    position: Position;
    /** «Профиль должности»: the sheet this position is described by. */
    profile: Profile;
    /** The положение of the department it is filed in; null where it is filed nowhere. */
    regulation: PositionRegulation | null;
    employees: Holder[];
    /** Renaming the position, re-filing it and changing its duties stays on the directory page. */
    canEdit: boolean;
    canOpenCards: boolean;
    /**
     * Whether the list of colleagues is this viewer's to read. A closed one
     * arrives as no people rather than as a refusal, and without this the page
     * could not tell that from a position nobody holds.
     */
    showsEmployees: boolean;
    showsPositions: boolean;
}) {
    // «СФД» opens the page: the sheet is why somebody comes to a position at
    // all, and the department's положение is a step aside from it.
    const [open, setOpen] = useState<Document>('profile');

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Должности', href: route('positions.index') },
        // The crumb is the one line that has to stand on its own among the
        // crumbs of every other position, so it carries the department.
        { title: position.display_name, href: route('positions.show', position.id) },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={position.display_name} />

            <div className="flex flex-1 flex-col gap-4 p-3 max-md:gap-3 md:px-5 md:py-4">
                {/* The name, and nothing around it, as a department's page has it:
                    where the position sits and who holds it are in the column
                    beside the documents. A phone's top bar already names the
                    position, so there the heading is for screen readers. */}
                <h1 className="text-xl font-semibold tracking-tight break-words max-md:sr-only">{position.name}</h1>

                {/* The two documents are the page, and what is known about the
                    position stands beside them rather than in place of them. Both
                    columns are told they may be narrower than their contents: a
                    grid track otherwise stands as wide as the longest line in it,
                    and a job title would push a phone screen sideways. */}
                <div className="grid grid-cols-1 items-start gap-4 lg:grid-cols-[1fr_24rem]">
                    <div className="flex min-w-0 flex-col gap-3">
                        <DocumentTabs tabs={DOCUMENTS} current={open} onChange={setOpen} label="Документы должности" name="position" />

                        <Card
                            role="tabpanel"
                            id={`position-panel-${open}`}
                            aria-labelledby={`position-tab-${open}`}
                            className="min-w-0 rounded-xl max-md:rounded-2xl max-md:border-0 max-md:shadow-none"
                        >
                            {open === 'profile' ? (
                                <PositionProfile position={position.id} profile={profile} />
                            ) : (
                                <DepartmentsRegulation regulation={regulation} />
                            )}
                        </Card>
                    </div>

                    {/* It follows the document down: a sheet of nine sections is
                        long, and who holds the post is worth keeping in view while
                        one reads what the post is. Taller than the window, it
                        scrolls itself rather than losing its last card. */}
                    <aside className="flex min-w-0 flex-col gap-4 lg:sticky lg:top-4 lg:max-h-[calc(100svh-2rem)] lg:overflow-y-auto">
                        <Section title="Подразделение">
                            <Placement department={position.department} canEdit={canEdit} />
                        </Section>

                        <Section title="Сотрудники на должности" count={showsEmployees ? employees.length : undefined}>
                            {!showsEmployees ? (
                                <p className="text-muted-foreground text-sm">Список сотрудников закрыт.</p>
                            ) : employees.length === 0 ? (
                                <p className="text-muted-foreground text-sm">На этой должности пока нет сотрудников.</p>
                            ) : (
                                <ul className="flex flex-col">
                                    {employees.map((person) => (
                                        <HolderRow
                                            key={person.id}
                                            person={person}
                                            position={position}
                                            canOpenCards={canOpenCards}
                                            showsPositions={showsPositions}
                                        />
                                    ))}
                                </ul>
                            )}
                        </Section>
                    </aside>
                </div>
            </div>
        </AppLayout>
    );
}
