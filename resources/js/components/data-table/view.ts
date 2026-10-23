import { router, usePage } from '@inertiajs/react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { type Sort, type TableView, type ViewState } from './types';

/**
 * How narrow and how wide a column may be dragged.
 *
 * The floor is what it takes for a column to stay usable at all: its header
 * keeps the funnel and the menu, and the menu is the only way back for a column
 * dragged down to nothing — a column squeezed until its own menu is out of reach
 * could only be recovered by resetting the whole view. The label gives way
 * instead and is cut short.
 *
 * The ceiling is about as wide as the card a table is drawn in, which is the
 * widest a column can be and still be a column rather than the whole page. It
 * is there so that one wild sweep of the mouse cannot push every other column
 * off the far side of the screen — and it has to leave room for the column that
 * fills a table, which is drawn as wide as whatever the others leave it and can
 * only be made wider than that by being dragged past the edge of the card.
 */
export const MIN_COLUMN_WIDTH = 96;
export const MAX_COLUMN_WIDTH = 1600;

/** A width as it may be stored: whole pixels, between the two limits. */
function clampWidth(width: number): number {
    return Math.min(MAX_COLUMN_WIDTH, Math.max(MIN_COLUMN_WIDTH, Math.round(width)));
}

/** One of the arrangements a viewer has kept for a table, under the name they gave it. */
export interface SavedView {
    id: string;
    name: string;
    view: ViewState;
    /** The filters, the sorting and the search this view was kept with, as a query. */
    query: string;
    /**
     * The same, for a list that narrows itself in the page: whatever the page
     * hands over is written down as it stands and handed back on the way in.
     */
    state?: unknown;
}

/** Everything kept for one table: the views, and which of them is in force. */
interface Catalogue {
    active: string | null;
    views: SavedView[];
}

const NO_VIEWS: Catalogue = { active: null, views: [] };

/**
 * What is in storage for a table, whichever way it was written.
 *
 * A table used to keep the one arrangement, written down as the arrangement
 * itself; it now keeps as many as the viewer names. One kept the old way is
 * read as the first of the new ones, so nobody loses the columns they had.
 */
function parseCatalogue(raw: string | null): Catalogue {
    try {
        const saved = JSON.parse(raw ?? 'null') as (Catalogue & ViewState) | null;
        if (!saved) return NO_VIEWS;

        if (Array.isArray(saved.views)) {
            const views = saved.views.filter((one) => one?.id && one?.view?.hidden && one.view.pinned);

            return { active: views.some((one) => one.id === saved.active) ? saved.active : null, views };
        }

        if (saved.hidden && saved.pinned) {
            const legacy: SavedView = { id: 'kept', name: 'Вид 1', view: saved, query: '' };

            return { active: legacy.id, views: [legacy] };
        }

        return NO_VIEWS;
    } catch {
        return NO_VIEWS;
    }
}

/** A kept view read against the columns there are now, rather than trusted as written. */
function sanitise(saved: ViewState | undefined, fallback: ViewState, known: string[]): ViewState {
    try {
        if (!saved?.hidden || !saved?.pinned) return fallback;

        // A saved view may mention columns that were renamed or removed since.
        const kept = (list: string[]) => list.filter((column) => known.includes(column));
        // A view saved before columns could be dragged has no order at all; that
        // is the page's own order, and so is an order left with nothing in it.
        const order = Array.isArray(saved.order) ? kept(saved.order) : [];
        // Likewise for widths, which are read one by one: a column that has been
        // renamed or dropped since leaves no ghost behind, and a width that is
        // not a number — or is one from before the limits were what they are —
        // is brought back inside them rather than trusted.
        const widths = Object.fromEntries(
            Object.entries(saved.widths ?? {})
                .filter(([column, width]) => known.includes(column) && typeof width === 'number' && Number.isFinite(width))
                .map(([column, width]) => [column, clampWidth(width)]),
        );

        return {
            hidden: kept(saved.hidden),
            pinned: { left: kept(saved.pinned.left), right: kept(saved.pinned.right) },
            ...(order.length > 0 ? { order } : {}),
            ...(Object.keys(widths).length > 0 ? { widths } : {}),
        };
    } catch {
        return fallback;
    }
}

/** What storage holds for a table, or nothing at all where there is no storage. */
function readCatalogue(key: string): Catalogue {
    try {
        return parseCatalogue(localStorage.getItem(key));
    } catch {
        // Storage can be unavailable (private mode): the table opens as the page draws it.
        return NO_VIEWS;
    }
}

