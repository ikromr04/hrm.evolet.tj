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
export function PersonFace({ id, name, avatar, className }: { id: number; name: string; avatar: string | null; className?: string }) {
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

    return avatar ? (
        <img src={avatar} alt="" className={cn('size-9 shrink-0 rounded-full object-cover', faceEdge, className)} />
    ) : (
        <PersonAvatar name={name} className={className} />
    );
}
