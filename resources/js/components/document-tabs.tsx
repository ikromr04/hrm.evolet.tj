import { cn } from '@/lib/utils';
import { useEffect, useRef, type KeyboardEvent } from 'react';

/**
 * Which document a page is showing, as a real set of tabs: the arrow keys walk
 * them, only the open one is in the tab order, and the one that is open wears a
 * plate and a heavier word, so it is not colour alone that says so.
 *
 * The look is the application's own, from the strip over an employee's profile —
 * but not its machinery: those are links to other addresses. These swap a panel
 * inside one page and are forgotten when it is left.
 *
 * A department and a position both carry «Положение» and «СФД», so the strip is
 * said once here; what each page calls the pair, and what it names its panels,
 * it brings with it.
 */
export function DocumentTabs<T extends string>({
    tabs,
    current,
    onChange,
    label,
    name,
    className,
}: {
    tabs: readonly { key: T; title: string }[];
    current: T;
    onChange: (key: T) => void;
    /** What the strip is, read out before its tabs. */
    label: string;
    /** What the ids of the tabs and their panels are built from. */
    name: string;
    className?: string;
}) {
    const strip = useRef<HTMLDivElement>(null);
    /** Whether the tab that just opened was reached by an arrow key. */
    const walked = useRef(false);

    // The tab an arrow key opens takes the focus with it, which is what the
    // arrow keys are expected to do — but only then, so that pressing one of
    // these, or switching on a phone, does not pull the focus across the page.
    // After the render rather than beside it: before it the tab that is to take
    // the focus is still the one being left.
    useEffect(() => {
        if (!walked.current) return;

        walked.current = false;
        strip.current?.querySelector<HTMLButtonElement>('[aria-selected="true"]')?.focus();
    }, [current]);

    const walk = (event: KeyboardEvent<HTMLDivElement>) => {
        const step = event.key === 'ArrowRight' ? 1 : event.key === 'ArrowLeft' ? -1 : 0;

        if (step === 0) return;

        event.preventDefault();
        walked.current = true;
        const at = tabs.findIndex((tab) => tab.key === current);
        onChange(tabs[(at + step + tabs.length) % tabs.length].key);
    };

    return (
        // No rule runs under the strip: a line across the whole column would be a
        // second edge directly above the card's own, and the card is what the tab
        // opens onto. On a phone the open tab is underlined instead, where a line
        // under a word is how a tab is read.
        <div
            ref={strip}
            role="tablist"
            aria-label={label}
            onKeyDown={walk}
            className={cn('flex overflow-x-auto [scrollbar-width:none] max-md:gap-x-6 md:gap-1 [&::-webkit-scrollbar]:hidden', className)}
        >
            {tabs.map((tab) => {
                const active = tab.key === current;

                return (
                    <button
                        key={tab.key}
                        type="button"
                        role="tab"
                        id={`${name}-tab-${tab.key}`}
                        aria-selected={active}
                        aria-controls={`${name}-panel-${tab.key}`}
                        tabIndex={active ? 0 : -1}
                        onClick={() => onChange(tab.key)}
                        className={cn(
                            'shrink-0 text-sm whitespace-nowrap transition-colors max-md:border-b-2 max-md:px-1 max-md:pt-3 max-md:pb-3.5 max-md:font-medium md:flex md:h-8 md:items-center md:rounded-md md:px-2.5',
                            active
                                ? 'max-md:border-brand max-md:text-foreground md:bg-brand-soft md:text-foreground max-md:font-semibold md:font-semibold md:dark:bg-white/10'
                                : 'max-md:text-muted-foreground md:text-muted-foreground md:hover:bg-accent md:hover:text-foreground max-md:border-transparent',
                        )}
                    >
                        {tab.title}
                    </button>
                );
            })}
        </div>
    );
}
