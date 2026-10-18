import { GlobalSearch } from '@/components/global-search';
import { NotificationsBell } from '@/components/notifications-bell';
import { ThemeToggle } from '@/components/theme-toggle';
import { Separator } from '@/components/ui/separator';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { type BreadcrumbItem as BreadcrumbItemType } from '@/types';
import { Link } from '@inertiajs/react';
import { ChevronLeft } from 'lucide-react';
import { type ReactNode } from 'react';

/**
 * The bar names the page. The trail used to stand here and every page drew a
 * heading of its own below it; it is the other way round now, so there is one
 * heading on screen and the bar says which page this is whatever the page under
 * it is made of.
 *
 * A page that does not say what to call it is called by the last of its crumbs,
 * which is already the name it goes by everywhere it is linked from.
 */
export function AppSidebarHeader({ breadcrumbs = [], title }: { breadcrumbs?: BreadcrumbItemType[]; title?: ReactNode }) {
    // A phone has the tab bar for the sections, so its bar is an app's: a way
    // back to the parent page, the name of this one and two icons.
    const parent = breadcrumbs.length > 1 ? breadcrumbs[breadcrumbs.length - 2] : null;
    // A page with no trail at all — the main page has none — still has a name,
    // and on a phone that name is better than the product's.
    const crumbTitle = breadcrumbs[breadcrumbs.length - 1]?.title ?? (typeof title === 'string' ? title : 'Evolet HRM');
    const heading = title ?? crumbTitle;

    return (
        <header className="max-md:bg-background/90 max-md:supports-[backdrop-filter]:bg-background/75 flex h-12 shrink-0 items-center gap-1 border-b px-2 max-md:sticky max-md:top-0 max-md:z-30 max-md:pt-[env(safe-area-inset-top)] max-md:backdrop-blur-xl md:h-13 md:gap-2 md:p-2">
            <SidebarTrigger className="size-9 max-md:hidden" aria-label="Свернуть меню" />
            <Separator orientation="vertical" className="h-4! max-md:hidden" />

            {parent ? (
                <Link
                    href={parent.href}
                    prefetch
                    className="text-brand-strong active:bg-accent -ml-1 flex size-10 shrink-0 items-center justify-center rounded-full md:hidden dark:text-[#C5E27A]"
                    aria-label={`Назад: ${parent.title}`}
                >
                    <ChevronLeft className="size-6" />
                </Link>
            ) : (
                <span className="w-2 shrink-0 md:hidden" />
            )}

            {/*
             * One heading, and the width decides which words it carries. A page
             * may call itself something fuller than its crumb — a department's
             * whole name where the trail has its initials — and that fuller name
             * is for the desktop bar, which has the room for it. The phone bar
             * keeps the short name it has always shown: it is 390px wide and
             * stands next to a back arrow, so it is a label on a way back as
             * much as a title.
             */}
            <h1
                title={typeof heading === 'string' ? heading : undefined}
                className="min-w-0 flex-1 truncate font-semibold max-md:text-[17px] max-md:tracking-tight md:px-2.5 md:text-xl md:leading-7 md:tracking-[-0.01em]"
            >
                <span className="md:hidden">{crumbTitle}</span>
                <span className="max-md:hidden">{heading}</span>
            </h1>

            <GlobalSearch />

            <div className="max-md:hidden">
                <ThemeToggle />
            </div>
            <NotificationsBell />
        </header>
    );
}
