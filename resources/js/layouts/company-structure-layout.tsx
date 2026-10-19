import { PageActionsProvider } from '@/components/page-actions';
import AppLayout from '@/layouts/app-layout';
import { companySections, type CompanySection } from '@/lib/access';
import { rememberSection, savedSection, useCompanyTabs } from '@/lib/company-section';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useEffect, useRef, type ReactNode } from 'react';

/**
 * Sends a bare visit on to the tab the viewer was last on, which is how
 * `useRememberedQuery` carries a list's filters between visits — the same shape,
 * because this is the same promise: ask for the section and find the page you
 * left.
 *
 * This is the long way round, and it is here for whoever arrives at the bare
 * address by a typed url, a bookmark or a link from elsewhere: the chart is
 * drawn first and swapped out a moment later. The menu does not come this way —
 * its entry carries the remembered address itself, so a press is one visit.
 *
 * Only the chart shares an address with the section itself, so only a visit to
 * it can have named nothing; the other two tabs name themselves and a link that
 * names something wins. A press on "Органограмма" names it by writing it down on
 * the way, so arriving at the shared address with the chart remembered is a
 * visit that asked for the chart and stays on it.
 *
 * A tab the viewer may no longer open is nowhere to send anybody: it is not
 * among the ones offered, so the chart stands and is written down in its place.
 */
function useRememberedSection(current: CompanySection, offered: CompanySection[]) {
    const { url } = usePage();
    const restored = useRef(false);
    // The strip is built afresh on every render; the visit below reads the
    // latest one rather than running again because the array is new.
    const latest = useRef(offered);

    useEffect(() => {
        latest.current = offered;
    });

    useEffect(() => {
        if (!restored.current && current.key === companySections[0].key && !url.includes('?')) {
            const saved = savedSection(latest.current);
            const target = saved?.key === current.key ? null : saved;

            if (target) {
                // On a first load the router is set up only after the page's own
                // effects have run, so the visit waits a tick for it.
                const timer = setTimeout(() => {
                    restored.current = true;
                    router.get(target.href, {}, { preserveScroll: true, replace: true });
                });

                return () => clearTimeout(timer);
            }
        }

        restored.current = true;
        rememberSection(current.key);
    }, [current, url]);
}

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

    useRememberedSection(current, tabs);

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
        // The bar names the section and the strip below it names the open tab,
        // which is what the phone's bar has always done the other way round:
        // there the bar names the tab, because the chips under it name the
        // section.
        //
        // The trail itself is drawn nowhere: on a desktop the bar and the strip
        // together already say where this is, and a third line saying it again
        // only pushed the page down. The crumbs are still named, because the
        // phone's bar reads them — the open tab for its name, the section for
        // the arrow back.
        <AppLayout breadcrumbs={breadcrumbs} title="Структура компании" trail="none" fitViewport>
            <Head title={`${current.title} — структура компании`} />

            <PageActionsProvider>
                {(actions) => (
                    <div className="flex flex-1 flex-col gap-4 p-3 max-md:gap-3 md:min-h-0 md:gap-2 md:p-4">
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
                                                    // Written down before the visit, so that the
                                                    // tab whose address is the section's own is
                                                    // asked for rather than passed through.
                                                    onClick={() => rememberSection(tab.key)}
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
