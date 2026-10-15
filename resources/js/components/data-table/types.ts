import { type ReactNode } from 'react';

/**
 * `depth` indents tree options such as departments. `hint` spells a short label
 * out beside itself — a department named by its abbreviation everywhere else
 * says here what it stands for, because a filter is not a link one can follow
 * to find out.
 */
export type Option<T> = { value: T; label: string; depth?: number; hint?: string };

/**
 * What a column's filter asks for. The `param` names the query string key the
 * page sends to the server, so the two stay in step.
 */
export type FilterDef =
    | { type: 'text'; param: string; placeholder: string }
    | { type: 'select'; param: string; options: Option<string>[] }
    | { type: 'multi'; param: string; options: Option<string | number>[] }
    | { type: 'dates'; from: string; to: string };

/**
 * One column. The table lays it out and draws its header; the page draws the
 * cell, since only it knows what its rows hold.
 */
export interface ColumnDef {
    key: string;
    label: string;
    /**
     * How wide the column is drawn, in pixels, until a viewer drags its edge:
     * the page's own answer, and the one a reset and the column's menu come
     * back to.
     */
    width: number;
    /**
     * Whether this is the column that takes up whatever room the others leave.
     * A table narrower than its card has to put that room somewhere, and the
     * page knows which of its columns is glad of it — a name, a list of duties.
     * At most one column says so; where none does, the last of the columns that
     * scroll takes it. The width above is then the least it will be drawn at,
     * not the width it is drawn at.
     */
    fill?: boolean;
    /**
     * Whether this column is fenced off from the ones beside it by a line down
     * the boundary — what `gridlines` does to every column of a table, asked
     * for one column at a time.
     *
     * It is for the columns whose cells cover blocks of rows. A cell standing
     * over twelve rows has nothing to say where it ends sideways, and the line
     * is what makes the block read as a block rather than as text that happens
     * to line up. The columns around it are read a row at a time and need no
     * line of their own; a table that drew one everywhere would be a grid, and
     * a list of rows reads as a list.
     *
     * A boundary is drawn where either of the two columns meeting at it asks
     * for one, so two fenced columns standing side by side are fenced as a
     * pair — a line down each outer edge and the one between them — and a
     * column dragged away from the other takes its lines with it. The line
     * belongs to the cells, so it is pinned, dragged, hidden, resized and
     * scrolled sideways along with the column, and the header agrees with the
     * body without being told to.
     */
    fenced?: boolean;
    filter?: FilterDef;
    /** Anything else a page wants to keep on a column, e.g. whether it is private. */
    [extra: string]: unknown;
}

export interface Sort {
    key: string;
    direction: 'asc' | 'desc';
}

/**
 * Which columns are hidden, which are pinned to an edge, the order a viewer
 * dragged them into and how wide they were dragged. The order is left out until
 * something is dragged, and a view saved before the dragging existed has none
 * either, so an absent order means the page's own one — which is also what a
 * reset returns to. The order names the unpinned columns; a pinned one takes its
 * place from the side it is pinned to.
 *
 * The widths name only the columns a viewer has actually dragged; every other
 * column keeps the width its page gives it, so a page may still change its mind
 * about a column nobody has touched, and a view saved before any of this could
 * be dragged is simply a view in which nobody has.
 */
export interface ViewState {
    hidden: string[];
    pinned: { left: string[]; right: string[] };
    order?: string[];
    widths?: Record<string, number>;
}

/**
 * A view as a table is handed one: everything that is written down, plus the one
 * thing the table cannot write down itself.
 *
 * A width is set by dragging inside the table, but the table has no idea where
 * views are kept — the key belongs to the page. Pinning and reordering ask the
 * page in so many props; a width arrives with the view instead, so that the six
 * tables already in the app can be dragged without each of their pages having to
 * hand on one more callback first. A view with no `resize` — one a page made by
 * hand — is a table whose columns cannot be dragged wider, exactly as before.
 *
 * `null` for the width puts a column back to the one its page gives it.
 */
export type TableView = ViewState & { resize?: (key: string, width: number | null) => void };

/** Whatever shape of filters a page uses; the table only reads them by param. */
export type FilterValues = Record<string, unknown>;

export type CellRenderer<Row> = (column: ColumnDef, row: Row) => ReactNode;
