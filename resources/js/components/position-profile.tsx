import { DepartmentName } from '@/components/department-name';
import { DocumentCard } from '@/components/document-card';
import { BODY, MEASURE, type DocumentSection, type Paper } from '@/lib/document';
import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import { type ReactNode } from 'react';

/** A department as every screen names it: short, full within reach. */
interface Filing {
    id: number;
    name: string;
    full_name: string;
}

/**
 * «01 ОБЩАЯ ИНФОРМАЦИЯ», as data rather than as text.
 *
 * Every line of it is something the application already knows, so none of it is
 * typed: where the position sits, what it is called, and what it is graded at.
 * Writing it out by hand would be a second copy of the structure, wrong the
 * first time somebody moved a position and never noticed this sheet.
 */
export interface General {
    /** Null where the position is filed on a department that has no parent. */
    unit: Filing | null;
    department: Filing | null;
    position: string;
    category: string | null;
    grade: string | null;
    /** Which position this one answers to. Always empty: the application does not record it. */
    reports_to: string | null;
    /** Which positions answer to this one. Always empty, for the same reason. */
    subordinates: string[];
}

export interface Profile extends Paper {
    general: General;
}

/**
 * What a line of the table says when there is nothing in it.
 *
 * Three different kinds of nothing, and they are not the same thing to read:
 * a category nobody has filled in yet is waiting for somebody and says where
 * they would do it; a position with no parent department has nothing missing at
 * all; and a line the application does not keep is not waiting for anybody.
 */
function Blank({ children }: { children: ReactNode }) {
    return <span className="text-muted-foreground">{children}</span>;
}

const UNFILLED = 'заполняется в списке должностей';
const UNKEPT = 'приложение пока не ведёт';

/** A department, linked to its own page, under whichever name fits the cell. */
function Where({ filing }: { filing: Filing | null }) {
    if (filing === null) return <Blank>—</Blank>;

    return (
        <Link href={route('departments.show', filing.id)} className="text-brand-strong hover:underline dark:text-[#C5E27A]">
            <DepartmentName name={filing.name} full={filing.full_name} />
        </Link>
    );
}

/**
 * The first section of the sheet: the lines of the Word original's two-column
 * table, in its order, each read out of the structure rather than written here.
 *
 * «Подчинение» and «Подчинённые» are two lines the application cannot fill: it
 * records which department a position is filed in and nothing about one position
 * answering to another. They stay in the table because the document has them,
 * and they say plainly that nothing is kept there — inventing a chain of command
 * out of the department tree would be a guess printed on a signed sheet.
 */
function GeneralTable({ general }: { general: General }) {
    const lines: { label: string; value: ReactNode }[] = [
        { label: 'ОТДЕЛ', value: <Where filing={general.unit} /> },
        { label: 'ДЕПАРТАМЕНТ', value: <Where filing={general.department} /> },
        { label: 'ДОЛЖНОСТЬ', value: general.position },
        { label: 'КАТЕГОРИЯ', value: general.category ?? <Blank>Не указана — {UNFILLED}</Blank> },
        { label: 'ГРАЙД', value: general.grade ?? <Blank>Не указан — {UNFILLED}</Blank> },
        { label: 'ПОДЧИНЕНИЕ', value: general.reports_to ?? <Blank>Не указано — {UNKEPT}</Blank> },
        {
            label: 'ПОДЧИНЕННЫЕ',
            value: general.subordinates.length > 0 ? general.subordinates.join(', ') : <Blank>Не указаны — {UNKEPT}</Blank>,
        },
    ];

    return (
        <div className={cn(BODY, MEASURE)}>
            <table>
                <tbody>
                    {lines.map((line) => (
                        <tr key={line.label}>
                            {/* The label column is the narrower of the two and
                                does not wrap a word that would fit: the names are
                                one word each and the answers are what is read. */}
                            <th scope="row" className="w-[12rem]">
                                {line.label}
                            </th>
                            <td>{line.value}</td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

/**
 * «Профиль должности» as a position reads it.
 *
 * The document itself is drawn by {@link DocumentCard}, which a department's
 * «Положение о работе» draws too. What belongs to this one is here: its five
 * addresses, and its first section — the one the application fills in out of the
 * structure, which is why that one carries no pencil.
 *
 * Nothing names an owner in these sentences: the heading of the sheet already
 * says whose it is, and «написан для «Ведущий специалист отдела HR»» would read
 * as a mistake rather than as information.
 */
export function PositionProfile({ position, profile }: { position: number; profile: Profile }) {
    return (
        <DocumentCard
            owner={position}
            addresses={{
                title: 'positions.profile.title',
                store: 'positions.profile.sections.store',
                update: 'positions.profile.sections.update',
                destroy: 'positions.profile.sections.destroy',
                order: 'positions.profile.order',
            }}
            paper={profile}
            empty="Разделы профиля не загрузились. Обновите страницу, а если они не появятся — сообщите администратору."
            fixed={(section: DocumentSection) => (section.computed ? <GeneralTable general={profile.general} /> : null)}
        />
    );
}
