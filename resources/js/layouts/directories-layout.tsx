import { PageActionsProvider } from '@/components/page-actions';
import AppLayout from '@/layouts/app-layout';
import { directoryLists, useCan } from '@/lib/access';
import { cn } from '@/lib/utils';
import { type SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import { useEffect, useRef, type ReactNode } from 'react';

/** Shell for the admin directories: title, tabs, then the current list. */
export default function DirectoriesLayout({ title, children }: { title: string; children: ReactNode }) {
    const { url } = usePage<SharedData>();
    const can = useCan();

    // Each list is a right of its own, «Доступы» included, so the strip carries
    // only the ones this person may open — a tab that led to a refusal would be
    // worse than no tab.
    const tabs = directoryLists.filter((list) => can(list.view)).map((list) => ({ title: list.title, href: `/directories/${list.key}` }));

    // On a phone a later tab may start off-screen: slide the strip, and only
    // the strip, so the open one is in view. The page itself is not scrolled.
    const activeTab = useRef<HTMLAnchorElement>(null);
    useEffect(() => {
        const link = activeTab.current;
        const strip = link?.closest('nav');
        if (!link || !strip) return;

        const from = strip.getBoundingClientRect();
        const to = link.getBoundingClientRect();
        strip.scrollLeft += to.left - from.left - (from.width - to.width) / 2;
    }, [url]);

    return (
        // The bar names the section and the strip below it names the open list;
        // on a phone it is the other way round, the bar naming the list and the
        // chips under it the section.
        <AppLayout title="Справочники" fitViewport>
            <Head title={`${title} — справочники`} />

            <PageActionsProvider>
                {(actions) => (
                    <div className="flex flex-1 flex-col gap-4 p-3 max-md:gap-3 md:min-h-0 md:gap-2 md:p-4">
                        {/*
                         * One tab is not a choice: with a single list open the strip only
                         * repeats the heading and the breadcrumb, so it is left out and
                         * the list starts right under the title.
                         */}
                        {/*
                         * The lists are switched the way the staff lists are switched
                         * on the employees page: the open one is a soft brand chip
                         * and the rest are plain words that shade in under the
                         * cursor. The two strips do the same thing a page apart, so
                         * they are drawn the same. On a narrow screen the strip
                         * scrolls sideways rather than wrapping into rows, and there
                         * the tabs are rounder and further apart, the way a mobile
                         * app switches between lists.
                         */}
                        <div className="flex shrink-0 items-center gap-2">
                            {tabs.length > 1 && (
                                <nav
                                    aria-label="Справочники"
                                    className="-mx-3 min-w-0 flex-1 overflow-x-auto px-3 [scrollbar-width:none] md:mx-0 md:px-0 [&::-webkit-scrollbar]:hidden"
                                >
                                    <div className="flex w-max min-w-full gap-2 md:h-8 md:gap-1">
                                        {tabs.map((tab) => {
                                            const active = url.startsWith(tab.href);

                                            return (
                                                <Link
                                                    key={tab.href}
                                                    href={tab.href}
                                                    prefetch
                                                    aria-current={active ? 'page' : undefined}
                                                    ref={active ? activeTab : undefined}
                                                    className={cn(
                                                        'text-sm transition-colors max-md:flex max-md:h-8 max-md:shrink-0 max-md:items-center max-md:rounded-full max-md:px-3 max-md:font-medium max-md:whitespace-nowrap md:inline-flex md:h-full md:items-center md:justify-center md:rounded-md md:px-2.5 md:whitespace-nowrap',
                                                        active
                                                            ? 'max-md:bg-brand-soft max-md:text-foreground md:bg-brand-soft md:text-foreground max-md:font-semibold md:font-semibold max-md:dark:bg-white/10 md:dark:bg-white/10'
                                                            : 'max-md:bg-card max-md:text-foreground md:text-muted-foreground md:hover:bg-accent md:hover:text-foreground',
                                                    )}
                                                >
                                                    {tab.title}
                                                </Link>
                                            );
                                        })}
                                    </div>
                                </nav>
                            )}

                            {/* The open list hangs its own button here, at the end of the line. */}
                            {actions}
                        </div>

                        {children}
                    </div>
                )}
            </PageActionsProvider>
        </AppLayout>
    );
}
