import { Button } from '@/components/ui/button';
import { Calendar } from '@/components/ui/calendar';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { useClosesOnPageScroll } from '@/hooks/use-closes-on-page-scroll';
import { formatDate } from '@/lib/employee';
import { cn } from '@/lib/utils';
import { CalendarIcon, X } from 'lucide-react';
import { useState } from 'react';
import { type DateRange as PickedRange } from 'react-day-picker';

/** «2026-11-05» — the way a date travels to the server and back. */
const toISO = (date: Date) => `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;

/** The same, read back without the timezone taking a day off it. */
function fromISO(value: string): Date | undefined {
    const [year, month, day] = value.split('-').map(Number);

    return year && month && day ? new Date(year, month - 1, day) : undefined;
}

/** A stretch of days as the page holds it: either end may be missing. */
export interface DateRange {
    from: string | null;
    to: string | null;
}

/**
 * The two ends of a stretch of days as one answer.
 *
 * Two boxes side by side ask the same question twice and let somebody answer
 * half of it; a stretch is one thing, so it is picked in one calendar — the
 * first press sets where it starts, the second where it ends, and what lies
 * between is drawn rather than left to be worked out from two numbers.
 *
 * While the calendar is open the two ends stand over it, «С» and «До», with
 * the one being answered marked: a calendar that says nothing about what has
 * been pressed so far leaves somebody guessing which half they are filling in.
 * Nothing is asked of the server until both ends are in.
 */
export function DateRangePicker({
    value,
    onChange,
    placeholder = 'Период',
    toDate,
    className,
    id,
}: {
    value: DateRange;
    onChange: (range: DateRange) => void;
    placeholder?: string;
    /** The last day that may be chosen, where days ahead mean nothing. */
    toDate?: Date;
    className?: string;
    id?: string;
}) {
    const [open, setOpen] = useState(false);
    const panel = useClosesOnPageScroll(open, setOpen);
    /** What has been pressed while the calendar is open, before it is asked for. */
    const [draft, setDraft] = useState<PickedRange | undefined>(undefined);
    /**
     * Which end the next press answers, kept here rather than read off the
     * calendar: a calendar answering for a range calls one day a range of one
     * — «с 5 по 5» — and the window would close on the first press, with half
     * the question asked.
     */
    const [step, setStep] = useState<'from' | 'to'>('from');

    const held: PickedRange | undefined =
        value.from || value.to ? { from: value.from ? fromISO(value.from) : undefined, to: value.to ? fromISO(value.to) : undefined } : undefined;
    const picked = draft ?? held;
    const asked = Boolean(value.from || value.to);
    const picking = step;

    /**
     * A press on a day. The first opens a stretch and the second closes it —
     * in either order, since somebody who presses the later day first means
     * the same stretch — and only then is the list asked for it.
     */
    const show = (_range: PickedRange | undefined, day: Date) => {
        if (step === 'from' || !draft?.from) {
            setDraft({ from: day, to: undefined });
            setStep('to');

            return;
        }

        const [start, end] = day < draft.from ? [day, draft.from] : [draft.from, day];

        onChange({ from: toISO(start), to: toISO(end) });
        setDraft(undefined);
        setStep('from');
        setOpen(false);
    };

    const end = (which: 'from' | 'to') => {
        const date = which === 'from' ? picked?.from : picked?.to;

        return (
            <span className="flex min-w-0 flex-col gap-0.5">
                <span className="text-muted-foreground text-[11px] tracking-wide uppercase">{which === 'from' ? 'С' : 'До'}</span>
                <span className={cn('text-sm tabular-nums', picking === which ? 'text-brand-strong font-semibold dark:text-[#C5E27A]' : '')}>
                    {date ? formatDate(toISO(date)) : '—'}
                </span>
            </span>
        );
    };

    return (
        <div className={cn('flex items-center gap-1', className)}>
            <Popover
                open={open}
                onOpenChange={(next) => {
                    setOpen(next);
                    // Opened or closed, the question starts from its first half
                    // again: closed half-answered, the calendar forgets what was
                    // pressed and the list goes on being read as it was.
                    setDraft(undefined);
                    setStep('from');
                }}
            >
                <PopoverTrigger asChild>
                    <Button
                        id={id}
                        type="button"
                        variant="outline"
                        className={cn('h-9 justify-start gap-2 px-3 font-normal tabular-nums', !asked && 'text-muted-foreground')}
                    >
                        <CalendarIcon className="text-muted-foreground size-4 shrink-0" />
                        {asked ? spell(value) : placeholder}
                    </Button>
                </PopoverTrigger>

                <PopoverContent ref={panel} align="start" className="w-auto overflow-hidden p-0">
                    {/* The two ends over the calendar, so a press has somewhere
                        to land that can be read afterwards. */}
                    <div className="flex items-center gap-3 border-b px-3 py-2">
                        {end('from')}
                        <span className="text-muted-foreground">–</span>
                        {end('to')}
                    </div>

                    <Calendar
                        mode="range"
                        autoFocus
                        selected={picked}
                        defaultMonth={picked?.from ?? picked?.to ?? toDate}
                        // One month, which is as much as the popover may be
                        // without outgrowing a phone; a stretch that crosses
                        // into the next is picked by paging to it, and what has
                        // been chosen so far stays drawn while that happens.
                        numberOfMonths={1}
                        captionLayout="dropdown"
                        startMonth={new Date(new Date().getFullYear() - 10, 0)}
                        endMonth={toDate ?? new Date(new Date().getFullYear() + 10, 11)}
                        disabled={toDate ? { after: toDate } : undefined}
                        onSelect={show}
                    />

                    <p className="text-muted-foreground border-t px-3 py-2 text-[13px]">
                        {picking === 'from' ? 'Выберите начало периода' : 'Теперь выберите конец'}
                    </p>
                </PopoverContent>
            </Popover>

            {/* The way back to «за всё время», beside the stretch it clears. */}
            {asked && (
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    className="text-muted-foreground size-9 shrink-0"
                    aria-label="Очистить период"
                    title="Очистить период"
                    onClick={() => onChange({ from: null, to: null })}
                >
                    <X className="size-4" />
                </Button>
            )}
        </div>
    );
}

/**
 * The stretch in words: what the two ends share is said once, and an end that
 * is missing is said as «с …» or «по …» rather than left blank.
 */
function spell({ from, to }: DateRange): string {
    if (from && !to) return `с ${formatDate(from)}`;
    if (!from && to) return `по ${formatDate(to)}`;
    if (!from || !to) return '';

    const start = formatDate(from) ?? '';
    const end = formatDate(to) ?? '';

    if (start === end) return start;

    const [startYear, startMonth] = from.split('-');
    const [endYear, endMonth] = to.split('-');
    // Both in one month: «5–27 ноя. 2026». Both in one year: «5 окт. – 27 ноя. 2026».
    const shared = startYear === endYear ? (startMonth === endMonth ? 2 : 1) : 0;
    const shorten = (one: string) =>
        one
            .split(' ')
            .slice(0, 3 - shared)
            .join(' ');

    return `${shorten(start)} – ${end}`;
}
