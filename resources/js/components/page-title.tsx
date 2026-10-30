import { cn } from '@/lib/utils';
import { type ReactNode } from 'react';

/**
 * What the page is called, over the page, and a line about it where one is
 * needed.
 *
 * The name is hidden on a phone, and that is the whole of why this is a
 * component rather than a line of classes: the bar at the top of a narrow
 * screen already carries it, and a second one under it would push the page down
 * to repeat what has just been said.
 *
 * The line under it is not hidden with the name. The name is a label — said
 * twice it is noise — but a subtitle tells the reader something they cannot
 * read anywhere else on the page, and a phone needs it as much as a monitor.
 */
export function PageTitle({ children, subtitle, className }: { children: ReactNode; subtitle?: ReactNode; className?: string }) {
    const name = <h1 className={cn('text-xl leading-7 font-semibold tracking-[-0.01em] max-md:hidden', className)}>{children}</h1>;

    if (subtitle === undefined) {
        return name;
    }

    return (
        <div className="flex flex-col gap-1">
            {name}
            <p className="text-muted-foreground max-w-prose text-[13px]">{subtitle}</p>
        </div>
    );
}
