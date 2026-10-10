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
    width: number;
    filter?: FilterDef;
    /** Anything else a page wants to keep on a column, e.g. whether it is private. */
    [extra: string]: unknown;
}

export interface Sort {
    key: string;
    direction: 'asc' | 'desc';
}

/** Which columns are hidden and which are pinned to an edge. */
export interface ViewState {
    hidden: string[];
    pinned: { left: string[]; right: string[] };
}

/** Whatever shape of filters a page uses; the table only reads them by param. */
export type FilterValues = Record<string, unknown>;

export type CellRenderer<Row> = (column: ColumnDef, row: Row) => ReactNode;
