import { buttonVariants } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';

export interface Paginated<T> {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
    path: string;
}

/** Page numbers to show: 1 2 3 … 28, keeping neighbours of the current page. */
function pageList(current: number, last: number): (number | 'gap')[] {
    const pages = new Set([1, last, current - 1, current, current + 1].filter((p) => p >= 1 && p <= last));
    if (current <= 3) [2, 3].forEach((p) => p <= last && pages.add(p));

    const sorted = [...pages].sort((a, b) => a - b);
    return sorted.flatMap((page, i) => (i > 0 && page - sorted[i - 1] > 1 ? ['gap' as const, page] : [page]));
}

const pageButton = 'size-9 shrink-0 rounded-md border font-semibold';

/**
 * The count and the page buttons. Below the desktop only the arrows and the
 * current page are left: the numbered row does not fit beside the rows-per-page
 * choice on a tablet, let alone on a 320px phone.
 */
export function Pagination({
    paginator,
    onPage,
    className,
}: {
    paginator: Paginated<unknown>;
    /**
     * Where a page lives, for a list that holds all of its rows already.
     *
     * A list the server pages through is walked by address, so its pages are
     * links: they are places, they open in a new tab, they go in the history.
     * A list that is all here is paged in the page itself, and a link to an
     * address that answers the same thing either way would be a lie about
     * where the second page is.
     */
    onPage?: (page: number) => void;
    className?: string;
}) {
    const { current_page: current, last_page: last } = paginator;
    const url = (page: number) => {
        const query = new URLSearchParams(window.location.search);
        query.set('page', String(page));
        return `${paginator.path}?${query.toString()}`;
    };

    const arrow = (page: number, label: string, icon: React.ReactNode, disabled: boolean) =>
        disabled ? (
            <span
                aria-disabled="true"
                className={cn(buttonVariants({ variant: 'outline', size: 'icon' }), 'size-9 shrink-0 opacity-50')}
                aria-label={label}
            >
                {icon}
            </span>
        ) : onPage ? (
            <button
                type="button"
                onClick={() => onPage(page)}
                className={cn(buttonVariants({ variant: 'outline', size: 'icon' }), 'size-9 shrink-0')}
                aria-label={label}
            >
                {icon}
            </button>
        ) : (
            <Link
                href={url(page)}
                preserveScroll
                className={cn(buttonVariants({ variant: 'outline', size: 'icon' }), 'size-9 shrink-0')}
                aria-label={label}
            >
                {icon}
            </Link>
        );

    /** One numbered page, as a place to go to or as a press. */
    const step = (page: number) => {
        const look = cn(
            buttonVariants({ variant: page === current ? 'default' : 'outline', size: 'icon' }),
            pageButton,
            page === current ? 'border-transparent' : 'text-foreground max-lg:hidden',
        );

        return onPage ? (
            <button key={page} type="button" onClick={() => onPage(page)} aria-current={page === current ? 'page' : undefined} className={look}>
                {page}
            </button>
        ) : (
            <Link key={page} href={url(page)} preserveScroll aria-current={page === current ? 'page' : undefined} className={look}>
                {page}
            </Link>
        );
    };

    return (
        <nav aria-label="Страницы" className={cn('text-muted-foreground flex items-center gap-2 text-sm', className)}>
            <span className="flex-1">
                Показано {paginator.data.length} из {paginator.total}
            </span>
            {last > 1 && (
                <>
                    {arrow(current - 1, 'Предыдущая страница', <ChevronLeft />, current <= 1)}
                    {pageList(current, last).map((page, i) =>
                        page === 'gap' ? (
                            <span key={`gap-${i}`} aria-hidden="true" className="max-lg:hidden">
                                …
                            </span>
                        ) : (
                            step(page)
                        ),
                    )}
                    {arrow(current + 1, 'Следующая страница', <ChevronRight />, current >= last)}
                </>
            )}
        </nav>
    );
}
