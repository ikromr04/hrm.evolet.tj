import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { Portal as TooltipPortal } from '@radix-ui/react-tooltip';
import { type ReactNode } from 'react';

/**
 * A word on what an icon-only header button does, shown on hover. Not on focus:
 * the menu or filter a button opens hands focus back to it on closing, and the
 * hint would pop up then for no reason; a screen reader has the button's label.
 */
export function HeaderHint({ label, children }: { label: string; children: ReactNode }) {
    return (
        <TooltipProvider delayDuration={400}>
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
