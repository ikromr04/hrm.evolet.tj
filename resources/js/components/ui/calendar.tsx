import { Button, buttonVariants } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { ChevronDownIcon, ChevronLeftIcon, ChevronRightIcon } from 'lucide-react';
import * as React from 'react';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { DayPicker, getDefaultClassNames, type DayButton, type DropdownProps } from 'react-day-picker';
import { ru } from 'react-day-picker/locale';

/**
 * shadcn's calendar, as its registry publishes it, with Russian as the language
 * it opens in: the month names, the short weekdays and the week starting on
 * Monday all come with the locale, and a page may hand it another.
 */
function Calendar({
    className,
    classNames,
    showOutsideDays = true,
    captionLayout = 'label',
    buttonVariant = 'ghost',
    formatters,
    components,
    ...props
}: React.ComponentProps<typeof DayPicker> & {
    buttonVariant?: React.ComponentProps<typeof Button>['variant'];
}) {
    const defaultClassNames = getDefaultClassNames();

    return (
        <DayPicker
            locale={ru}
            showOutsideDays={showOutsideDays}
            className={cn(
                'group/calendar bg-background p-3 [--cell-size:--spacing(8)] [[data-slot=card-content]_&]:bg-transparent [[data-slot=popover-content]_&]:bg-transparent',
                className,
            )}
            captionLayout={captionLayout}
            formatters={{
                formatMonthDropdown: (date) => date.toLocaleString('ru', { month: 'short' }),
                ...formatters,
            }}
            classNames={{
                root: cn('w-fit', defaultClassNames.root),
                months: cn('relative flex flex-col gap-4 md:flex-row', defaultClassNames.months),
                month: cn('flex w-full flex-col gap-4', defaultClassNames.month),
                nav: cn('pointer-events-none absolute inset-x-0 top-0 flex w-full items-center justify-between gap-1', defaultClassNames.nav),
                button_previous: cn(
                    buttonVariants({ variant: buttonVariant }),
                    'pointer-events-auto size-(--cell-size) p-0 select-none aria-disabled:opacity-50',
                    defaultClassNames.button_previous,
                ),
                button_next: cn(
                    buttonVariants({ variant: buttonVariant }),
                    'pointer-events-auto size-(--cell-size) p-0 select-none aria-disabled:opacity-50',
                    defaultClassNames.button_next,
                ),
                month_caption: cn('flex h-(--cell-size) w-full items-center justify-center px-(--cell-size)', defaultClassNames.month_caption),
                dropdowns: cn('relative z-10 flex h-(--cell-size) w-full items-center justify-center gap-1.5 text-sm font-medium', defaultClassNames.dropdowns),
                dropdown_root: cn('relative rounded-md', defaultClassNames.dropdown_root),
                caption_label: cn(
                    'font-medium capitalize select-none',
                    captionLayout === 'label'
                        ? 'text-sm'
                        : '[&>svg]:text-muted-foreground flex h-8 items-center gap-1 rounded-md pr-1 pl-2 text-sm [&>svg]:size-3.5',
                    defaultClassNames.caption_label,
                ),
                month_grid: cn('w-full border-collapse', defaultClassNames.month_grid),
                weekdays: cn('flex', defaultClassNames.weekdays),
                weekday: cn('text-muted-foreground flex-1 rounded-md text-[0.8rem] font-normal select-none', defaultClassNames.weekday),
                week: cn('mt-2 flex w-full', defaultClassNames.week),
                week_number_header: cn('w-(--cell-size) select-none', defaultClassNames.week_number_header),
                week_number: cn('text-muted-foreground text-[0.8rem] select-none', defaultClassNames.week_number),
                day: cn(
                    'group/day relative aspect-square h-full w-full p-0 text-center select-none [&:last-child[data-selected=true]_button]:rounded-r-md',
                    props.showWeekNumber
                        ? '[&:nth-child(2)[data-selected=true]_button]:rounded-l-md'
                        : '[&:first-child[data-selected=true]_button]:rounded-l-md',
                    defaultClassNames.day,
                ),
                range_start: cn('bg-accent rounded-l-md', defaultClassNames.range_start),
                range_middle: cn('rounded-none', defaultClassNames.range_middle),
                range_end: cn('bg-accent rounded-r-md', defaultClassNames.range_end),
                today: cn('bg-accent text-accent-foreground rounded-md data-[selected=true]:rounded-none', defaultClassNames.today),
                outside: cn('text-muted-foreground aria-selected:text-muted-foreground', defaultClassNames.outside),
                disabled: cn('text-muted-foreground opacity-50', defaultClassNames.disabled),
                hidden: cn('invisible', defaultClassNames.hidden),
                ...classNames,
            }}
            components={{
                Root: ({ className, rootRef, ...props }) => <div data-slot="calendar" ref={rootRef} className={cn(className)} {...props} />,
                Chevron: ({ className, orientation, ...props }) => {
                    if (orientation === 'left') return <ChevronLeftIcon className={cn('size-4', className)} {...props} />;
                    if (orientation === 'right') return <ChevronRightIcon className={cn('size-4', className)} {...props} />;

                    return <ChevronDownIcon className={cn('size-4', className)} {...props} />;
                },
                Dropdown: CalendarDropdown,
                DayButton: CalendarDayButton,
                WeekNumber: ({ children, ...props }) => (
                    <td {...props}>
                        <div className="flex size-(--cell-size) items-center justify-center text-center">{children}</div>
                    </td>
                ),
                ...components,
            }}
            {...props}
        />
    );
}

