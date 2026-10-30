import { cn } from '@/lib/utils';
import { type ReactNode } from 'react';

/**
 * What stands inside the shell on one page: the padding away from the edges of
 * the window, and the spacing between the blocks of the page.
 *
 * Written once because it was written fourteen times, in four spellings, and
 * the differences between them were nobody's decision — one page had a wider
 * gap than its neighbours for no reason anybody could name, and three carried a
 * base gap that no screen ever applied, both ends of it overridden.
 *
 * One spacing for every page, wide or narrow: sixteen points between the blocks
 * of it, twelve on a phone. A page that is one card filling the height — a
 * list, a journal — adds `md:min-h-0` itself, which is what lets that card
 * shrink below its content and scroll inside instead of growing the page.
 */
export function PageContent({ className, children }: { className?: string; children: ReactNode }) {
    return <div className={cn('flex flex-1 flex-col gap-4 p-3 max-md:gap-3 md:px-4 md:py-6 lg:px-6', className)}>{children}</div>;
}
