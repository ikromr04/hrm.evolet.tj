import { Breadcrumb, BreadcrumbItem, BreadcrumbLink, BreadcrumbList, BreadcrumbPage, BreadcrumbSeparator } from '@/components/ui/breadcrumb';
import { type BreadcrumbItem as BreadcrumbItemType } from '@/types';
import { Fragment } from 'react';

/** The product, where every trail starts, as the design has it. */
const HOME: BreadcrumbItemType = { title: 'Evolet HRM', href: '/' };

/**
 * The trail, which stands in the bar and says where the page sits. It shares
 * that line with the menu button, the search and two icons, so it keeps to one
 * line: the steps never wrap, and the page's own name — the last of them, and
 * the one that can be as long as a department's — gives way with an ellipsis.
 *
 * It opens on the product and ends on this page. A phone is given the last step
 * alone: four steps do not fit across 390px, and the one somebody is on is the
 * one worth saying there.
 */
export function Breadcrumbs({ breadcrumbs }: { breadcrumbs: BreadcrumbItemType[] }) {
    if (breadcrumbs.length === 0) return null;

    const steps = [HOME, ...breadcrumbs];

    return (
        <Breadcrumb>
            <BreadcrumbList className="flex-nowrap">
                {steps.map((item, index) => {
                    const isLast = index === steps.length - 1;

                    return (
                        <Fragment key={index}>
                            <BreadcrumbItem className={isLast ? 'min-w-0' : 'shrink-0 max-md:hidden'}>
                                {isLast ? (
                                    <BreadcrumbPage className="truncate font-medium">{item.title}</BreadcrumbPage>
                                ) : (
                                    <BreadcrumbLink href={item.href}>{item.title}</BreadcrumbLink>
                                )}
                            </BreadcrumbItem>
                            {!isLast && <BreadcrumbSeparator className="shrink-0 max-md:hidden" />}
                        </Fragment>
                    );
                })}
            </BreadcrumbList>
        </Breadcrumb>
    );
}
