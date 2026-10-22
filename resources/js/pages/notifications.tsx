import { ComingSoon } from '@/components/coming-soon';
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

            <div className="flex flex-1 flex-col gap-4 p-3 max-md:gap-3 md:gap-6 md:px-4 md:py-6 lg:px-6">
                <h1 className="text-xl leading-7 font-semibold tracking-[-0.01em] max-md:hidden">Уведомления</h1>

                <ComingSoon what="Уведомления" />
            </div>
        </AppLayout>
    );
}
