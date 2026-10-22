import { PageActionsProvider } from '@/components/page-actions';
import AppLayout from '@/layouts/app-layout';
import { companySections } from '@/lib/access';
import { useCompanyTabs } from '@/lib/company-section';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import { useEffect, useRef, type ReactNode } from 'react';

/** Shell for the company structure: title, tabs, then the tab that is open. */
export default function CompanyStructureLayout({ children }: { children: ReactNode }) {
    const { url } = usePage();
    const tabs = useCompanyTabs();

    // The chart's address is the section's own, and so a prefix of the units'
    // one: the open tab is the one whose address this is, not the one it starts
    // with. A department's own page is under the section without being a tab of
    // it, and leaves the chart standing.
    const path = url.split('?')[0];
    const current = tabs.find((section) => section.href === path) ?? companySections[0];

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Структура компании', href: companySections[0].href },
        { title: current.title, href: url },
    ];

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
        // The bar says the trail — the section and the open tab — and the strip
        // below it is where the tab is switched. The phone's bar reads the same
        // crumbs the other way round: there the bar names the tab, because the
        // chips under it name the section.
        <AppLayout breadcrumbs={breadcrumbs} fitViewport>
            <Head title={`${current.title} — структура компании`} />

            <PageActionsProvider>
                {(actions) => (
                    <div className="flex flex-1 flex-col gap-4 p-3 max-md:gap-3 md:min-h-0 md:gap-2 md:px-4 md:py-4 lg:px-6">
                        {/*
                         * One tab is not a choice: with the chart alone open the strip
                         * only repeats the heading and the breadcrumb, so it is left out.
                         */}
                        {/*
                         * The tabs of the directories, because they do the same thing:
                         * the open one is a soft brand chip and the rest are plain
                         * words that shade in under the cursor. On a narrow screen the
                         * strip scrolls sideways rather than wrapping into rows, and
                         * there the chips are rounder and further apart, the way a
                         * mobile app switches between lists.
                         */}
                        <div className="flex shrink-0 items-center gap-2">
                            {tabs.length > 1 && (
                                <nav
                                    aria-label="Структура компании"
                                    className="-mx-3 min-w-0 flex-1 overflow-x-auto px-3 [scrollbar-width:none] md:mx-0 md:px-0 [&::-webkit-scrollbar]:hidden"
                                >
                                    <div className="flex w-max min-w-full gap-2 md:h-8 md:gap-1">
                                        {tabs.map((tab) => {
                                            const active = tab.key === current.key;

                                            return (
                                                <Link
                                                    key={tab.key}
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

                            {/* The open tab hangs its own buttons here, at the end of the line. */}
                            {actions}
                        </div>

                        {children}
                    </div>
                )}
            </PageActionsProvider>
        </AppLayout>
    );
}
