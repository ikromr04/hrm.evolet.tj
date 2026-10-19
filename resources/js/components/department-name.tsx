import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import { type ReactNode } from 'react';

/**
 * A department as a field of a page names it: the short form it is known by,
 * the full name along for whoever needs it, and the id, so the field can be a
 * way back to the department it names.
 */
export interface Filing {
    id: number;
    name: string;
    full_name: string;
}

/**
 * "Департамент маркетинга (ДМ)" — both names at once, for the two places with
 * room to say what an abbreviation stands for: the «Подразделения» list and a
 * department's own page. One that has no abbreviation is just its name, with no
 * empty brackets after it.
 */
export function departmentTitle(full: string, short: string | null | undefined): string {
    return short && short !== full ? `${full} (${short})` : full;
}

/**
 * A department named the short way, with its full name within reach.
 *
 * An abbreviation is all a chart box or a table cell has room for, so the full
 * name has to stay reachable three ways at once, because no one of them serves
 * everybody: a tooltip for a mouse, a hidden copy beside it that becomes what a
 * screen reader reads out, and — since a finger has no hover — the link around
 * it, as every abbreviation on screen leads to the department's own page, which
 * spells the name out in its heading.
 *
 * Where the surrounding element already owns its hover — a chart box, a badge
 * that carries a title of its own — `tooltip={false}` leaves that one in
 * charge rather than nesting a second one inside it; the hidden copy stays
 * either way.
 */
export function DepartmentName({
    name,
    full,
    className,
    tooltip = true,
}: {
    /** What is shown: the abbreviation, or the full name when there is none. */
    name: string;
    full: string;
    className?: string;
    tooltip?: boolean;
}) {
    // Nothing is hidden when the two are the same, so there is nothing to say.
    if (name === full) return <span className={className}>{name}</span>;

    const short = (
        <span className={className}>
            <span aria-hidden="true">{name}</span>
            <span className="sr-only">{full}</span>
        </span>
    );

    if (!tooltip) return short;

    // Its own provider: these names are drawn in tables, in badges and in
    // chart boxes, and a hint that only works where somebody remembered to wrap
    // the page is a hint that mostly does not work.
    return (
        <TooltipProvider delayDuration={400}>
            <Tooltip>
                <TooltipTrigger asChild>{short}</TooltipTrigger>
                <TooltipContent>{full}</TooltipContent>
            </Tooltip>
        </TooltipProvider>
    );
}

/**
 * A department as the tables of the app name it: the abbreviation on one line
 * and, underneath it, quieter and smaller, what the abbreviation stands for —
 * the whole of it the link to the department's own page.
 *
 * The org chart names departments this way, and a table that named them any
 * other way would be teaching the same abbreviations twice. Both lines belong
 * to the link, so the label reads out whole and the full name is not left
 * waiting behind a hover or in a copy kept for a screen reader; only the
 * abbreviation takes the underline, since two underlined lines read as two
 * names rather than one.
 *
 * A department with no abbreviation is already named in full on the first line,
 * so there is nothing left to spell out underneath — and that one line is then
 * as long as «Отдел Развития Продуктового Портфеля» rather than three letters,
 * which is why it is given what the second line is given: it wraps and is cut
 * where the second line would be cut.
 */
export function DepartmentLink({
    id,
    name,
    full,
    leading,
    lines = 2,
    className,
}: {
    id: number;
    /** What is shown: the abbreviation, or the full name where there is none. */
    name: string;
    full: string;
    /** Something standing before the abbreviation, such as the crown of a head. */
    leading?: ReactNode;
    /**
     * How many lines the name underneath may take. Two where a cell holds one
     * department, which is the most these names need; one where a cell stacks
     * several of them and the lines of two columns are read across each other,
     * since a name that wrapped in one column would carry everything under it
     * out of step with the other.
     */
    lines?: 1 | 2;
    className?: string;
}) {
    const spelled = full !== name;
    // Cut at the line the name is allowed to reach rather than stretching the
    // column: what is cut off the hover still says, and the department's own
    // page spells it out in its heading either way. A word longer than the
    // column breaks rather than pushing the cell wider — and the wrapping is
    // asked for, because a cell is cut off at its own edge unless its content
    // says it wraps.
    const clamped = cn(lines === 1 ? 'line-clamp-1' : 'line-clamp-2', 'break-words whitespace-normal');

    return (
        <Link
            href={route('departments.show', id)}
            className={cn('group flex min-w-0 flex-col gap-0.5', className)}
            // The longest names do not fit even two lines, and a name cut short
            // has to be readable without leaving the table.
            title={`Открыть: ${full}`}
        >
            <span className="flex min-w-0 items-center gap-1">
                {leading}
                <span className={cn('min-w-0 group-hover:underline', !spelled && clamped)}>{name}</span>
            </span>
            {spelled && <span className={cn('text-muted-foreground text-[11px] leading-tight', clamped)}>{full}</span>}
        </Link>
    );
}
