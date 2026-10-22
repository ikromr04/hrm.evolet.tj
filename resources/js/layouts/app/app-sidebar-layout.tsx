import { AppContent } from '@/components/app-content';
import { AppShell } from '@/components/app-shell';
import { AppSidebar } from '@/components/app-sidebar';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import { FlashNotice } from '@/components/flash-notice';
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
            {/* One shell at every width: the menu is a drawer on a phone, opened
                from the same button the desktop collapses it with, so the foot of
                the screen belongs to the page. */}
            <AppContent
                variant="sidebar"
                className={cn('max-md:bg-muted/40', fitViewport && 'md:h-[calc(100svh-(--spacing(4)))] md:overflow-hidden')}
            >
                {/* The trail stands in the bar, so the page starts with the page. */}
                <AppSidebarHeader breadcrumbs={breadcrumbs} title={title} />
                <FlashNotice />

                {children}
            </AppContent>
        </AppShell>
    );
}
