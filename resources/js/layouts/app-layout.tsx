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
    /**
     * Where the trail is drawn. Over the page by default, under the bar.
     * «bar» puts it in the bar, where the heading would be, for a page whose
     * first line is already its own name — a person's card. «none» draws it
     * nowhere, for a page that still names its crumbs for the phone, which
     * reads them as the name of the page and the way back from it.
     */
    trail?: 'page' | 'bar' | 'none';
}

export default function AppLayout({ children, breadcrumbs, title, ...props }: AppLayoutProps) {
    return (
        <AppLayoutTemplate breadcrumbs={breadcrumbs} title={title} {...props}>
            {children}
        </AppLayoutTemplate>
    );
}
