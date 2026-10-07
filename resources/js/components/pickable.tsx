import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import { type ReactNode } from 'react';

const look =
    'focus-visible:ring-ring cursor-pointer rounded-md transition-[filter] hover:brightness-95 focus-visible:ring-2 focus-visible:outline-hidden dark:hover:brightness-125';

/**
 * A chip that narrows the staff list to whoever shares it: in the list itself it
 * applies the filter (`onPick`), elsewhere it leads to the list so filtered
 * (`href`). Given neither, it is the chip alone — for a viewer the filter would
 * refuse.
 */
export function Pickable({
    href,
    onPick,
    picked,
    label,
    children,
}: {
    href?: string;
    onPick?: () => void;
    /** Whether the filter already holds it; a second click takes it off. */
    picked?: boolean;
    label: string;
    children: ReactNode;
}) {
    if (onPick) {
        return (
            <button
                type="button"
                title={picked ? `${label} — убрать из фильтра` : label}
                aria-pressed={picked}
                className={cn(look, 'text-left')}
                onClick={(event) => {
                    // A row may open the record; the chip only filters.
                    event.stopPropagation();
                    onPick();
                }}
            >
                {children}
            </button>
        );
    }

    if (href) {
        return (
            <Link href={href} title={label} className={look}>
                {children}
            </Link>
        );
    }

    return <>{children}</>;
}
