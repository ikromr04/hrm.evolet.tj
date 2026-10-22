import AppLayoutTemplate from '@/layouts/app/app-sidebar-layout';
import { type BreadcrumbItem } from '@/types';
import { type ReactNode } from 'react';

interface AppLayoutProps {
    children: ReactNode;
    breadcrumbs?: BreadcrumbItem[];
    /**
     * What the bar calls this page where it has no trail to stand there — an
     * error page, which sits nowhere in the system. A page with crumbs is said
     * by them; the phone, which shows no trail, calls it by the last of them.
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
