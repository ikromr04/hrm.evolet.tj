import { ComingSoon } from '@/components/coming-soon';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Главная', href: '/' }];

/**
 * «Главная» as the design has it: the page writes its own name, and under it
 * says that it is still being drawn.
 *
 * The page it will be is drawn already — the counts of the company, the staff
 * of each department, the fleet and the last operations with it — and the
 * server still answers with every one of those figures. It is held back until
 * the drawing is settled, so the page says so rather than showing numbers
 * nobody has agreed on yet.
 */
export default function Dashboard() {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Главная" />

            <div className="flex flex-1 flex-col gap-4 p-3 max-md:gap-3 md:gap-6 md:px-4 md:py-6 lg:px-6">
                <h1 className="text-xl leading-7 font-semibold tracking-[-0.01em] max-md:hidden">Главная</h1>

                <ComingSoon what="Главная" />
            </div>
        </AppLayout>
    );
}
