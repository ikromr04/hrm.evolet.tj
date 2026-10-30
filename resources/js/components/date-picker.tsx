import { Button } from '@/components/ui/button';
import { Calendar } from '@/components/ui/calendar';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { useClosesOnPageScroll } from '@/hooks/use-closes-on-page-scroll';
import { formatDate } from '@/lib/employee';
import { cn } from '@/lib/utils';
import { CalendarIcon } from 'lucide-react';
import { useState } from 'react';

/** «2026-11-05» — the way a date travels to the server and back. */
const toISO = (date: Date) => `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;

/** The same, read back without the timezone taking a day off it. */
function fromISO(value: string): Date | undefined {
    const [year, month, day] = value.split('-').map(Number);

    return year && month && day ? new Date(year, month - 1, day) : undefined;
}

/**
 * A date chosen from a month rather than typed into a browser's own box.
 *
 * It reads as every date in the app does — «05 ноя. 2026» — and is held as the
 * day itself, so a date picked at midnight in Dushanbe is the day it looks
 * like and not the one before it.
 */
export function DatePicker({
    value,
    onChange,
    placeholder = 'Выберите дату',
    disabled,
    invalid,
    required,
    fromYear,
    fromDate,
    toDate,
    className,
    id,
}: {
    value: string;
    onChange: (value: string) => void;
    placeholder?: string;
    disabled?: boolean;
    invalid?: boolean;
    /** The form will not do without a date here: what the star beside the label says. */
    required?: boolean;
    /** The earliest year the month list offers; the hundred years back by default. */
    fromYear?: number;
    /** The earliest day that may be chosen, where a date cannot fall before another one. */
    fromDate?: Date;
    /** The last day that may be chosen — today, where a date cannot be in the future. */
    toDate?: Date;
    className?: string;
    id?: string;
}) {
    const [open, setOpen] = useState(false);
    const panel = useClosesOnPageScroll(open, setOpen);
    const selected = value ? fromISO(value) : undefined;
    // A floor and a ceiling have to travel as two matchers of their own: one
    // object carrying both `before` and `after` reads as the days between them.
    const bounds = [...(fromDate ? [{ before: fromDate }] : []), ...(toDate ? [{ after: toDate }] : [])];

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <Button
                    id={id}
                    type="button"
                    variant="outline"
                    disabled={disabled}
                    aria-invalid={invalid}
                    aria-required={required}
                    className={cn(
                        'w-full justify-start gap-2 px-3 font-normal tabular-nums',
                        // The same grey a box with nothing typed in it shows, because
                        // this says the same thing: nothing has been answered here yet.
                        !value && 'text-placeholder hover:text-placeholder',
                        'aria-invalid:border-destructive aria-invalid:ring-destructive/20',
                        className,
                    )}
                >
                    <CalendarIcon className="text-muted-foreground size-4 shrink-0" />
                    {value ? formatDate(value) : placeholder}
                </Button>
            </PopoverTrigger>

            <PopoverContent ref={panel} align="start" className="w-auto overflow-hidden p-0">
                <Calendar
                    mode="single"
                    autoFocus
                    selected={selected}
                    defaultMonth={selected ?? toDate ?? fromDate}
                    captionLayout="dropdown"
                    startMonth={fromDate ?? new Date(fromYear ?? new Date().getFullYear() - 100, 0)}
                    endMonth={toDate ?? new Date(new Date().getFullYear() + 10, 11)}
                    disabled={bounds.length > 0 ? bounds : undefined}
                    onSelect={(date) => {
                        // A click on the day already chosen reports nothing chosen,
                        // which is how a date is taken back: the box empties rather
                        // than keeping what was clicked away from.
                        onChange(date ? toISO(date) : '');
                        setOpen(false);
                    }}
                />

                {/* And a way that does not have to be guessed at. Only with
                    something to clear: an empty box needs no button to empty it. */}
                {value !== '' && (
                    <div className="border-t p-2">
                        <Button
                            type="button"
                            variant="ghost"
                            className="text-muted-foreground h-8 w-full justify-start px-2 font-normal"
                            onClick={() => {
                                onChange('');
                                setOpen(false);
                            }}
                        >
                            Очистить
                        </Button>
                    </div>
                )}
            </PopoverContent>
        </Popover>
    );
}
