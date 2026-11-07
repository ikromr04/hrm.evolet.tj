import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { Portal as TooltipPortal } from '@radix-ui/react-tooltip';
import { type ReactNode } from 'react';

/**
 * A word on what an icon-only button does, shown on hover. Not on focus:
 * the menu or filter a button opens hands focus back to it on closing, and the
 * hint would pop up then for no reason; a screen reader has the button's label.
 *
 * Written for the buttons in a table's head and used by the ones in its rows as
 * well — an icon with nothing written on it asks the same question wherever it
 * stands, and the answer should not be worded twice.
 *
 * It answers at once rather than after a pause. A pause is there to keep hints
 * from flashing at a cursor merely crossing the screen, but these sit in a
 * column at the edge of a table where nothing else is to be pointed at: the
 * cursor that arrives on one is asking.
 */
export function Hint({ label, children }: { label: string; children: ReactNode }) {
    return (
        <TooltipProvider delayDuration={0}>
            <Tooltip>
                <TooltipTrigger asChild onFocus={(event) => event.preventDefault()}>
                    {children}
                </TooltipTrigger>
                {/* Out of the table, whose scrolling box would clip it. */}
                <TooltipPortal>
                    <TooltipContent className="px-2 py-1 text-xs font-normal">{label}</TooltipContent>
                </TooltipPortal>
            </Tooltip>
        </TooltipProvider>
    );
}
