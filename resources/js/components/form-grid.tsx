import { cn } from '@/lib/utils';
import { type ReactNode } from 'react';

/**
 * Fields laid side by side: one to a row, two, or three, by how much room the
 * row itself has.
 *
 * Three widths, counted on the grid and not on the window: up to 720px a field
 * has the row to itself, from 721px two share it, and from 1101px three do. The
 * steps are stated rather than worked out from a minimum column width, so that
 * «two columns» means two at the same width on every page that has the room —
 * a grid that fits as many as it can gives a dialog and a full page different
 * answers at the same measure, and two forms of the same card then disagree
 * about where their fields sit.
 *
 * A field may say `@min-[721px]:col-span-2` to take a whole row of a two-column
 * grid, or `@min-[1101px]:col-span-3` for a three-column one. Those figures are
 * these, and they must go on being these: a field spanning two columns of a row
 * that has one makes a second column out of nothing, and the row ends up two
 * uneven halves.
 */
export function FormGrid({ children, className }: { children: ReactNode; className?: string }) {
    return (
        // The grid measures this wrapper rather than the form around it, which is
        // the whole reason the wrapper is here: an element cannot be asked for its
        // own width, and the form is wider than these columns by its own padding,
        // by the indent of the part, and by the 240px of nav standing inside it.
        // Read against the form, a threshold meaning «two columns» let two narrow
        // ones through on a 768px page. Read here, it means what it says.
        <div className="@container">
            <div className={cn('grid grid-cols-1 gap-4 @min-[721px]:grid-cols-2 @min-[1101px]:grid-cols-3', className)}>{children}</div>
        </div>
    );
}
