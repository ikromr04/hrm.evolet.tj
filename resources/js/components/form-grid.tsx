import { cn } from '@/lib/utils';
import { type ReactNode } from 'react';

/**
 * Fields laid side by side: one to a row where there is no room, two where
 * there is, three where there is room for three.
 *
 * Measured against the form rather than the window, because the same group of
 * fields is asked for in places of very different widths — a page of its own, a
 * dialog, a column beside a nav — and a rule written in viewport widths puts
 * three fields across a narrow dialog on a wide monitor. From `md` up only:
 * a phone gets one field to a row whatever the arithmetic says, because two
 * halves of a phone are not a place to type a surname.
 *
 * It does not declare the container it measures — that belongs to the form
 * above it — and this is deliberate rather than an omission. Every grid on a
 * page then measures the same thing, so the fields of one part line up with
 * the fields of the next instead of each part breaking at its own width; and a
 * field inside may say `@min-[22rem]:col-span-2` to take a whole row at the
 * same moment this grid decides there are two, which only works while both
 * read the one reference. A form holding these must carry `@container`.
 */
export function FormGrid({ children, className }: { children: ReactNode; className?: string }) {
    return (
        <div
            className={cn(
                // Each column stops at 400px, because a box twice as wide as the
                // longest thing anybody types in it is harder to read, not easier:
                // the eye loses which label the far end of the box belongs to. A
                // wide page is left with room to spare on the right rather than
                // three boxes stretched across it.
                //
                // minmax(0,400px) and not minmax(auto,400px): `auto` floors a track
                // at the width of what is in it, so one long unbroken word or one
                // wide select would push its column past the ceiling and set the
                // page scrolling sideways — the ceiling would hold everywhere
                // except where it is needed. The nought lets the column shrink.
                'grid grid-cols-[minmax(0,400px)] gap-4 md:@min-[22rem]:grid-cols-[repeat(2,minmax(0,400px))] md:@min-[30rem]:grid-cols-[repeat(3,minmax(0,400px))]',
                className,
            )}
        >
            {children}
        </div>
    );
}
