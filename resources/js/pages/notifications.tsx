import { ComingSoon } from '@/components/coming-soon';
import { PageContent } from '@/components/page-content';
import { PageTitle } from '@/components/page-title';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Уведомления', href: '/notifications' }];

/**
 * «Уведомления» as it stands: a door with nothing behind it yet.
 *
 * The bell in the bar and the entry in the menu both lead here, so the section
 * is where it will be when it is built; until then the page says so rather
 * than showing an empty list, which would read as one that failed to load.
 */
export default function Notifications() {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Уведомления" />

            <PageContent>
                <PageTitle>Уведомления</PageTitle>

                <ComingSoon what="Уведомления" />
            </PageContent>
        </AppLayout>
    );
}