/** The arrangement a table opens in: the view in force, where one is kept. */
function load(key: string, fallback: ViewState, known: string[]): ViewState {
    const catalogue = readCatalogue(key);

    return sanitise(catalogue.views.find((one) => one.id === catalogue.active)?.view, fallback, known);
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
 * Which columns a viewer hid, pinned, dragged into another order or dragged
 * wider. A view kept from an earlier visit opens the table; a view arranged in
 * this one is kept only when the viewer asks for it — see `useTableMemory`. The
 * key carries a version: bump it when the columns change so badly that an old
 * view would be nonsense.
 */
export function useTableView(storageKey: string, columnKeys: string[], fallback: ViewState) {
    const [view, setView] = useState<ViewState>(() => load(storageKey, fallback, columnKeys));

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
     * How wide a viewer dragged a column, or `null` to forget it and let the
     * page's own width stand again. It travels with the view rather than back
     * out to the page, so it has to keep the same identity between renders.
     */
    const resize = useCallback((key: string, width: number | null) => {
        setView((current) => {
            const widths = { ...current.widths };

            if (width === null) delete widths[key];
            else widths[key] = clampWidth(width);

            return { ...current, widths };
        });
    }, []);

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

    // The table is handed the view with its own way of writing a width down.
    const table: TableView = useMemo(() => ({ ...view, resize }), [view, resize]);

    return { view: table, setView, pin, toggleHidden, reorder, resize, order };
}

export function resetView(storageKey: string) {
    try {
        localStorage.removeItem(storageKey);
    } catch {
        // As above: nothing to clear if storage is unavailable.
    }
}

/**
 * Opens a list with the filters, the sorting and the search a viewer asked to
 * keep. The list lives in its query string, so that string is what is kept —
 * all of it but the page, which may not exist once the list has changed.
 *
 * Nothing is written here: a list is kept only when the viewer presses the
 * button for it (`useTableMemory`). What is read is read once, on the way in. A
 * visit with a query of its own — a link to a narrowed list, the back button —
 * is the list that was asked for and stands; a bare one, from the menu, is sent
 * on to the kept list.
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
    }, [storageKey, url]);
}

/** A query string as it is written down: the page left out, the rest in one order. */
function canonicalQuery(query: string): string {
    const params = new URLSearchParams(query);
    params.delete('page');
    params.sort();

    return params.toString();
}

/** A view as it is written down: the same arrangement always reads the same. */
function fingerprint(view: ViewState): string {
    return JSON.stringify({
        hidden: [...view.hidden].sort(),
        left: view.pinned.left,
        right: view.pinned.right,
        order: view.order ?? [],
        widths: Object.fromEntries(Object.entries(view.widths ?? {}).sort(([a], [b]) => a.localeCompare(b))),
    });
}

/** «Вид 3»: the name a new one is offered under, after the highest already taken. */
function nextName(views: SavedView[]): string {
    const taken = views.map((one) => Number(/^Вид (\d+)$/.exec(one.name)?.[1] ?? 0));

    return `Вид ${Math.max(0, ...taken) + 1}`;
}

export interface TableMemory {
    /** Whether there is anything for the menu to say. */
    offered: boolean;
    /** The views kept for this table, in the order they were made. */
    views: SavedView[];
    /** The view the table was last put into, even if it has been dragged about since. */
    activeId: string | null;
    /** Where the table stands: the page's own arrangement, a kept one, or neither. */
    current: 'default' | 'saved' | 'custom';
    /** The name a new view would be offered under. */
    suggestedName: string;
    /** Show the page's own arrangement, or one that was kept. */
    choose: (id: string | null) => void;
    /** Keep what is on screen as a view of its own. */
    save: (name: string) => void;
    /** Put what is on screen into a view that is already kept. */
    overwrite: (id: string) => void;
    rename: (id: string, name: string) => void;
    remove: (id: string) => void;
}

/**
 * The arrangements a viewer keeps for a table, and which of them it stands in.
 *
 * Sorting, widths, pins, the order of the columns, which of them are shown and
 * every filter are one answer to «how I want this list», and a viewer may have
 * several: the whole staff by department, the people who left this year, the
 * four columns somebody reads every Monday. Each is kept under a name, by the
 * press that makes it — until then nothing is written at all, a list narrowed
 * to answer one question being no list anybody wants back next week.
 *
 * A table therefore stands in the page's own arrangement, in one of the kept
 * ones, or in neither, having been dragged about since. The menu says which,
 * moves between them, and writes down what is on screen.
 */
export function useTableMemory({
    viewKey,
    queryKey,
    columnKeys,
    view,
    setView,
    fallback,
    state,
    onState,
    defaultState,
}: {
    viewKey: string;
    /** Where the filters live, for a table whose list is a page of its own. */
    queryKey?: string;
    /** The columns there are, for reading a kept view that may name others. */
    columnKeys: string[];
    view: ViewState;
    setView: (view: ViewState) => void;
    /** The arrangement the page opens with when nothing is kept. */
    fallback: ViewState;
    /**
     * What a list that narrows itself in the page is holding now — its sorting,
     * its funnels, its search — and the way to put it back. A list that lives in
     * its address bar says nothing here and keeps its query instead.
     */
    state?: unknown;
    onState?: (state: unknown) => void;
    /** What that state is when the page opens with nothing kept. */
    defaultState?: unknown;
}): TableMemory {
    const { url } = usePage();
    const [kept, setKept] = useState(() => readCatalogue(viewKey));

    const query = canonicalQuery(url.split('?')[1] ?? '');
    const shown = fingerprint(view);
    // A table whose list is narrowed in the page rather than in the address bar
    // has no query to keep, and is not asked about one.
    const tracksQuery = queryKey !== undefined;

    /** The page's own narrowing, written down the one way. */
    const held = JSON.stringify(state ?? null);
    const opened = JSON.stringify(defaultState ?? null);

    const active = kept.views.find((one) => one.id === kept.active) ?? null;
    const asActive =
        active !== null &&
        shown === fingerprint(active.view) &&
        (!tracksQuery || query === canonicalQuery(active.query)) &&
        held === JSON.stringify(active.state ?? defaultState ?? null);
    const asPage = active === null && shown === fingerprint(fallback) && (!tracksQuery || query === '') && held === opened;
    const current = asActive ? 'saved' : asPage ? 'default' : 'custom';

    /**
     * Writes the catalogue down, and beside it the query of whichever view is in
     * force — on the way in, the list is sent to that query before the page it
     * belongs to has been drawn, so it is read from a key of its own.
     */
    const keep = (next: Catalogue) => {
        setKept(next);

        try {
            if (next.views.length === 0) localStorage.removeItem(viewKey);
            else localStorage.setItem(viewKey, JSON.stringify(next));

            if (!queryKey) return;

            const chosen = next.views.find((one) => one.id === next.active);
            if (chosen) localStorage.setItem(queryKey, chosen.query);
            else localStorage.removeItem(queryKey);
        } catch {
            // Storage can be unavailable (private mode); the arrangement then
            // lasts as long as the page does, which is all it ever did.
        }
    };

    /**
     * A list that narrows itself in the page opens narrowed the way the view in
     * force was kept: nothing in the address says so, and the page itself starts
     * from its own defaults, so the kept state is handed over once, on the way
     * in. A list that lives in its address bar is put back by the address.
     */
    const restored = useRef(false);

    useEffect(() => {
        if (restored.current) return;
        restored.current = true;

        const one = kept.views.find((view) => view.id === kept.active);
        if (one && one.state !== undefined && one.state !== null) onState?.(one.state);
        // Once, when the list is drawn: afterwards the viewer is the one choosing.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    /** The list as one address, with the questions it is being asked, or without them. */
    const go = (next: string) => router.get(`${window.location.pathname}${next ? `?${next}` : ''}`, {}, { preserveScroll: true, replace: true });

    const show = (one: SavedView | null) => {
        // Read rather than trusted: a kept view may name a column this page no
        // longer has, and the columns may have been renamed since.
        setView(one ? sanitise(one.view, fallback, columnKeys) : fallback);
        onState?.(one ? (one.state ?? defaultState ?? null) : (defaultState ?? null));

        const wanted = one ? canonicalQuery(one.query) : '';
        if (tracksQuery && query !== wanted) go(one?.query ?? '');
    };

    const choose = (id: string | null) => {
        const one = id === null ? null : (kept.views.find((view) => view.id === id) ?? null);

        keep({ ...kept, active: one?.id ?? null });
        show(one);
    };

    const save = (name: string) => {
        const one: SavedView = {
            id: `${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 8)}`,
            name,
            view,
            query,
            state: state ?? null,
        };

        keep({ active: one.id, views: [...kept.views, one] });
    };

    const overwrite = (id: string) =>
        keep({ active: id, views: kept.views.map((one) => (one.id === id ? { ...one, view, query, state: state ?? null } : one)) });

    const rename = (id: string, name: string) => keep({ ...kept, views: kept.views.map((one) => (one.id === id ? { ...one, name } : one)) });

    /** What was on screen stays there; only the view it was kept under goes. */
    const remove = (id: string) => keep({ active: kept.active === id ? null : kept.active, views: kept.views.filter((one) => one.id !== id) });

    return {
        offered: current !== 'default' || kept.views.length > 0,
        views: kept.views,
        activeId: kept.active,
        current,
        suggestedName: nextName(kept.views),
        choose,
        save,
        overwrite,
        rename,
        remove,
    };
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
