import { Breadcrumb, BreadcrumbItem, BreadcrumbLink, BreadcrumbList, BreadcrumbPage, BreadcrumbSeparator } from '@/components/ui/breadcrumb';
import { type BreadcrumbItem as BreadcrumbItemType } from '@/types';
import { Fragment } from 'react';

/** Where every trail starts; a page names only its own way down from here. */
export const rootCrumb: BreadcrumbItemType = { title: 'Evolet HRM', href: '/dashboard' };

/**
 * The trail at the top of the page. It used to share the bar with the menu, the
 * search and two buttons, and had to drop its own middle to fit a 56px line;
 * now the bar carries the heading instead and the trail has the width of the
 * page to itself, so it says the whole way down and wraps if it must.
 */
export function Breadcrumbs({ breadcrumbs }: { breadcrumbs: BreadcrumbItemType[] }) {
    if (breadcrumbs.length === 0) return null;

    return (
        <Breadcrumb>
            <BreadcrumbList>
                {breadcrumbs.map((item, index) => {
                    const isLast = index === breadcrumbs.length - 1;

                    return (
                        <Fragment key={index}>
                            <BreadcrumbItem>
                                {isLast ? (
                                    <BreadcrumbPage className="font-medium">{item.title}</BreadcrumbPage>
                                ) : (
                                    <BreadcrumbLink href={item.href}>{item.title}</BreadcrumbLink>
                                )}
                            </BreadcrumbItem>
                            {!isLast && <BreadcrumbSeparator />}
                        </Fragment>
                    );
                })}
            </BreadcrumbList>
        </Breadcrumb>
    );
}
