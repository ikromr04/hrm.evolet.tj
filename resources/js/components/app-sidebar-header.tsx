import { Breadcrumbs } from '@/components/breadcrumbs';
import { GlobalSearch } from '@/components/global-search';
import { ThemeToggle } from '@/components/theme-toggle';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { type BreadcrumbItem as BreadcrumbItemType } from '@/types';
import { Bell } from 'lucide-react';
import { type ReactNode } from 'react';

/**
 * The bar says where this page sits: the button that opens the menu, the trail,
 * and the search and the two icons at the end of it.
 *
 * One bar at every width, as the design draws it — a phone opens the same menu
 * as a drawer over the page rather than carrying a bar of tabs of its own at
 * the foot of the screen.
 *
 * A page with no trail — an error page, which sits nowhere — is named instead,
 * so the line is never empty. On a phone the trail is its last step alone: the
 * whole way down does not fit across 390px, and the step somebody is on is the
 * one worth saying.
 */
export function AppSidebarHeader({ breadcrumbs = [], title }: { breadcrumbs?: BreadcrumbItemType[]; title?: ReactNode }) {
    // A page with no trail at all still has a name, and it is better than the
    // product's.
    const crumbTitle = breadcrumbs[breadcrumbs.length - 1]?.title ?? (typeof title === 'string' ? title : 'Evolet HRM');
    const heading = title ?? crumbTitle;
    // Nothing to put there but the heading if the page has no trail to show.
    const trail = breadcrumbs.length > 0;

    return (
        <header className="flex h-12 shrink-0 items-center gap-2 border-b px-2 md:px-4 lg:px-6">
            <SidebarTrigger className="-ml-1" aria-label="Меню" />
            <Separator orientation="vertical" className="mx-2 h-4!" />

            <div className="min-w-0 flex-1">
                {trail ? (
                    <Breadcrumbs breadcrumbs={breadcrumbs} />
                ) : (
                    <h1 title={typeof heading === 'string' ? heading : undefined} className="truncate text-base font-medium">
                        {heading}
                    </h1>
                )}
            </div>

            <GlobalSearch />
            <ThemeToggle />

            {/* The bell keeps its place in the bar and nothing behind it: the
                section is put aside, and a door that opens on «скоро» is worse
                than one plainly shut. */}
            <Button variant="outline" size="icon" disabled className="size-9 shrink-0" aria-label="Уведомления — скоро" title="Уведомления — скоро">
                <Bell />
            </Button>
        </header>
    );
}
