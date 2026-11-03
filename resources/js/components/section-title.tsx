import { cn } from '@/lib/utils';

/**
 * The name of one numbered part of a page: the figure in front of it, then the
 * name itself.
 *
 * The figure is what makes it this heading rather than an ordinary one, and it
 * stands in a box of its own — twenty points wide whatever the number — so that
 * the names begin on one vertical however many parts there are, and the figures
 * read as a column down the left. Twenty because «01» and «11» measure 17.6 at
 * this size, and a box they overflow is no column at all.
 *
 * Which fixes what the body of a part is indented by: the twenty of the box and
 * the four of the gap, so twenty-four.
 *
 * Monospace, so «01» and «11» are the same width; in the brand's colour, so
 * the eye finds the figures without reading them.
 *
 * It is an h2 by default, which is what a part of a page usually is; a page
 * whose outline puts it deeper says so with `as`.
 */
export function SectionTitle({
    number,
    title,
    as: Tag = 'h2',
    active = false,
    className,
}: {
    /** Already padded as it is shown — «01», not 1. */
    number: string;
    title: string;
    as?: 'h2' | 'h3';
    /**
     * Whether this is the part the nav beside it has marked.
     *
     * Said twice on purpose: the column says which part is open, and the part
     * says the same of itself, so that the two can be read apart — on a narrow
     * screen the nav is a strip that may have scrolled the marked entry out of
     * sight, and a reader halfway down a long sheet should not have to look up
     * to find out where they are.
     */
    active?: boolean;
    className?: string;
}) {
    return (
        <Tag className={cn('flex items-baseline gap-1 text-base font-semibold', className)}>
            <span className="text-brand-strong w-5 shrink-0 font-mono text-base font-medium tabular-nums dark:text-[#C5E27A]">{number}</span>
            {title}
            {/* The same green the nav marks its open entry with, and the same
                dot the tables use for "this one has something set on it". */}
            {active && <span className="bg-brand size-1.5 shrink-0 self-center rounded-full" aria-hidden />}
        </Tag>
    );
}
