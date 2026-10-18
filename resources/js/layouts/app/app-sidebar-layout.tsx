import { AppContent } from '@/components/app-content';
import { AppShell } from '@/components/app-shell';
import { AppSidebar } from '@/components/app-sidebar';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import { Breadcrumbs, rootCrumb } from '@/components/breadcrumbs';
import { FlashNotice } from '@/components/flash-notice';
import { MobileTabBar } from '@/components/mobile-tab-bar';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { type ReactNode } from 'react';

interface AppSidebarLayoutProps {
    children: ReactNode;
    breadcrumbs?: BreadcrumbItem[];
    /** What the bar calls the page; the last crumb when the page does not say. */
    title?: ReactNode;
    /** Lock the content to the window height on desktop so the page itself never scrolls; the page scrolls its own panes. */
    fitViewport?: boolean;
}

export default function AppSidebarLayout({ children, breadcrumbs = [], title, fitViewport = false }: AppSidebarLayoutProps) {
    return (
        <AppShell variant="sidebar">
            <AppSidebar />
            {/* On a phone the tab bar sits over the bottom of the page; the padding keeps the last row clear of it. */}
            <AppContent
                variant="sidebar"
                className={cn(
                    'max-md:bg-muted/40 max-md:pb-[calc(3.5rem+env(safe-area-inset-bottom))]',
                    fitViewport && 'md:h-svh md:overflow-hidden',
                )}
            >
                <AppSidebarHeader breadcrumbs={breadcrumbs} title={title} />
                <FlashNotice />

                {/*
                 * The trail opens the page now that the bar holds the heading.
                 * Only its top padding is here: the page below brings its own,
                 * and the two together are the 16px that separates every pair of
                 * blocks on the page.
                 *
                 * A phone has no room for a trail and never showed one — there
                 * the bar is a way back to the parent instead.
                 */}
                {breadcrumbs.length > 0 && (
                    <div className="shrink-0 px-4 pt-4 max-md:hidden">
                        <Breadcrumbs breadcrumbs={[rootCrumb, ...breadcrumbs]} />
                    </div>
                )}

                {children}
            </AppContent>
            <MobileTabBar />
        </AppShell>
    );
}
