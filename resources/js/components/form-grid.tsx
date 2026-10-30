import { cn } from '@/lib/utils';
import { type ReactNode } from 'react';

/**
 * Fields laid side by side: as many to a row as fit with room to type in.
 *
 * The row is not told how many columns to have. Each column asks for at least
 * 360px — as narrow as a box may get before a date or a long position stops
 * fitting in it — and the row takes as many as that allows, sharing what is
 * left over between them. So one field to a row on a phone, two on a laptop,
 * three on a monitor, and nothing to keep in step by hand as the page changes.
 *
 * From 1920px up the columns stop growing and stand at 400px: a box twice as
 * wide as the longest thing anybody types in it is harder to read, not easier,
 * because the eye loses which label the far end of it belongs to. A very wide
 * page is left with room to spare on the right rather than three stretched
 * boxes across it.
 *
 * A field inside may say `@min-[46rem]:col-span-2` to take a whole row, and
 * that figure is this grid's own arithmetic rather than a guess: 360·2 + 16 of
 * gap = 736px is the width at which a second column appears, so the field
 * begins spanning two at the very moment there are two. They must not
 * disagree — a field spanning two columns of a row that has one makes a second
 * column out of nothing, and the row ends up two uneven halves.
 */
export function FormGrid({ children, className }: { children: ReactNode; className?: string }) {
    return (
        // The grid measures this wrapper rather than the form around it, which is
        // the whole reason the wrapper is here: an element cannot be asked for its
        // own width, and the form is wider than these columns by its own padding,
        // by the indent of the part, and by the 240px of nav standing inside it.
        // Read against the form, a threshold meaning «two columns of 320px» let
        // two columns of 211px through on a 768px page. Read here, it means what
        // it says.
        <div className="@container">
            <div
                className={cn(
                    // min(360px,100%) and not a bare 360px: a floor is a promise the
                    // grid keeps by overflowing, so in a box narrower than one column
                    // — a cramped dialog — a bare figure would push the column out of
                    // the page. Against 100% the floor gives way instead.
                    //
                    // minmax(0,400px) above: with no stretching to do, the nought lets
                    // a column shrink where a hundredth of a pixel is missing, and the
                    // ceiling is what the column is actually worth.
                    'grid grid-cols-[repeat(auto-fit,minmax(min(360px,100%),1fr))] gap-4 min-[1921px]:grid-cols-[repeat(auto-fit,minmax(0,400px))]',
                    className,
                )}
            >
                {children}
            </div>
        </div>
    );
}
