import { cn } from '@/lib/utils';
import { useEffect, useRef } from 'react';

/** One entry: what the part is called, and a word about it for a tooltip. */
export interface SectionNavItem {
    title: string;
    note?: string;
}

/**
 * The column down the left of a long page: what the page is made of, and which
 * part of it is being read.
 *
 * Numbered, because that is what makes it this and not the application's menu —
 * the numbers stand in a column of their own and the eye runs down them. The
 * open one wears the pale brand green the menu marks the open page with, so one
 * application has one way of saying «вы здесь».
 *
 * It says where you are on the page, not how far through something you are:
 * pressing an entry scrolls to that part, and the mark follows the scrolling
 * back. So nothing here is «passed» — a section somebody has scrolled by is not
 * a section they have finished — and the open one is `aria-current="location"`,
 * a place in the page rather than a step in a sequence.
 *
 * Below `lg` the column lies down into a strip that scrolls sideways, and the
 * marked entry is brought into view — on a phone the fourth section would
 * otherwise be marked out of sight.
 */
export function SectionNav({
    label,
    items,
    current,
    onSelect,
    className,
}: {
    /**
     * What the list is, for whoever cannot see that it is one: it names the
     * nav for a screen reader and is not drawn. The parts themselves say what
     * the page holds, and a word over them only repeated the obvious.
     */
    label: string;
    items: readonly SectionNavItem[];
    current: number;
    /** Left out where the entries are a list to read rather than a way in. */
    onSelect?: (index: number) => void;
    className?: string;
}) {
    const strip = useRef<HTMLOListElement>(null);

    useEffect(() => {
        const list = strip.current;
        const item = list?.children[current] as HTMLElement | undefined;

        if (!list || !item || list.scrollWidth <= list.clientWidth) return;

        list.scrollTo({ left: item.offsetLeft - list.offsetLeft - 8, behavior: 'smooth' });
    }, [current]);

    return (
        <nav aria-label={label} className={cn('lg:sticky lg:top-6', className)}>
            <ol
                ref={strip}
                className="flex gap-0.5 overflow-x-auto [scrollbar-width:none] lg:flex-col lg:overflow-visible [&::-webkit-scrollbar]:hidden"
            >
                {items.map((item, index) => {
                    const open = index === current;

                    const inside = (
                        <>
                            <span
                                className={cn(
                                    'font-mono text-sm tabular-nums',
                                    open ? 'text-brand-strong dark:text-[#C5E27A]' : 'text-muted-foreground',
                                )}
                            >
                                {String(index + 1).padStart(2, '0')}
                            </span>
                            <span className="min-w-0 lg:truncate">{item.title}</span>
                        </>
                    );

                    const shape = cn(
                        // The figure and the name are one size, so centring them
                        // puts them on one line and no coaxing is needed.
                        'flex h-9 w-full items-center gap-2.5 rounded-lg px-2.5 text-left text-sm whitespace-nowrap transition-colors',
                        open ? 'bg-brand-soft text-foreground font-medium dark:bg-[#A8CF45]/15' : 'text-muted-foreground',
                    );

                    return (
                        <li key={item.title} className="shrink-0">
                            {onSelect === undefined ? (
                                // Nothing to press: the entries say what the page
                                // holds and the page is read by scrolling it.
                                <span className={shape} title={item.note}>
                                    {inside}
                                </span>
                            ) : (
                                <button
                                    type="button"
                                    aria-current={open ? 'location' : undefined}
                                    onClick={() => onSelect(index)}
                                    title={item.note}
                                    className={cn(shape, !open && 'hover:bg-accent hover:text-foreground')}
                                >
                                    {inside}
                                </button>
                            )}
                        </li>
                    );
                })}
            </ol>
        </nav>
    );
}
