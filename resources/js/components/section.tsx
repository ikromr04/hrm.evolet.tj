import { cn } from '@/lib/utils';
import { type ReactNode } from 'react';

/**
 * One numbered part of a page: what it is called, and everything said under
 * the name.
 *
 * Both halves are given rather than built here, because what a title looks
 * like and what a body holds are the page's business — a form, a table, two
 * sentences. What belongs to every part alike is the eight points between the
 * name and the answer, which is the whole of what this keeps.
 */
export function Section({
    title,
    body,
    className,
    ...rest
}: { title: ReactNode; body: ReactNode; className?: string } & Omit<React.ComponentPropsWithoutRef<'section'>, 'title' | 'children'>) {
    return (
        <section className={cn('flex min-w-0 flex-col gap-2', className)} {...rest}>
            {title}

            {/* Indented by the width of the figure's box in the title and the gap
                after it, so the name of the part and everything said under it
                stand on one vertical. */}
            <div className="flex min-w-0 flex-col pl-6">{body}</div>
        </section>
    );
}
