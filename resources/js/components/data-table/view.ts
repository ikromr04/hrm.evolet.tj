import { router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { type Sort, type ViewState } from './types';

function load(key: string, fallback: ViewState, known: string[]): ViewState {
    try {
        const saved = JSON.parse(localStorage.getItem(key) ?? 'null') as ViewState | null;
        if (!saved?.hidden || !saved?.pinned) return fallback;

        // A saved view may mention columns that were renamed or removed since.
        const kept = (list: string[]) => list.filter((column) => known.includes(column));

        return { hidden: kept(saved.hidden), pinned: { left: kept(saved.pinned.left), right: kept(saved.pinned.right) } };
    } catch {
        return fallback;
    }
}

function save(key: string, view: ViewState) {
    try {
        localStorage.setItem(key, JSON.stringify(view));
    } catch {
        // Storage can be unavailable (private mode); the view just won't persist.
    }
}

/**
 * Which columns a viewer hid or pinned, remembered between visits. The key
 * carries a version: bump it when the columns change so badly that an old
 * view would be nonsense.
 */
export function useTableView(storageKey: string, columnKeys: string[], fallback: ViewState) {
    const [view, setView] = useState<ViewState>(() => load(storageKey, fallback, columnKeys));

    useEffect(() => save(storageKey, view), [storageKey, view]);

    const pin = (key: string, side: 'left' | 'right' | null) =>
        setView((current) => ({
            ...current,
            pinned: {
                left: side === 'left' ? [...current.pinned.left.filter((k) => k !== key), key] : current.pinned.left.filter((k) => k !== key),
                right: side === 'right' ? [key, ...current.pinned.right.filter((k) => k !== key)] : current.pinned.right.filter((k) => k !== key),
            },
        }));

    const toggleHidden = (key: string, hidden: boolean) =>
        setView((current) => ({
            ...current,
            hidden: hidden ? [...current.hidden, key] : current.hidden.filter((k) => k !== key),
        }));

    return { view, setView, pin, toggleHidden };
}

export function resetView(storageKey: string) {
    try {
        localStorage.removeItem(storageKey);
    } catch {
        // As above: nothing to clear if storage is unavailable.
    }
}

/**
 * Keeps a list's filters, sorting and search between visits. The list lives in
 * its query string, so that string is what is remembered — all of it but the
 * page, which may not exist once the list has changed. A visit with a query of
 * its own (a link to a narrowed list) wins over what was remembered; a bare one,
 * from the menu, is sent on to the remembered list.
 */
export function useRememberedQuery(storageKey: string) {
    const { url } = usePage();
    const restored = useRef(false);

    useEffect(() => {
        const params = new URLSearchParams(window.location.search);
        params.delete('page');
        const query = params.toString();

        if (!restored.current && query === '') {
            let saved: string | null = null;
            try {
                saved = localStorage.getItem(storageKey);
            } catch {
                // No storage: the list opens as it is.
            }
            if (saved) {
                // On a first load the router is set up only after the page's own
                // effects have run, so the visit waits a tick for it.
                const timer = setTimeout(() => {
                    restored.current = true;
                    // A fresh page, so boxes that keep their own copy of a filter start from the restored one.
                    router.get(`${window.location.pathname}?${saved}`, {}, { preserveScroll: true, replace: true });
                });

                return () => clearTimeout(timer);
            }
        }
        restored.current = true;

        try {
            if (query) localStorage.setItem(storageKey, query);
            else localStorage.removeItem(storageKey);
        } catch {
            // As above: the list just won't be remembered.
        }
    }, [storageKey, url]);
}

/**
 * The toolbar search box: what is typed now, sent to the server a moment later.
 *
 * The box keeps its own text while typing. An answer carries the query it was
 * asked with, so letters typed while it was on its way would be swallowed if
 * the answer were adopted — the box therefore ignores answers to its own
 * questions and takes only a query it did not ask for: a reset, a link, or the
 * back button.
 */
export function useDebouncedSearch(serverValue: string, apply: (value: string) => void, delay = 300): [string, (value: string) => void] {
    const [value, setValue] = useState(serverValue);
    // Queries the box itself asked for and has not seen answered yet, oldest first.
    const asked = useRef<string[]>([]);
    const applyRef = useRef(apply);

    useEffect(() => {
        applyRef.current = apply;
    });

    useEffect(() => {
        const index = asked.current.indexOf(serverValue);

        if (index >= 0) {
            // Our own answer, possibly to an older keystroke: drop it and everything before it.
            asked.current = asked.current.slice(index + 1);

            return;
        }

        asked.current = [];
        setValue(serverValue);
    }, [serverValue]);

    useEffect(() => {
        if (value === asked.current.at(-1) || (asked.current.length === 0 && value === serverValue)) return;

        let timer: ReturnType<typeof setTimeout>;

        const ask = () => {
            // Anything standing over the list waits — a window, a menu, a list of
            // its own: a visit now would redraw the rows under it, taking it away
            // mid-click, and would cancel the save a window is busy with.
            if (document.querySelector('[role="dialog"], [role="alertdialog"], [role="menu"], [role="listbox"]')) {
                timer = setTimeout(ask, delay);

                return;
            }

            asked.current = [...asked.current, value];
            applyRef.current(value);
        };

        timer = setTimeout(ask, delay);

        return () => clearTimeout(timer);
        // The query on the server only matters while the box has asked nothing.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [value, delay]);

    return [value, setValue];
}

/** Whether a list is sorted other than the way it opens. */
export function isSorted(sort: Sort, defaultSort: Sort): boolean {
    return sort.key !== defaultSort.key || sort.direction !== defaultSort.direction;
}

/**
 * Where a click on a column header takes the sorting: up, then down, then back
 * to the order the list opens in.
 */
export function cycleSort(current: Sort, key: string, defaultSort: Sort): Sort {
    if (current.key !== key) return { key, direction: 'asc' };
    if (current.direction === 'asc') return { key, direction: 'desc' };

    return defaultSort;
}
