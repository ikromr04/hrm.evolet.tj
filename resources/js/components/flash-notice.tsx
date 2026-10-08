import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';
import { Info, X } from 'lucide-react';
import { useEffect, useState } from 'react';

/**
 * A sentence the server left for the next page: something went sideways on the
 * way — an expired session, say — and the person should know why they are back
 * where they were. It stays until closed or until the next page replaces it.
 */
export function FlashNotice() {
    const { flash } = usePage<SharedData>().props;
    const [shown, setShown] = useState(flash.notice);

    // A new visit brings a new notice, or none.
    useEffect(() => setShown(flash.notice), [flash.notice]);

    if (!shown) {
        return null;
    }

    return (
        // Above an open window: a sentence about an expired session arrives while
        // the form that met it still stands, and the overlay would bury it. Only
        // the stacking is lifted — a page scrolled down keeps the notice at its
        // top, where it has always been, because a dialog freezes the scrolling.
        <div
            role="status"
            className="bg-muted text-foreground relative z-[60] mx-3 mt-3 flex items-start gap-3 rounded-lg border px-4 py-3 text-sm md:mx-5"
        >
            <Info className="text-muted-foreground mt-0.5 size-4 shrink-0" />
            <p className="min-w-0 flex-1 break-words">{shown}</p>
            <button
                type="button"
                onClick={() => setShown(null)}
                aria-label="Закрыть"
                className="text-muted-foreground hover:text-foreground -m-2 shrink-0 rounded-md p-2"
            >
                <X className="size-4" />
            </button>
        </div>
    );
}
