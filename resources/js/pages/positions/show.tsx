import { DepartmentName } from '@/components/department-name';
import { type EmploymentStatus } from '@/components/employee-actions';
import { PersonFace } from '@/components/person-face';
import { PersonLink } from '@/components/person-link';
import { StatusBadge, type StatusTone } from '@/components/status-badge';
import { Card } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { peopleLabel, type Sex } from '@/lib/employee';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { ClipboardList, Users } from 'lucide-react';
import { type ReactNode } from 'react';

interface PositionRef {
    id: number;
    name: string;
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
    /** Which position asks for the duty — how this page tells its own from the rest. */
    position_id: number;
    /** Null where the position asks for it everywhere; otherwise the department it came with. */
    department_id: number | null;
}

interface Position extends PositionRef {
    /** What the position answers for wherever it is held. */
    duties: DutyRef[];
    /** The departments that ask something of this position on top of that. */
    department_duties: { department: DepartmentRef; duties: DutyRef[] }[];
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
    /** The duties of all those positions together. */
    duties: Duty[];
}

/**
 * A duty can be a whole sentence — "Ведёт табель учёта рабочего времени" — so its
 * chip wraps onto a second line instead of running out of the row it sits in.
 */
const chip = 'h-auto min-h-[22px] max-w-full py-0.5 whitespace-normal';

/** A label over a list of duties, there only once there is more than one list. */
const listLabel = 'text-[13px] font-medium';

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
 * Duties numbered down a column, in their own order: the order somebody entered
 * them, which is the order the server sends them in. A duty is a sentence more
 * often than a word, so each takes a line and wraps within it; the number keeps
 * its column. A department's list counts from one again, because it is a list of
 * its own rather than the tail of the one above it.
 */
function DutyList({ duties }: { duties: DutyRef[] }) {
    return (
        <ol className="flex flex-col gap-1 text-sm">
            {duties.map((duty, index) => (
                <li key={duty.id} className="flex gap-2">
                    <span className="text-muted-foreground shrink-0 tabular-nums">{index + 1}.</span>
                    <span className="min-w-0 break-words">{duty.name}</span>
                </li>
            ))}
        </ol>
    );
}

/**
 * One duty a person carries.
 *
 * A chip that came with a department names it, because two people on the same
 * position no longer carry the same duties, and without the abbreviation the two
 * rows would read as if the page had quietly left something out of one of them.
 * The abbreviation goes unemphasised in front of the duty and leaves the full
 * name to the chip's hover, which also names the position a duty from elsewhere
 * belongs to.
 */
function DutyChip({ duty, tone, position, department }: { duty: Duty; tone: StatusTone; position?: string; department?: DepartmentRef }) {
    const hover = [position, department?.full_name].filter(Boolean).join(' · ');

    return (
        <StatusBadge tone={tone} className={cn(chip, tone === 'success' && 'font-semibold')} title={hover || undefined}>
            {department && (
                <span className="mr-1 font-normal whitespace-nowrap opacity-80">
                    <DepartmentName name={department.name} full={department.full_name} tooltip={false} />
                    <span aria-hidden="true"> ·</span>
                </span>
            )}
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
 */
function Duties({ person, positionId, departments }: { person: Holder; positionId: number; departments: Map<number, DepartmentRef> }) {
    if (person.duties.length === 0) {
        return <p className="text-muted-foreground text-[13px]">Обязанности не указаны</p>;
    }

    const here = person.duties.filter((duty) => duty.position_id === positionId);
    const elsewhere = person.duties.filter((duty) => duty.position_id !== positionId);
    const positionNames = new Map(person.positions.map((position) => [position.id, position.name]));
    // Only a department the page was told the name of can be named on a chip,
    // and every duty of this position is covered, since a department that asks
    // anything of it is one the block above lists. A duty from another position
    // can come from a department the page never heard of; that chip says nothing
    // extra rather than an id.
    const departmentOf = (duty: Duty) => (duty.department_id === null ? undefined : departments.get(duty.department_id));

    return (
        <div className="flex flex-col gap-1.5">
            {here.length > 0 && (
                <div role="group" aria-label="Обязанности этой должности" className="flex flex-wrap gap-1">
                    {here.map((duty) => (
                        <DutyChip key={duty.id} duty={duty} tone="success" department={departmentOf(duty)} />
                    ))}
                </div>
            )}
            {elsewhere.length > 0 && (
                <div className="flex flex-wrap items-baseline gap-x-2 gap-y-1">
                    <span className="text-muted-foreground text-[13px]">Другие обязанности:</span>
                    {elsewhere.map((duty) => (
                        <DutyChip
                            key={duty.id}
                            duty={duty}
                            tone="neutral"
                            position={positionNames.get(duty.position_id)}
                            department={departmentOf(duty)}
                        />
                    ))}
                </div>
            )}
        </div>
    );
}

/** One holder: the face, the name, and the duties they carry. */
function HolderRow({
    person,
    positionId,
    departments,
    canOpenCards,
    showsPositions,
}: {
    person: Holder;
    positionId: number;
    departments: Map<number, DepartmentRef>;
    canOpenCards: boolean;
    showsPositions: boolean;
}) {
    const others = person.positions.filter((position) => position.id !== positionId);
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
                                {others.map((position, index) => (
                                    <span key={position.id}>
                                        <Link
                                            href={route('positions.show', position.id)}
                                            className="text-brand-strong hover:underline dark:text-[#C5E27A]"
                                        >
                                            {position.name}
                                        </Link>
                                        {index < others.length - 1 && ','}
                                    </span>
                                ))}
                            </p>
                        )}
                        <Duties person={person} positionId={positionId} departments={departments} />
                    </>
                )}
            </div>
        </li>
    );
}

