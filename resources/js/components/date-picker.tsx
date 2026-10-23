import { Button } from '@/components/ui/button';
import { Calendar } from '@/components/ui/calendar';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
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
    fromYear,
    toDate,
    className,
    id,
}: {
    value: string;
    onChange: (value: string) => void;
    placeholder?: string;
    disabled?: boolean;
    invalid?: boolean;
    /** The earliest year the month list offers; the hundred years back by default. */
    fromYear?: number;
    /** The last day that may be chosen — today, where a date cannot be in the future. */
    toDate?: Date;
    className?: string;
    id?: string;
}) {
    const [open, setOpen] = useState(false);
    const selected = value ? fromISO(value) : undefined;

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <Button
                    id={id}
                    type="button"
                    variant="outline"
                    disabled={disabled}
                    aria-invalid={invalid}
                    className={cn(
                        'w-full justify-start gap-2 px-3 font-normal tabular-nums',
                        !value && 'text-muted-foreground',
                        'aria-invalid:border-destructive aria-invalid:ring-destructive/20',
                        className,
                    )}
                >
                    <CalendarIcon className="text-muted-foreground size-4 shrink-0" />
                    {value ? formatDate(value) : placeholder}
                </Button>
            </PopoverTrigger>

            <PopoverContent align="start" className="w-auto p-0">
                <Calendar
                    mode="single"
                    autoFocus
                    selected={selected}
                    defaultMonth={selected ?? toDate}
                    captionLayout="dropdown"
                    startMonth={new Date(fromYear ?? new Date().getFullYear() - 100, 0)}
                    endMonth={toDate ?? new Date(new Date().getFullYear() + 10, 11)}
                    disabled={toDate ? { after: toDate } : undefined}
                    onSelect={(date) => {
                        if (!date) return;

                        onChange(toISO(date));
                        setOpen(false);
                    }}
                />
            </PopoverContent>
        </Popover>
    );
}
