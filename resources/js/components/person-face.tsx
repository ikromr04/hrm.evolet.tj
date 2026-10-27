import { faceEdge, PersonAvatar } from '@/components/person-avatar';
import { useCan } from '@/lib/access';
import { cn } from '@/lib/utils';
import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';
import { Lock } from 'lucide-react';

/**
 * A colleague's face wherever it turns up beside their name.
 *
 * Three answers, and they are not the same answer. A photograph, if there is one
 * and it is this viewer's to look at. The initials, when nobody ever uploaded one
 * — that is a fact about the person. A lock, when the line is closed — that is a
 * fact about the viewer, and saying it with initials would be a small lie.
 *
 * The card and the staff list draw the same three from the list of lines they are
 * sent; here there is no such list, so the right is asked directly.
 */
export function PersonFace({
    id,
    name,
    avatar,
    original,
    plain = false,
    className,
}: {
    id: number;
    name: string;
    avatar: string | null;
    /**
     * The upload behind the thumbnail. What stands in a row is a square cut
     * for rows, and whoever presses it wants a better look than that; where a
     * page sends only the cut one, that is what opens.
     */
    original?: string | null;
    /**
     * Whether the face is only a face. A photograph normally opens itself in a
     * tab of its own when it is pressed — the picture is the easiest thing on
     * a row to aim at, and whoever wants a better look gets one — but a face
     * drawn inside something else that is already pressed (a row of a list of
     * choices, a box of the chart) leaves the press to it.
     */
    plain?: boolean;
    className?: string;
}) {
    const { auth } = usePage<SharedData>().props;
    const can = useCan();
    const self = auth.user?.id === id;

    if (!can(self ? 'profile.field.avatar' : 'employees.field.avatar')) {
        return (
            <span
                title="Фотография закрыта"
                aria-label="Фотография закрыта"
                className={cn('bg-muted text-muted-foreground flex size-9 shrink-0 items-center justify-center rounded-full', faceEdge, className)}
            >
                <Lock className="size-[45%]" />
            </span>
        );
    }

    if (!avatar) return <PersonAvatar name={name} className={className} />;

    const face = <img src={avatar} alt="" className={cn('size-9 shrink-0 rounded-full object-cover', faceEdge, className)} />;

    if (plain) return face;

    return (
        // Opened rather than enlarged in place: a tab of its own is where a
        // picture is looked at, and the row underneath stays where it was.
        <a
            href={original ?? avatar}
            target="_blank"
            rel="noopener"
            title={`Фотография: ${name}`}
            className="shrink-0 rounded-full"
            // The face may stand inside something else that is pressed — a row
            // of a table that opens a card; the picture is its own answer.
            onClick={(event) => event.stopPropagation()}
        >
            {face}
        </a>
    );
}
