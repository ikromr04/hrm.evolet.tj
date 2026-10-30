import { cn } from '@/lib/utils';
import { type ReactNode } from 'react';

/**
 * A page read in numbered parts: what it is made of down the left, and the
 * parts themselves beside them.
 *
 * The two belong together — one says where you are in the other — so they are
 * laid out here rather than by each page that happens to have both. It holds
 * those two and nothing else, which is why they are named rather than passed
 * as children: a third thing dropped into this grid would land in one of the
 * columns and be spaced as if it were a part of the page, which it would not be.
 *
 * The spacing between the parts belongs here too, so that a page holding six of
 * them is not spaced unlike the next page holding five.
 *
 * Below `lg` the margin lies down: the nav becomes a strip that scrolls
 * sideways (it does that itself) and the parts take the whole width, because a
 * 240px column beside a form leaves neither of them room.
 */
export function Sections({ nav, sections, className }: { nav: ReactNode; sections: ReactNode; className?: string }) {
    return (
        <div
            className={cn(
                // minmax(0,1fr) rather than 1fr or auto: a grid column sizes to
                // its content by default, so one wide table or one long unbroken
                // word inside would push the column past the grid and set the
                // whole page scrolling sideways. The nought lets it shrink.
                'grid grid-cols-1 gap-4 lg:grid-cols-[240px_minmax(0,1fr)] lg:items-start',
                className,
            )}
        >
            {nav}

            {/* min-w-0 for the reason the column beside it carries minmax(0,1fr). */}
            <div className="flex min-w-0 flex-col gap-5">{sections}</div>
        </div>
    );
}