export default function PositionPage({
    position,
    employees,
    canOpenCards,
    showsEmployees,
    showsPositions,
}: {
    position: Position;
    employees: Holder[];
    /** Renaming the position and changing its duties stays on the directory page. */
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
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Должности', href: route('positions.index') },
        { title: position.name, href: route('positions.show', position.id) },
    ];

    const byDepartment = position.department_duties;
    // A chip below names a department by its abbreviation, and the names of the
    // departments that ask anything of this position all arrived with the block
    // above.
    const departments = new Map(byDepartment.map(({ department }) => [department.id, department]));
    const hasDuties = position.duties.length > 0 || byDepartment.length > 0;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={position.name} />

            <div className="flex flex-1 flex-col gap-4 p-3 max-md:gap-3 md:px-5 md:py-4">
                <Card className="flex flex-col gap-4 rounded-xl p-4 max-md:rounded-2xl md:p-6">
                    <div className="flex flex-col gap-1.5">
                        {/* A phone's top bar already names the position, so the heading is
                            there for screen readers alone. */}
                        <h1 className="text-xl font-semibold tracking-tight break-words max-md:sr-only">{position.name}</h1>
                        {/* Counting nobody would be a small lie where the list is closed. */}
                        {showsEmployees && (
                            <p className="text-muted-foreground flex items-center gap-1.5 text-sm">
                                <Users className="size-4" />
                                {peopleLabel(employees.length)}
                            </p>
                        )}
                    </div>

                    <div className="flex flex-col gap-2">
                        <h2 className="text-muted-foreground flex items-center gap-1.5 text-[13px] font-medium">
                            <ClipboardList className="size-4" />
                            Обязанности
                        </h2>
                        {!hasDuties ? (
                            <p className="text-muted-foreground text-sm">Обязанности не указаны</p>
                        ) : (
                            <div className="flex flex-col gap-3">
                                <div className="flex flex-col gap-1">
                                    {/* The position asked the same of everybody until a
                                        department asked for more, and only then is there
                                        a second list for a label to tell this one from. */}
                                    {byDepartment.length > 0 && <h3 className={listLabel}>Общие</h3>}
                                    {position.duties.length > 0 ? (
                                        <DutyList duties={position.duties} />
                                    ) : (
                                        <p className="text-muted-foreground text-sm">Не указаны</p>
                                    )}
                                </div>
                                {/* What a department asks comes on top of the common
                                    duties rather than instead of them, which the heading
                                    has to say outright: on its own, a list under a
                                    department's name reads as the whole of the job
                                    there. */}
                                {byDepartment.map(({ department, duties }) => (
                                    <div key={department.id} className="flex flex-col gap-1">
                                        <h3 className={listLabel}>
                                            <DepartmentName name={department.name} full={department.full_name} />
                                            <span className="text-muted-foreground font-normal"> — дополнительно к общим</span>
                                        </h3>
                                        <DutyList duties={duties} />
                                    </div>
                                ))}
                            </div>
                        )}
                    </div>
                </Card>

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
                                    positionId={position.id}
                                    departments={departments}
                                    canOpenCards={canOpenCards}
                                    showsPositions={showsPositions}
                                />
                            ))}
                        </ul>
                    )}
                </Section>
            </div>
        </AppLayout>
    );
}
