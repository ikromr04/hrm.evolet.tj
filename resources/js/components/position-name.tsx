import { DepartmentName } from '@/components/department-name';

/** The department a job belongs to, as the job names it: short, full within reach. */
export interface PositionUnit {
    /** The abbreviation the department is known by, or its name where it has none. */
    name: string;
    full_name: string;
}

/**
 * A job as every screen names one: the title, and the department it belongs to.
 *
 * The number travels with it where the viewer may open the job's own page and
 * is null for everybody else, so a badge stays a word rather than becoming a
 * link into a refusal.
 */
export interface PositionRef {
    id: number | null;
    name: string;
    unit: PositionUnit | null;
}

/**
 * «Аналитик (ОАП)» — the title with its department in brackets.
 *
 * The same title exists in several departments and means a different job in
 * each, so the brackets are not decoration: they are what tells two «Аналитик»
 * apart. Three letters say nothing by themselves, so the abbreviation keeps the
 * full name within reach — under the cursor, and in a copy a screen reader
 * reads out — which is how a department is named everywhere else on screen.
 *
 * A position nobody has filed in a department yet is just its title: there is
 * no department to name, and empty brackets would say there was.
 */
export function PositionName({ name, unit, className }: { name: string; unit: PositionUnit | null; className?: string }) {
    if (unit === null) {
        return <span className={className}>{name}</span>;
    }

    return (
        <span className={className}>
            {name} (<DepartmentName name={unit.name} full={unit.full_name} />)
        </span>
    );
}

/** The same, as one line of text: for a title attribute, a subtitle, a notification. */
export const positionTitle = (position: { name: string; unit: PositionUnit | null }): string =>
    position.unit === null ? position.name : `${position.name} (${position.unit.name})`;
