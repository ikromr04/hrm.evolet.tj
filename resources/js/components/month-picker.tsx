import { Button } from '@/components/ui/button';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { useClosesOnPageScroll } from '@/hooks/use-closes-on-page-scroll';
import { shortMonths } from '@/lib/employee';
import { cn } from '@/lib/utils';
import { CalendarIcon, ChevronLeft, ChevronRight } from 'lucide-react';
import { useEffect, useState } from 'react';

/** «2020-09» — a month as it travels, and as a card remembers a past job. */
const parse = (value: string) => {
    const [year, month] = value.split('-').map(Number);

    return year && month ? { year, month } : undefined;
};

const toValue = (year: number, month: number) => `${year}-${String(month).padStart(2, '0')}`;

/** «сен. 2020»: the months the rest of the application writes dates with. */
const label = (year: number, month: number) => `${shortMonths[month - 1]} ${year}`;

/**
 * A month chosen from a year rather than typed into a browser's own box.
 *
 * The same field as the DatePicker beside it — the same button, the same grey
 * for a question nobody has answered, the same way of taking an answer back —
 * because a card asks for a month exactly where it asks for a day: a labour
 * book names the month somebody started and the month they left, never the
 * day, and a day box would be asking for a precision nobody has.
 *
 * What it holds is «2020-09», which is what a month box held before it and
 * what the two halves the server keeps are put together from.
 */
export function MonthPicker({
    value,
    onChange,
    placeholder = 'Выберите месяц',
    disabled,
    invalid,
    required,
    min,
    max,
    className,
    id,
}: {
    value: string;
    onChange: (value: string) => void;
    placeholder?: string;
    disabled?: boolean;
    invalid?: boolean;
    /** The form will not do without a month here: what the star beside the label says. */
    required?: boolean;
    /** The earliest month that may be chosen, «2020-09»; the rest are closed. */
    min?: string;
    /** And the last — this month, where a date cannot be in the future. */
    max?: string;
    className?: string;
    id?: string;
}) {
    const [open, setOpen] = useState(false);
    const panel = useClosesOnPageScroll(open, setOpen);

    const chosen = parse(value);
    const floor = parse(min ?? '');
    const ceiling = parse(max ?? '');

    // The year the grid is showing, which is not the year chosen: somebody
    // looking for 2014 pages back through the years without choosing one on the
    // way. It opens on the answer, or on the latest year that may be answered.
    const [year, setYear] = useState(() => chosen?.year ?? ceiling?.year ?? new Date().getFullYear());

    // Opened again after the answer changed elsewhere — cleared by the field it
    // belongs to, say — it opens where the answer now is rather than where it
    // was left.
    useEffect(() => {
        if (open) setYear(chosen?.year ?? ceiling?.year ?? new Date().getFullYear());
        // Only the opening matters: paging through the years inside must not be
        // undone by a redraw.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    const beyond = (month: number) =>
        (floor !== undefined && (year < floor.year || (year === floor.year && month < floor.month))) ||
        (ceiling !== undefined && (year > ceiling.year || (year === ceiling.year && month > ceiling.month)));

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
                        !chosen && 'text-placeholder hover:text-placeholder',
                        'aria-invalid:border-destructive aria-invalid:ring-destructive/20',
                        className,
                    )}
                >
                    <CalendarIcon className="text-muted-foreground size-4 shrink-0" />
                    {chosen ? label(chosen.year, chosen.month) : placeholder}
                </Button>
            </PopoverTrigger>

            <PopoverContent ref={panel} align="start" className="w-auto overflow-hidden p-0">
                <div className="flex items-center justify-between gap-2 p-2">
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="size-7"
                        aria-label="Предыдущий год"
                        disabled={floor !== undefined && year <= floor.year}
                        onClick={() => setYear((shown) => shown - 1)}
                    >
                        <ChevronLeft className="size-4" />
                    </Button>

                    <span className="text-sm font-medium tabular-nums" aria-live="polite">
                        {year}
                    </span>

                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="size-7"
                        aria-label="Следующий год"
                        disabled={ceiling !== undefined && year >= ceiling.year}
                        onClick={() => setYear((shown) => shown + 1)}
                    >
                        <ChevronRight className="size-4" />
                    </Button>
                </div>

                {/* Twelve of them, three to a row: the whole year at once, which is
                    the point of asking for a month rather than a day. */}
                <div className="grid grid-cols-3 gap-1 p-2 pt-0">
                    {shortMonths.map((name, index) => {
                        const month = index + 1;
                        const picked = chosen?.year === year && chosen.month === month;

                        return (
                            <Button
                                key={name}
                                type="button"
                                variant={picked ? 'default' : 'ghost'}
                                size="sm"
                                disabled={beyond(month)}
                                aria-current={picked ? 'date' : undefined}
                                className="h-8 w-16 font-normal"
                                onClick={() => {
                                    onChange(toValue(year, month));
                                    setOpen(false);
                                }}
                            >
                                {name}
                            </Button>
                        );
                    })}
                </div>

                {/* And a way to take the answer back that does not have to be
                    guessed at. Only with something to clear: an empty box needs
                    no button to empty it. */}
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
