import { router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { type Sort, type ViewState } from './types';

function load(key: string, fallback: ViewState, known: string[]): ViewState {
    try {
        const saved = JSON.parse(localStorage.getItem(key) ?? 'null') as ViewState | null;
        if (!saved?.hidden || !saved?.pinned) return fallback;

        // A saved view may mention columns that were renamed or removed since.
        const kept = (list: string[]) => list.filter((column) => known.includes(column));
        // A view saved before columns could be dragged has no order at all; that
        // is the page's own order, and so is an order left with nothing in it.
        const order = Array.isArray(saved.order) ? kept(saved.order) : [];

        return {
            hidden: kept(saved.hidden),
            pinned: { left: kept(saved.pinned.left), right: kept(saved.pinned.right) },
            ...(order.length > 0 ? { order } : {}),
        };
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
 * The page's columns in the order a viewer put them. A column the saved order
 * has never heard of — one the release after it added — keeps the place the page
 * gives it, behind whichever of its neighbours the viewer has already placed, so
 * that it turns up beside the columns it belongs with instead of at an edge.
 */
export function orderedKeys(columnKeys: string[], order: string[] | undefined): string[] {
    if (!order?.length) return columnKeys;

    const result = order.filter((key) => columnKeys.includes(key));

    columnKeys.forEach((key, index) => {
        if (result.includes(key)) return;

        const neighbour = columnKeys
            .slice(0, index)
            .reverse()
            .find((earlier) => result.includes(earlier));

        result.splice(neighbour === undefined ? 0 : result.indexOf(neighbour) + 1, 0, key);
    });

    return result;
}

/** Which of the three runs of columns a column sits in. */
function groupOf(view: ViewState, key: string): 'left' | 'center' | 'right' {
    if (view.pinned.left.includes(key)) return 'left';
    if (view.pinned.right.includes(key)) return 'right';

    return 'center';
}

/** The order a group is drawn in: a pin's own list, or the order for the middle. */
function groupKeys(view: ViewState, columnKeys: string[], group: 'left' | 'center' | 'right'): string[] {
    if (group === 'left') return view.pinned.left;
    if (group === 'right') return view.pinned.right;

    return orderedKeys(columnKeys, view.order);
}

/**
 * Puts a column beside another one, where it was dropped. A pin already holds a
 * column to an edge, so a column only ever moves among its own kind: a drop on
 * a column from another group is no move at all, rather than a drag that
 * quietly unpins something.
 */
export function reorderColumn(view: ViewState, columnKeys: string[], key: string, target: string, place: 'before' | 'after'): ViewState {
    const group = groupOf(view, key);
    if (key === target || group !== groupOf(view, target)) return view;

    const without = groupKeys(view, columnKeys, group).filter((other) => other !== key);
    const at = without.indexOf(target);
    if (at < 0) return view;

    const next = [...without.slice(0, place === 'after' ? at + 1 : at), key, ...without.slice(place === 'after' ? at + 1 : at)];

    if (group === 'left') return { ...view, pinned: { ...view.pinned, left: next } };
    if (group === 'right') return { ...view, pinned: { ...view.pinned, right: next } };

    return { ...view, order: next };
}

/**
 * A page's own columns in the order the table draws them, for a menu that lists
 * them: it should read along the table, or the names in it stand somewhere the
 * eye cannot follow. A page may hold fewer columns than the view knows — a
 * category a filter left out — and may hold one the view has not met yet; both
 * are the same question as above, and are answered there.
 */
export function orderedColumns<Column extends { key: string }>(columns: Column[], order: string[]): Column[] {
    return orderedKeys(
        columns.map((column) => column.key),
        order,
    ).map((key) => columns.find((column) => column.key === key)!);
}

/**
 * Which columns a viewer hid, pinned or dragged into another order, remembered
 * between visits. The key carries a version: bump it when the columns change so
 * badly that an old view would be nonsense.
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

    const reorder = (key: string, target: string, place: 'before' | 'after') =>
        setView((current) => reorderColumn(current, columnKeys, key, target, place));

    /**
     * The columns in the order the table draws them — the pins at their edges,
     * everything else in between — which is how `orderedColumns` lays a menu
     * out. Hidden ones keep their place in it, since they are the ones a menu
     * is about to bring back.
     */
    const order = [
        ...view.pinned.left.filter((key) => columnKeys.includes(key)),
        ...orderedKeys(columnKeys, view.order).filter((key) => groupOf(view, key) === 'center'),
        ...view.pinned.right.filter((key) => columnKeys.includes(key)),
    ];

    return { view, setView, pin, toggleHidden, reorder, order };
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
