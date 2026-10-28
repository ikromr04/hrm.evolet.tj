import { useEffect } from 'react';

/**
 * A page opened in order to add something to it.
 *
 * Half the lists of this app are added to through a window on the list's own
 * page rather than through a page of their own, so «добавить филиал» has
 * nowhere of its own to lead. It leads here instead: the address carries `add`,
 * the list opens, and the window opens with it — one press rather than a page,
 * a look around for the button and a second press.
 *
 * The word is taken out of the address as soon as it is read, so that the
 * window does not open again on the way back from a reload or a step back
 * through the history.
 */
export function useAskedToAdd(mayAdd: boolean, open: () => void): void {
    useEffect(() => {
        if (!mayAdd) return;

        const asked = new URL(window.location.href);
        if (asked.searchParams.get('add') === null) return;

        asked.searchParams.delete('add');
        window.history.replaceState({}, '', `${asked.pathname}${asked.search}${asked.hash}`);

        open();
        // Once, on arrival: afterwards the button is the way in.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);
}
