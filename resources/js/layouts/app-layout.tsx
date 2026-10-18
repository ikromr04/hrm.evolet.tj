import AppLayoutTemplate from '@/layouts/app/app-sidebar-layout';
import { type BreadcrumbItem } from '@/types';
import { type ReactNode } from 'react';

interface AppLayoutProps {
    children: ReactNode;
    breadcrumbs?: BreadcrumbItem[];
    /**
     * What the bar calls this page. Left out, it calls it by the last of the
     * crumbs; a page says it when its own name is fuller than that crumb, as a
     * department's is, or when it has no trail at all, as the main page has not.
     */
    title?: ReactNode;
    fitViewport?: boolean;
}

export default function AppLayout({ children, breadcrumbs, title, ...props }: AppLayoutProps) {
    return (
        <AppLayoutTemplate breadcrumbs={breadcrumbs} title={title} {...props}>
            {children}
        </AppLayoutTemplate>
    );
}
