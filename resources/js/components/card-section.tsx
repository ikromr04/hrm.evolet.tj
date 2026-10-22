import { Card } from '@/components/ui/card';
import { cn } from '@/lib/utils';
import { type ReactNode } from 'react';

/**
 * A named block of a card, as a colleague's card and a department's page both
 * have them: the name on a ruled band flush with the card's own edges, and
 * whatever acts on the block at the end of that band.
 *
 * It was written for the column beside a department's documents and is said
 * here because the «Обзор» of that same page is made of these too — one
 * furniture for one page, rather than two that drift apart.
 */
export function CardSection({
    title,
    count,
    action,
    className,
    bodyClassName,
    children,
}: {
    title: string;
    count?: number;
    action?: ReactNode;
    className?: string;
    /** For a block whose rows run to the card's edges rather than sitting inside its padding. */
    bodyClassName?: string;
    children: ReactNode;
}) {
    return (
        <Card className={cn('flex flex-col gap-4 rounded-xl px-4 py-5 md:px-6', className)}>
            {/* A card with nothing to press keeps the band all the same, so
                blocks with a button and blocks without still read as one set. */}
            <div className="bg-muted/60 -mx-4 -mt-5 flex min-h-11 flex-wrap items-center justify-between gap-x-3 gap-y-1 rounded-t-xl border-b px-4 py-2 md:-mx-6 md:px-6">
                <h2 className="flex min-w-0 items-baseline gap-2 text-base font-semibold">
                    {title}
                    {count !== undefined && <span className="text-muted-foreground text-sm font-normal tabular-nums">{count}</span>}
                </h2>
                {action}
            </div>
            {bodyClassName === undefined ? children : <div className={bodyClassName}>{children}</div>}
        </Card>
    );
}
