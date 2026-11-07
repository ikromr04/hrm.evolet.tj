import { Hint } from '@/components/data-table/hint';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { type LucideIcon } from 'lucide-react';

/**
 * What can be done to one row of a table, as the buttons themselves.
 *
 * These used to hide behind a «⋯» menu. A menu is the right answer where there
 * are a dozen things to do, or where what they are depends on reading the row
 * first; here there are two or three, they are the same two or three down the
 * whole table, and every one of them cost two presses and a wait for the menu
 * to open. Laid out as buttons they cost one press, and a column of repeating
 * icons also says at a glance which rows can be touched at all — a row whose
 * delete is not this viewer's to use simply has one button fewer.
 *
 * Each carries its word on hover and in its label, because an icon alone is a
 * guess: a cross is «уволить» on one row of this application and «удалить» on
 * the next, and the two are not the same mistake to make.
 */
export interface RowAction {
    key: string;
    /** What it does, in words: the hint on hover, and what a reader hears. */
    label: string;
    Icon: LucideIcon;
    onSelect: () => void;
    disabled?: boolean;
    /** Red, kept for what cannot be undone. */
    danger?: boolean;
}

/** Red, for the actions there is no taking back. */
const dangerAction = 'text-[#B42318] hover:text-[#B42318] dark:text-[#F7A19A] dark:hover:text-[#F7A19A]';

export function RowActions({ actions, of, className }: { actions: RowAction[]; of?: string; className?: string }) {
    // Nothing this viewer may do to this row: no buttons, and no gap where
    // they would have been.
    if (actions.length === 0) {
        return null;
    }

    return (
        <div
            // Always drawn, wherever it is put. Hiding these until the row is
            // pointed at is the table's own doing — it floats them over the
            // row's right-hand end and fades them in — and a phone, which has
            // no cursor to hover with, shows the same buttons in its list
            // outright.
            className={cn('flex items-center justify-end gap-1', className)}
        >
            {actions.map(({ key, label, Icon, onSelect, disabled, danger }) => (
                <Hint key={key} label={label}>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        disabled={disabled}
                        onClick={onSelect}
                        // Named with the row it belongs to, because a reader
                        // meeting «Удалить» eleven times down a table is being
                        // told nothing: it is the row that tells them apart.
                        aria-label={of === undefined ? label : `${label}: ${of}`}
                        className={cn('text-muted-foreground size-7', danger && dangerAction)}
                    >
                        <Icon className="size-4" />
                    </Button>
                </Hint>
            ))}
        </div>
    );
}