/**
 * The month and the year, chosen from a list of our own rather than from the
 * one the browser draws.
 *
 * A native select opens a list as tall as the system pleases: twelve months
 * stand in a neat box and a hundred years run the height of the screen. Both
 * lists are the same box here — twelve rows and no more — so the years are
 * scrolled through rather than hunted down a page.
 */
function CalendarDropdown({ value, onChange, options, className, ...props }: DropdownProps) {
    return (
        <Select
            value={value === undefined ? undefined : String(value)}
            disabled={props.disabled}
            onValueChange={(next) =>
                onChange?.({ target: { value: next } } as React.ChangeEvent<HTMLSelectElement>)
            }
        >
            <SelectTrigger
                aria-label={props['aria-label']}
                className={cn('h-8 w-fit gap-1 border-0 px-2 text-sm font-medium capitalize shadow-none focus:ring-0 focus:ring-offset-0', className)}
            >
                <SelectValue />
            </SelectTrigger>
            {/* Both lists are one box, a third shorter than the twelve months
                would fill: enough to read a run of them at a glance, little
                enough that the box does not stand over the calendar. */}
            <SelectContent className="max-h-[17rem]">
                {options?.map((option) => (
                    <SelectItem key={option.value} value={String(option.value)} disabled={option.disabled} className="capitalize">
                        {option.label}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}

function CalendarDayButton({ className, day, modifiers, ...props }: React.ComponentProps<typeof DayButton>) {
    const defaultClassNames = getDefaultClassNames();

    const ref = React.useRef<HTMLButtonElement>(null);
    React.useEffect(() => {
        if (modifiers.focused) ref.current?.focus();
    }, [modifiers.focused]);

    return (
        <Button
            ref={ref}
            variant="ghost"
            size="icon"
            data-day={day.date.toLocaleDateString()}
            data-selected-single={modifiers.selected && !modifiers.range_start && !modifiers.range_end && !modifiers.range_middle}
            data-range-start={modifiers.range_start}
            data-range-end={modifiers.range_end}
            data-range-middle={modifiers.range_middle}
            className={cn(
                'group-data-[focused=true]/day:border-ring group-data-[focused=true]/day:ring-ring/50 data-[range-end=true]:bg-primary data-[range-end=true]:text-primary-foreground data-[range-middle=true]:bg-accent data-[range-middle=true]:text-accent-foreground data-[range-start=true]:bg-primary data-[range-start=true]:text-primary-foreground data-[selected-single=true]:bg-primary data-[selected-single=true]:text-primary-foreground dark:hover:text-accent-foreground flex aspect-square size-auto w-full min-w-(--cell-size) flex-col gap-1 leading-none font-normal group-data-[focused=true]/day:relative group-data-[focused=true]/day:z-10 group-data-[focused=true]/day:ring-[3px] data-[range-end=true]:rounded-md data-[range-end=true]:rounded-r-md data-[range-middle=true]:rounded-none data-[range-start=true]:rounded-md data-[range-start=true]:rounded-l-md [&>span]:text-xs [&>span]:opacity-70',
                defaultClassNames.day,
                className,
            )}
            {...props}
        />
    );
}

export { Calendar, CalendarDayButton };
