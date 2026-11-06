import { PageContent } from '@/components/page-content';
import { PageTitle } from '@/components/page-title';
import AppLayout from '@/layouts/app-layout';
import { directoryLists, useCan } from '@/lib/access';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, usePage } from '@inertiajs/react';
import { type ReactNode } from 'react';

/**
 * Shell for the admin directories, built the way every other page of the
 * application is: the name of the page, whatever acts on it, and the list.
 *
 * The page is called after the list that is open — «Документы (Чек-лист)»,
 * «Категории оборудования» — rather than after the section they belong to. It used
 * to be the other way round, with «Справочники» over a strip of tabs, and the
 * section is now said three times without it: by the menu, which holds the
 * lists one under another, by the trail above the page, and by the window's own
 * title.
 */
export default function DirectoriesLayout({ title, children }: { title: string; children: ReactNode }) {
    const { url } = usePage<SharedData>();
    const can = useCan();

    // The way back from a list is to the section, which has no page of its own:
    // its first step is the first list this person may open.
    const first = directoryLists.find((list) => can(list.view));

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Справочники', href: first === undefined ? url : `/directories/${first.key}` },
        { title, href: url },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs} fitViewport>
            <Head title={`${title} — справочники`} />

            {/* The list fills the height of the page and scrolls inside
                itself, as the staff list and the fleet do. */}
            <PageContent className="md:min-h-0">
                <PageTitle>{title}</PageTitle>

                {children}
            </PageContent>
        </AppLayout>
    );
}
