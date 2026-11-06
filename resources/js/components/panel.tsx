import { StatusBadge } from '@/components/status-badge';
import { useShows } from '@/components/visible-fields';
import { cn } from '@/lib/utils';
import { type ComponentPropsWithoutRef, type ReactNode } from 'react';

/**
 * A named block of a page: the name on a ruled band flush with the block's own
 * edges, whatever acts on the block at the end of that band, and the answers
 * underneath.
 *
 * This is the one block the application is built out of — a colleague's card,
 * a department's page, the queue of documents waiting to be looked at. It was
 * two components with the same drawing in them until they were put together:
 * two of anything this visible drift apart inside a month, and the half that
 * drifts is the half nobody was looking at.
 *
 * The block is the section, and its header and its body are inside it. The
 * card's look is written out here rather than borrowed by wrapping one element
 * in another: wrapping only put a div between the section and its own header,
 * and the look is four classes.
 *
 * On a phone it is an inset grouped list instead: the name and the action sit
 * above the block, as a native settings screen has them, and what is left holds
 * nothing but rows.
 */
export function Panel({
    title,
    count,
    action,
    fields,
    info,
    className,
    infoClassName,
    bodyClassName,
    children,
    ...rest
}: {
    /** A block that fills a whole tab passes none: the tab names it. */
    title?: string;
    /** A figure beside the name, where the block holds a number of things. */
    count?: number;
    /**
     * What acts on the block, at the end of its header. There may be several —
     * «Версии», «Скачать», «Изменить» — and the row they stand in is this
     * component's to lay out, not the caller's.
     */
    action?: ReactNode;
    /**
     * The lines of a card this block is made of. Where none of them is this
     * viewer's to read, there is no block — not an empty one, which would say
     * that there is nothing on file rather than that it is not theirs to see.
     */
    fields?: string[];
    /**
     * A line about the block as a whole, between its header and its body: how
     * far the sheet has got, what state the paper is in. Ruled off from the
     * body below it and flush with the block's edges, so it reads as a second
     * line of the header rather than as the first row of the answers.
     */
    info?: ReactNode;
    className?: string;
    /** For an info line that is not for paper, or wants laying out its own way. */
    infoClassName?: string;
    /** For a block whose rows run to the edges rather than sitting inside the padding. */
    bodyClassName?: string;
    children?: ReactNode;
} & Omit<ComponentPropsWithoutRef<'section'>, 'title' | 'children'>) {
    const shows = useShows();

    if (fields !== undefined && !fields.some(shows)) {
        return null;
    }

    return (
        // Whatever else was passed lands on the block itself — `data-print`,
        // where one of these is the only thing on a page meant to go to paper.
        <section className={cn('bg-card text-card-foreground flex min-w-0 flex-col rounded-xl border shadow-xs', className)} {...rest}>
            {title && (
                // Above the block and in the colour of a label on a phone,
                // where the block is a grouped list; a band flush with the
                // block's own edges from `md` up, where it is a card.
                <header className="bg-muted/60 flex flex-wrap items-center justify-between rounded-t-xl border-b px-5 py-2">
                    <h2 className="flex min-w-0 items-center gap-2 font-semibold">
                        {title}
                        {count !== undefined && (
                            <StatusBadge tone="neutral" className="font-normal">
                                {count}
                            </StatusBadge>
                        )}
                    </h2>

                    {/* Several buttons may stand here, and only the last of them
                        is the thing the block is really for — «Изменить» after
                        «Версии» and «Скачать». So the last wears the brand
                        colour and the rest step back into the colour of a
                        label: a row of three green words is a row with no first
                        among them, and whoever came to press one has to read all
                        three to find out which. */}
                    {action !== undefined && action !== false && action !== null && (
                        <div className="[&>*:last-child]:text-brand-strong [&>*:not(:last-child)]:text-muted-foreground flex min-w-0 flex-wrap items-center justify-end gap-3 dark:[&>*:last-child]:text-[#C5E27A]">
                            {action}
                        </div>
                    )}
                </header>
            )}

            {info !== undefined && info !== false && info !== null && (
                <div className={cn('flex min-w-0 flex-wrap items-center gap-x-3 border-b px-5 py-2', infoClassName)}>{info}</div>
            )}

            <div
                className={cn(
                    'bg-card flex min-w-0 flex-col rounded-2xl p-5 pt-3',
                    // Under a band the body starts closer to it than it ends
                    // from the bottom edge: the band is a line to read from
                    // rather than a margin to clear.
                    bodyClassName,
                )}
            >
                {children}
            </div>
        </section>
    );
}
