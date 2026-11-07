import { Breadcrumbs } from '@/components/breadcrumbs';
import { GlobalSearch } from '@/components/global-search';
import { ThemeToggle } from '@/components/theme-toggle';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { SidebarTrigger, useSidebar } from '@/components/ui/sidebar';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { plural } from '@/lib/plural';
import { type BreadcrumbItem as BreadcrumbItemType, type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';
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
/**
 * The button the menu opens and closes by, and the hint that says it can be
 * done from the keyboard instead.
 *
 * The hint names the shortcut rather than only the button's job: somebody who
 * reaches for the mouse a dozen times a day is exactly the person who should be
 * told there is a key for it, and the only moment they are looking at the
 * button is while the hint is up. The key is written the way the machine writes
 * it — ⌘ on a Mac, Ctrl everywhere else — and in the same little box the search
 * writes its own shortcut in, so the two read as one habit.
 *
 * On a phone the menu is a drawer and there is no keyboard to press, so the
 * hint says what the button does and stops there.
 */
function MenuButton() {
    const { state, isMobile } = useSidebar();
    const shuts = !isMobile && state === 'expanded';

    return (
        <TooltipProvider delayDuration={400}>
            <Tooltip>
                <TooltipTrigger asChild>
                    <SidebarTrigger className="-ml-1" aria-label="Меню" />
                </TooltipTrigger>
                <TooltipContent side="bottom" className="flex items-center gap-2 py-1 text-[13px]">
                    {isMobile ? 'Меню' : shuts ? 'Свернуть меню' : 'Развернуть меню'}
                    {!isMobile && (
                        <kbd className="border-border bg-muted rounded border px-[5px] py-px font-sans text-[11px] font-medium">
                            {onApple() ? '⌘ B' : 'Ctrl B'}
                        </kbd>
                    )}
                </TooltipContent>
            </Tooltip>
        </TooltipProvider>
    );
}

/** Whether this is a board whose own key is ⌘ rather than Ctrl. */
function onApple() {
    return typeof navigator !== 'undefined' && /Mac|iPhone|iPad/.test(navigator.platform || navigator.userAgent);
}

/**
 * The bell, and the number of notes waiting behind it.
 *
 * The number is the whole reason the bell is in the bar rather than only in the
 * menu: somebody who is reading a card has to be able to tell at a glance that
 * the office has answered their request, without going looking for a page that
 * might have nothing on it. So the figure rides on the button and the button
 * leads to the list.
 *
 * Nothing at all when nothing is unread. A nought in a circle is a mark that
 * reads as news from across the room and turns out to be none, and a bar that
 * cries wolf once stops being looked at.
 *
 * The figure sits over the top right corner rather than beside the drawing: the
 * bar is 48px and the button is 36px of it, so there is no width to give a
 * second thing and six points of room above the button for the mark to hang
 * into. Past ninety-nine it stops counting — three figures do not fit, and
 * «ninety-nine and more» is the same news as «a hundred and four».
 *
 * The count is read off the shared props, which hold it for every page: it is
 * one small query per visit, so the mark is as fresh as the page under it
 * without anything on this side asking for it.
 */
function NotificationBell() {
    const { auth } = usePage<SharedData>().props;
    const unread = auth.notifications ?? 0;

    return (
        <Button variant="outline" size="icon" asChild className="relative size-9 shrink-0">
            <Link
                href={route('notifications.index')}
                prefetch
                aria-label={
                    unread > 0 ? `Уведомления: ${unread} ${plural(unread, ['непрочитанное', 'непрочитанных', 'непрочитанных'])}` : 'Уведомления'
                }
                title="Уведомления"
            >
                <Bell />

                {unread > 0 && (
                    // Said in the label above, so the mark itself is a drawing:
                    // read out, the button would otherwise count twice.
                    <span
                        aria-hidden="true"
                        className="bg-brand-strong absolute -top-1 -right-1 flex h-4 min-w-4 items-center justify-center rounded-full px-1 text-[10px] leading-none font-semibold text-white tabular-nums dark:bg-[#C5E27A] dark:text-[#1B2309]"
                    >
                        {unread > 99 ? '99+' : unread}
                    </span>
                )}
            </Link>
        </Button>
    );
}

export function AppSidebarHeader({ breadcrumbs = [], title }: { breadcrumbs?: BreadcrumbItemType[]; title?: ReactNode }) {
    // A page with no trail at all still has a name, and it is better than the
    // product's.
    const crumbTitle = breadcrumbs[breadcrumbs.length - 1]?.title ?? (typeof title === 'string' ? title : 'Evolet HRM');
    const heading = title ?? crumbTitle;
    // Nothing to put there but the heading if the page has no trail to show.
    const trail = breadcrumbs.length > 0;

    return (
        <header className="flex h-12 shrink-0 items-center gap-2 border-b px-2 md:px-4 lg:px-6">
            <MenuButton />
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

            <NotificationBell />
        </header>
    );
}
