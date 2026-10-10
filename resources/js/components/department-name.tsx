import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';

/**
 * "Департамент маркетинга (ДМ)" — both names at once, for the two places with
 * room to say what an abbreviation stands for: the «Отделы» directory and a
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

    return (
        <Tooltip>
            <TooltipTrigger asChild>{short}</TooltipTrigger>
            <TooltipContent>{full}</TooltipContent>
        </Tooltip>
    );
}
