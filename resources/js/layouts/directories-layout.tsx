import AppLayout from '@/layouts/app-layout';
import { directoryLists, useCan } from '@/lib/access';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import { type ReactNode, useEffect, useRef } from 'react';

/** Shell for the admin directories: title, tabs, then the current list. */
export default function DirectoriesLayout({ title, children }: { title: string; children: ReactNode }) {
    const { url } = usePage<SharedData>();
    const can = useCan();

    // Each list is a right of its own, «Доступы» included, so the strip carries
    // only the ones this person may open — a tab that led to a refusal would be
    // worse than no tab.
    const tabs = directoryLists.filter((list) => can(list.view)).map((list) => ({ title: list.title, href: `/directories/${list.key}` }));

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Справочники', href: '/directories' },
        { title, href: url },
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
        // The bar names the section and the strip below it names the open list;
        // on a phone it is the other way round, the bar naming the list and the
        // chips under it the section.
        <AppLayout breadcrumbs={breadcrumbs} title="Справочники" fitViewport>
            <Head title={`${title} — справочники`} />

            <div className="flex flex-1 flex-col gap-4 p-3 max-md:gap-3 md:min-h-0 md:p-4">
                {/*
                 * One tab is not a choice: with a single list open the strip only
                 * repeats the heading and the breadcrumb, so it is left out and
                 * the list starts right under the title.
                 */}
                {/*
                 * On a narrow screen the strip scrolls sideways rather than
                 * wrapping into rows: the underline has to stay one line. The
                 * border sits on the inner row, inside the scrolling box, so the
                 * active tab's underline can overlap it without spilling out.
                 * On a phone the tabs are pills instead, the way a mobile app
                 * switches between lists: a 2px underline is a poor target for a
                 * thumb and hard to spot at a glance, a filled chip is neither.
                 */}
                {tabs.length > 1 && (
                    <nav
                        aria-label="Справочники"
                        className="-mx-3 shrink-0 overflow-x-auto px-3 [scrollbar-width:none] md:mx-0 md:px-0 [&::-webkit-scrollbar]:hidden"
                    >
                        <div className="flex w-max min-w-full gap-2 md:gap-6 md:border-b">
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
                                            'text-sm transition-colors max-md:flex max-md:h-8 max-md:shrink-0 max-md:items-center max-md:rounded-full max-md:px-3 max-md:font-medium max-md:whitespace-nowrap md:-mb-px md:border-b-2 md:px-1 md:pb-2.5',
                                            active
                                                ? 'max-md:bg-brand-soft max-md:text-foreground md:border-brand md:text-foreground max-md:font-semibold md:font-semibold max-md:dark:bg-white/10'
                                                : 'max-md:bg-card max-md:text-foreground md:text-muted-foreground md:hover:text-foreground md:border-transparent md:font-medium',
                                        )}
                                    >
                                        {tab.title}
                                    </Link>
                                );
                            })}
                        </div>
                    </nav>
                )}

                {children}
            </div>
        </AppLayout>
    );
}
