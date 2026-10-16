import { DepartmentName } from '@/components/department-name';
import { BODY, MEASURE } from '@/lib/document';
import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import { type ReactNode } from 'react';

/**
 * «01 ОБЩАЯ ИНФОРМАЦИЯ» — the one section of a profile that is data rather than
 * text, and the furniture it is drawn with.
 *
 * Both sheets open with it: a position's names where it sits and what it is
 * called, a department's names where it sits, who leads it and how many work
 * there. The lines differ, the table does not — so the table is here and each
 * sheet brings its own lines, rather than one of them growing a second shape
 * behind a flag.
 *
 * None of it is typed. Every line is something the application already knows,
 * and writing it out by hand would be a second copy of the structure, wrong the
 * first time somebody moved a department and never noticed this sheet.
 */

/** A department as every screen names it: short, full within reach. */
export interface Filing {
    id: number;
    name: string;
    full_name: string;
}

/**
 * What a line says when there is nothing in it.
 *
 * Not every empty line is the same thing to read, so nothing here decides what
 * it means — it only says it quietly, and each sheet writes the words. A
 * department with nothing above it is not missing an отдел; a line the
 * application does not keep is not waiting for anybody to fill it.
 */
export function Blank({ children }: { children: ReactNode }) {
    return <span className="text-muted-foreground">{children}</span>;
}

/** A department, linked to its own page, under whichever name fits the cell. */
export function Where({ filing }: { filing: Filing | null }) {
    if (filing === null) return <Blank>—</Blank>;

    return (
        <Link href={route('departments.show', filing.id)} className="text-brand-strong hover:underline dark:text-[#C5E27A]">
            <DepartmentName name={filing.name} full={filing.full_name} />
        </Link>
    );
}

/** The two-column table of the Word original, in the order the sheet has it. */
export function GeneralTable({ lines }: { lines: { label: string; value: ReactNode }[] }) {
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
