import { type EmploymentStatus } from '@/components/employee-actions';
import { PersonFace } from '@/components/person-face';
import { PersonLink } from '@/components/person-link';
import { StatusBadge } from '@/components/status-badge';
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

interface Duty {
    id: number;
    name: string;
    /** Which position asks for the duty — how this page tells its own from the rest. */
    position_id: number;
}

interface Position extends PositionRef {
    duties: { id: number; name: string }[];
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
 * What one person is responsible for.
 *
 * The duties of this position are what the page was opened for, so they come
 * first and in the pale brand green that marks a chosen value everywhere else:
 * among everything the person carries, the green is what says "this is what the
 * page is about". The others are responsibilities they carry elsewhere; they stay
 * plain and name, on hover, the position they come from.
 */
function Duties({ person, positionId }: { person: Holder; positionId: number }) {
    if (person.duties.length === 0) {
        return <p className="text-muted-foreground text-[13px]">Обязанности не указаны</p>;
    }

    const here = person.duties.filter((duty) => duty.position_id === positionId);
    const elsewhere = person.duties.filter((duty) => duty.position_id !== positionId);
    const positionNames = new Map(person.positions.map((position) => [position.id, position.name]));

    return (
        <div className="flex flex-col gap-1.5">
            {here.length > 0 && (
                <div role="group" aria-label="Обязанности этой должности" className="flex flex-wrap gap-1">
                    {here.map((duty) => (
                        <StatusBadge key={duty.id} tone="success" className={cn(chip, 'font-semibold')}>
                            {duty.name}
                        </StatusBadge>
                    ))}
                </div>
            )}
            {elsewhere.length > 0 && (
                <div className="flex flex-wrap items-baseline gap-x-2 gap-y-1">
                    <span className="text-muted-foreground text-[13px]">Другие обязанности:</span>
                    {elsewhere.map((duty) => (
                        <StatusBadge key={duty.id} tone="neutral" className={chip} title={positionNames.get(duty.position_id)}>
                            {duty.name}
                        </StatusBadge>
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
    canOpenCards,
    showsPositions,
}: {
    person: Holder;
    positionId: number;
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
                        <Duties person={person} positionId={positionId} />
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
                        {position.duties.length === 0 ? (
                            <p className="text-muted-foreground text-sm">Обязанности не указаны</p>
                        ) : (
                            // Numbered down a column, in the duties' own order: the order
                            // somebody entered them, which is the order the server sends
                            // them in. A duty is a sentence more often than a word, so each
                            // takes a line and wraps within it; the number keeps its column.
                            <ol className="flex flex-col gap-1 text-sm">
                                {position.duties.map((duty, index) => (
                                    <li key={duty.id} className="flex gap-2">
                                        <span className="text-muted-foreground shrink-0 tabular-nums">{index + 1}.</span>
                                        <span className="min-w-0 break-words">{duty.name}</span>
                                    </li>
                                ))}
                            </ol>
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
