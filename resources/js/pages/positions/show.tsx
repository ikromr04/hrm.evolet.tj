import { departmentTitle } from '@/components/department-name';
import { type Regulation } from '@/components/department-regulation';
import { DocumentTabs } from '@/components/document-tabs';
import { EditPencil } from '@/components/edit-pencil';
import { type EmploymentStatus } from '@/components/employee-actions';
import { PersonFace } from '@/components/person-face';
import { PersonLink } from '@/components/person-link';
import { PositionEditor, type DepartmentOption } from '@/components/position-editor';
import { PositionName, type PositionUnit } from '@/components/position-name';
import { PositionProfile, type General, type Profile } from '@/components/position-profile';
import { PositionRegulation } from '@/components/position-regulation';
import { StatusBadge, type StatusTone } from '@/components/status-badge';
import { Card } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { type Sex } from '@/lib/employee';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { Building2, ChevronRight } from 'lucide-react';
import { useState, type ReactNode } from 'react';

interface PositionRef {
    id: number;
    name: string;
    /** «Аналитик (ОПР)»: what tells one of two positions sharing a name from the other. */
    display_name: string;
    /** The department it belongs to, named both ways; null for one nobody has filed. */
    unit?: PositionUnit | null;
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
    /** Whether whoever holds this heads the department it sits in. */
    is_head: boolean;
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
function Section({ title, count, action, children }: { title: string; count?: number; action?: ReactNode; children: ReactNode }) {
    const heading = (
        <>
            {title}
            {count !== undefined && <span className="text-muted-foreground text-sm font-normal tabular-nums">{count}</span>}
        </>
    );

    return (
        <Card className="flex flex-col gap-3 rounded-xl px-4 py-4 max-md:rounded-2xl md:gap-4 md:px-6 md:py-5">
            {/* On a desktop the name stands on a ruled band flush with the card's
                edges, the way every block of a colleague's card and a
                department's wears it. A phone keeps the name on a line of its
                own inside the card: there the cards are a column one scrolls
                through and a band on each would be a row of grey stripes. */}
            <div className="bg-muted/60 -mx-6 -mt-5 flex min-h-11 flex-wrap items-center justify-between gap-x-3 gap-y-1 rounded-t-xl border-b px-6 py-2 max-md:hidden">
                <h2 className="flex min-w-0 items-baseline gap-2 text-base font-semibold">{heading}</h2>
                {action}
            </div>

            <div className="flex min-h-6 items-end justify-between gap-3 md:hidden">
                <h2 className="flex items-baseline gap-2 text-base font-semibold">{heading}</h2>
                {action}
            </div>
            {children}
        </Card>
    );
}

/**
 * Where the position sits, under its name, with both names at once: this page is
 * one of the two with room to say what an abbreviation stands for, and it leads
 * to the department's own page as every abbreviation on screen does.
 *
 * Both steps of the structure, because the sheet names both. «01 ОБЩАЯ
 * ИНФОРМАЦИЯ» opened the profile with ОТДЕЛ and ДЕПАРТАМЕНТ and is not a
 * section of it any more; this is where those two are read, and a position
 * filed straight on a department has only the one step to show. The trail reads
 * the way the department's own page reads it, outermost first.
 *
 * One that nobody has filed says so outright, in the colour of something to be
 * dealt with: the name alone is what the rest of the app has to tell two
 * positions apart by, so an unplaced one is unfinished rather than ordinary, and
 * whoever may keep the list is told where it is finished.
 */
function Placement({ general, department, canEdit }: { general: General; department: DepartmentRef | null; canEdit: boolean }) {
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

    // Outermost first, and only the steps there are: a position filed on a
    // department that has nothing above it names that one department rather
    // than naming it twice.
    const trail = [general.department, general.unit].filter((step) => step !== null);

    return (
        <p className="text-muted-foreground flex flex-wrap items-center gap-x-1.5 gap-y-1 text-sm">
            <Building2 className="size-4 shrink-0" aria-hidden="true" />
            {trail.length === 0 ? (
                <Link href={route('departments.show', department.id)} className="hover:text-foreground hover:underline">
                    {departmentTitle(department.full_name, department.name)}
                </Link>
            ) : (
                trail.map((step, index) => (
                    <span key={step.id} className="flex items-center gap-1.5">
                        {index > 0 && <ChevronRight className="size-3.5 shrink-0" aria-hidden="true" />}
                        <Link href={route('departments.show', step.id)} className="hover:text-foreground hover:underline">
                            {departmentTitle(step.full_name, step.name)}
                        </Link>
                    </span>
                ))
            )}
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
                                            <PositionName name={held.name} unit={held.unit ?? null} />
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

/** What the main column of a position's page can hold. */
type Document = 'profile' | 'regulation';

// In the order a department reads them, so the two pages open the same way.
const DOCUMENTS: { key: Document; title: string }[] = [
    { key: 'regulation', title: 'Положение' },
    { key: 'profile', title: 'СФД' },
];
export default function PositionPage({
    position,
    profile,
    regulation,
    employees,
    canEdit,
    editorOptions,
    canOpenCards,
    showsEmployees,
    showsPositions,
}: {
    position: Position;
    /** «Профиль должности»: the sheet this position is described by. */
    profile: Profile;
    /** «Положение о работе» this position keeps, read and written here. */
    regulation: Regulation;
    employees: Holder[];
    /** Whether the position is this viewer's to change — what the pencil on «Обязанности» asks. */
    canEdit: boolean;
    /** Absent until the window is opened, which is when the page asks for it. */
    editorOptions?: { departments: DepartmentOption[]; duties: string[] };
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
    const [open, setOpen] = useState<Document>('regulation');

    // The duties are written in the window the list of positions opens — the
    // one place a position is named, filed and described at once. What it asks
    // with is fetched when the pencil is pressed rather than with every visit.
    const [editing, setEditing] = useState(false);
    const [opening, setOpening] = useState(false);

    const openEditor = () => {
        if (editorOptions) {
            setEditing(true);

            return;
        }

        setOpening(true);
        router.reload({ only: ['editorOptions'], onSuccess: () => setEditing(true), onFinish: () => setOpening(false) });
    };

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Должности', href: route('positions.index') },
        // The crumb is the one line that has to stand on its own among the
        // crumbs of every other position, so it carries the department.
        { title: position.display_name, href: route('positions.show', position.id) },
    ];

    return (
        // The bar carries the name on its own, as a department's page has it:
        // where the position sits and who holds it are in the column beside the
        // documents, and the trail above says the rest.
        <AppLayout breadcrumbs={breadcrumbs} title={position.name}>
            <Head title={position.display_name} />

            <div className="flex flex-1 flex-col gap-4 p-3 max-md:gap-3 md:p-4">
                {/* The two documents are the page, and what is known about the
                    position stands beside them rather than in place of them. Both
                    columns are told they may be narrower than their contents: a
                    grid track otherwise stands as wide as the longest line in it,
                    and a job title would push a phone screen sideways.

                    The strip of tabs is a row of the grid in its own right, above
                    both columns, the way a department's page has it: the document
                    and the column beside it begin at the same line, and a strip
                    that wraps to two lines moves both of them down together. */}
                <div className="grid grid-cols-1 items-start gap-4 lg:grid-cols-[1fr_24rem]">
                    <DocumentTabs
                        tabs={DOCUMENTS}
                        current={open}
                        onChange={setOpen}
                        label="Документы должности"
                        name="position"
                        className="lg:col-span-2"
                    />

                    <div className="flex min-w-0 flex-col">
                        <Card
                            role="tabpanel"
                            id={`position-panel-${open}`}
                            aria-labelledby={`position-tab-${open}`}
                            className="min-w-0 rounded-xl max-md:rounded-2xl max-md:border-0 max-md:shadow-none"
                        >
                            {open === 'profile' ? (
                                <PositionProfile position={position.id} profile={profile} />
                            ) : (
                                <PositionRegulation position={position.id} regulation={regulation} />
                            )}
                        </Card>
                    </div>

                    {/* It follows the document down: a sheet of nine sections is
                        long, and who holds the post is worth keeping in view while
                        one reads what the post is. Taller than the window, it
                        scrolls itself rather than losing its last card. */}
                    <aside className="flex min-w-0 flex-col gap-4 lg:sticky lg:top-4 lg:max-h-[calc(100svh-2rem)] lg:overflow-y-auto">
                        <Section title="Подразделение">
                            <Placement general={profile.general} department={position.department} canEdit={canEdit} />
                        </Section>

                        {/*
                         * What the post answers for, read as a whole: the same list
                         * the holders below carry their share of, in the same
                         * green. A duty is a sentence rather than a word, so the
                         * chips wrap and the long ones break across lines.
                         */}
                        <Section
                            title="Обязанности"
                            count={position.duties.length}
                            action={
                                canEdit && (
                                    <EditPencil
                                        label="Изменить обязанности"
                                        tip="Изменить должность"
                                        busy={opening}
                                        className="-my-1 -mr-2.5 lg:my-0 lg:-mr-2 lg:size-7"
                                        onClick={openEditor}
                                    />
                                )
                            }
                        >
                            {position.duties.length === 0 ? (
                                <p className="text-muted-foreground text-sm">У должности не указаны обязанности.</p>
                            ) : (
                                <ul className="flex flex-wrap gap-1">
                                    {position.duties.map((duty) => (
                                        <li key={duty.id} className="max-w-full">
                                            <StatusBadge tone="success" className={chip}>
                                                <span className="min-w-0 break-words">{duty.name}</span>
                                            </StatusBadge>
                                        </li>
                                    ))}
                                </ul>
                            )}
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

            {/* The same window the list of positions opens, so a job is named,
                filed and described in one place however it was reached. */}
            {editing && editorOptions && (
                <PositionEditor
                    position={{
                        id: position.id,
                        name: position.name,
                        is_head: position.is_head,
                        department: position.department === null ? null : { id: position.department.id },
                        duties: position.duties.map((duty) => duty.name),
                    }}
                    suggestions={editorOptions.duties}
                    departments={editorOptions.departments}
                    onClose={() => setEditing(false)}
                />
            )}
        </AppLayout>
    );
}
