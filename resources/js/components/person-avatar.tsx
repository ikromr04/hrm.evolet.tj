import { useInitials } from '@/hooks/use-initials';
import { cn } from '@/lib/utils';

const palette = [
    { background: '#DCE7F7', color: '#1E3A6B' },
    { background: '#F6DDE4', color: '#6B1E35' },
    { background: '#E4EFD9', color: '#2F4A12' },
    { background: '#F2E4D0', color: '#5A3A12' },
    { background: '#E3E0F5', color: '#352B70' },
];

/** Picks a stable colour pair for a name, so a person keeps the same avatar everywhere. */
export function avatarColors(name: string) {
    let hash = 0;
    for (const char of name) {
        hash = (hash * 31 + char.charCodeAt(0)) | 0;
    }

    return palette[Math.abs(hash) % palette.length];
}

/**
 * The hairline every face wears, whichever of the three it is: a photograph, a
 * set of initials or a lock. Drawn inside the circle rather than around it, so
 * a face takes the same room with it as without.
 */
export const faceEdge = 'ring-border ring-1 ring-inset';

export function PersonAvatar({ name, className }: { name: string; className?: string }) {
    const getInitials = useInitials();

    return (
        <span
            aria-hidden="true"
            style={avatarColors(name)}
            className={cn('flex size-9 shrink-0 items-center justify-center rounded-full text-xs font-semibold', faceEdge, className)}
        >
            {getInitials(name)}
        </span>
    );
}
