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
 * set of initials or a lock. In the colour the tables and the cards are drawn
 * in, and a border rather than an inset ring: a ring is a shadow, and a
 * photograph is content, which paints over it — so under a photograph an inset
 * ring is there in the styles and nowhere on the screen. The border takes its
 * width out of the circle, so a face still fills the room it is given.
 */
export const faceEdge = 'border border-border';

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
