import { Button } from '@/components/ui/button';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';
import { LoaderCircle, Pencil } from 'lucide-react';

/**
 * The pencil that opens something for changing — beside the heading of what it
 * changes, or in the corner of the card that holds it.
 *
 * One treatment wherever a page is written on in place: a section of a
 * document, the document as a whole, the department the column beside it
 * describes. It is a bordered icon button, the width of a finger on a phone and
 * the height of the buttons it stands beside, named out loud for whoever cannot
 * see where it sits and saying on hover and on focus what it opens — because
 * the button itself has no words, and a page may carry two of them at once.
 *
 * Bordered rather than bare: it is a button among buttons wherever the design
 * puts it, and a bare icon over a card read as part of the card.
 */
export function EditPencil({
    label,
    tip = 'Изменить',
    busy = false,
    className,
    onClick,
}: {
    /** What this pencil opens, for a screen reader: «Изменить подразделение». */
    label: string;
    /** The same on hover and on focus; the short word where the card says the rest. */
    tip?: string;
    /** Waiting for what the window needs, so a press is answered rather than ignored. */
    busy?: boolean;
    className?: string;
    onClick: () => void;
}) {
    return (
        <TooltipProvider delayDuration={400}>
            <Tooltip>
                <TooltipTrigger asChild>
                    <Button
                        type="button"
                        variant="outline"
                        size="icon"
                        className={cn('text-muted-foreground size-9 shrink-0 max-md:size-11', className)}
                        aria-label={label}
                        disabled={busy}
                        onClick={onClick}
                    >
                        {busy ? <LoaderCircle className="size-4 animate-spin" /> : <Pencil className="size-4" />}
                    </Button>
                </TooltipTrigger>
                <TooltipContent className="px-2 py-1 text-xs font-normal">{tip}</TooltipContent>
            </Tooltip>
        </TooltipProvider>
    );
}
