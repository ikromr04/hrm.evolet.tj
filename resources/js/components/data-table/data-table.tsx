import { Card } from '@/components/ui/card';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { cn } from '@/lib/utils';
import { ArrowDown, ArrowUp, ArrowUpDown, EllipsisVertical, EyeOff, Pin, PinOff } from 'lucide-react';
import { Fragment, type CSSProperties, type ReactNode } from 'react';
import { ColumnFilter, isFilterActive } from './filters';
import { HeaderHint } from './hint';
import { type CellRenderer, type ColumnDef, type FilterValues, type Sort, type ViewState } from './types';

/** Room for the sticky column of row actions at the right edge. */
export const ACTIONS_WIDTH = 56;

interface DataTableProps<Row> {
    columns: ColumnDef[];
    rows: Row[];
    rowKey: (row: Row) => string | number;
    /** The page draws its own cells: only it knows what a row holds. */
    renderCell: CellRenderer<Row>;

    sort: Sort;
    /** Keys the server can actually sort by. */
    sortable: string[];
    onSort: (key: string, direction?: 'asc' | 'desc') => void;

    filters: FilterValues;
    onFilter: (changes: FilterValues) => void;
    /** A column may have a filter the current viewer must not use. */
    canFilter?: (column: ColumnDef) => boolean;
    /** Which filter popovers need the wider layout. */
    wideFilter?: (column: ColumnDef) => boolean;

    view: ViewState;
    onPin: (key: string, side: 'left' | 'right' | null) => void;
    /** Left out where the page has nowhere to bring a hidden column back from. */
    onHide?: (key: string) => void;
    /** The column that carries the row's identity and so cannot be hidden. */
    lockedKey: string;

    /** The right-hand sticky cell, when the viewer may act on a row. */
    actions?: (row: Row) => ReactNode;
    empty: ReactNode;
    /** A bar under the table, inside the card: rows per page and paging. */
    footer?: ReactNode;
    /**
     * How a row reads on a phone — usually a MobileRow. Given one, the table
     * turns into a plain list below `md`, where columns would only scroll sideways.
     */
    mobileRow?: (row: Row) => ReactNode;
}

/**
 * The table behind the employee and equipment lists: columns a viewer can sort,
 * filter, pin to either edge or hide, with the choice remembered between
 * visits. Rows and cells belong to the page; everything around them lives here.
 */
export function DataTable<Row>({
    columns,
    rows,
    rowKey,
    renderCell,
    sort,
    sortable,
    onSort,
    filters,
    onFilter,
    canFilter = (column) => Boolean(column.filter),
    wideFilter,
    view,
    onPin,
    onHide,
    lockedKey,
    actions,
    empty,
    footer,
    mobileRow,
}: DataTableProps<Row>) {
    /* Left-pinned first, then the rest in their own order, then right-pinned. */
    const isHidden = (key: string) => view.hidden.includes(key);
    const pinSide = (key: string) => (view.pinned.left.includes(key) ? 'left' : view.pinned.right.includes(key) ? 'right' : null);
    const byKey = (key: string) => columns.find((column) => column.key === key)!;

    const left = view.pinned.left.filter((key) => !isHidden(key) && columns.some((c) => c.key === key)).map(byKey);
    const right = view.pinned.right.filter((key) => !isHidden(key) && columns.some((c) => c.key === key)).map(byKey);
    const center = columns.filter((column) => !isHidden(column.key) && !pinSide(column.key));
    const visible = [...left, ...center, ...right];

    const actionsWidth = actions ? ACTIONS_WIDTH : 0;
    const tableWidth = visible.reduce((sum, column) => sum + column.width, 0) + actionsWidth;

    const stickyStyle = (column: ColumnDef): CSSProperties => {
        const side = pinSide(column.key);

        if (side === 'left') {
            const index = left.indexOf(column);
            return { left: left.slice(0, index).reduce((sum, c) => sum + c.width, 0) };
        }
        if (side === 'right') {
            const index = right.indexOf(column);
            return { right: right.slice(index + 1).reduce((sum, c) => sum + c.width, actionsWidth) };
        }

        return {};
    };

    const stickyClass = (column: ColumnDef, header: boolean) => {
        const side = pinSide(column.key);
        if (!side) return '';

        // Pins hold from the desktop up. On a phone or a tablet a pinned name
        // column alone is about as wide as the screen and would leave nothing
        // to scroll, so there the columns all move together; the row actions
        // stay at the edge, being narrow.
        return cn(
            'lg:sticky',
            header ? 'bg-sidebar z-20' : 'bg-card z-[1]',
            side === 'left' && column === left[left.length - 1] && 'lg:shadow-[1px_0_0_var(--border)]',
            side === 'right' && column === right[0] && 'lg:shadow-[-1px_0_0_var(--border)]',
        );
    };

    return (
        <Card className="flex flex-col gap-0 overflow-hidden rounded-xl p-0 max-md:rounded-2xl max-md:border-0 max-md:shadow-none md:min-h-0 md:flex-1">
            {/* relative: absolutely placed things inside the table — screen-reader
            labels, for one — take this box as their frame and are clipped by it,
            instead of escaping it and stretching the whole page sideways. */}
            {mobileRow && (
                <ul className="divide-border/70 divide-y md:hidden">
                    {rows.map((row) => (
                        <Fragment key={rowKey(row)}>{mobileRow(row)}</Fragment>
                    ))}
                    {rows.length === 0 && <li className="text-muted-foreground px-6 py-16 text-center text-sm">{empty}</li>}
                </ul>
            )}
            <div className={cn('scroll-soft relative overflow-auto md:min-h-0 md:flex-1', mobileRow && 'max-md:hidden')}>
                <table className="min-w-full table-fixed border-collapse text-sm" style={{ width: tableWidth }}>
                    <thead className="bg-sidebar sticky top-0 z-30">
                        <tr className="text-muted-foreground text-left text-[13px] whitespace-nowrap">
                            {visible.map((column, index) => {
                                const active = sort.key === column.key;
                                const canSort = sortable.includes(column.key);
                                const side = pinSide(column.key);
                                const SortIcon = !active ? ArrowUpDown : sort.direction === 'asc' ? ArrowUp : ArrowDown;

                                return (
                                    <th
                                        key={column.key}
                                        scope="col"
                                        aria-sort={active ? (sort.direction === 'asc' ? 'ascending' : 'descending') : undefined}
                                        style={{ width: column.width, ...stickyStyle(column) }}
                                        className={cn(
                                            'px-4 py-2.5 font-semibold',
                                            index === 0 && 'pl-6',
                                            index === visible.length - 1 && !actions && 'pr-6',
                                            stickyClass(column, true),
                                        )}
                                    >
                                        <div className="flex items-center gap-0.5">
                                            {canSort ? (
                                                <button
                                                    type="button"
                                                    onClick={() => onSort(column.key)}
                                                    className={cn(
                                                        'hover:text-foreground -ml-1 inline-flex shrink-0 items-center gap-1.5 rounded px-1 py-0.5',
                                                        active && 'text-foreground',
                                                    )}
                                                >
                                                    <span>{column.label}</span>
                                                    <SortIcon className={cn('size-3.5 shrink-0', !active && 'opacity-40')} aria-hidden="true" />
                                                </button>
                                            ) : (
                                                <span>{column.label}</span>
                                            )}

                                            <div className="ml-auto flex shrink-0 items-center gap-0.5">
                                                {column.filter && canFilter(column) && (
                                                    <ColumnFilter column={column} filters={filters} onApply={onFilter} wide={wideFilter?.(column)} />
                                                )}

                                                <DropdownMenu>
                                                    <HeaderHint label={canSort ? 'Сортировка и вид колонки' : 'Вид колонки'}>
                                                        <DropdownMenuTrigger asChild>
                                                            <button
                                                                type="button"
                                                                aria-label={`Действия с колонкой: ${column.label}`}
                                                                className="hover:bg-accent hover:text-foreground rounded p-1 opacity-50 hover:opacity-100"
                                                            >
                                                                <EllipsisVertical className="size-3.5" />
                                                            </button>
                                                        </DropdownMenuTrigger>
                                                    </HeaderHint>
                                                    <DropdownMenuContent align="end" className="w-52">
                                                        {canSort && (
                                                            <>
                                                                <DropdownMenuItem onSelect={() => onSort(column.key, 'asc')}>
                                                                    <ArrowUp />
                                                                    По возрастанию
                                                                </DropdownMenuItem>
                                                                <DropdownMenuItem onSelect={() => onSort(column.key, 'desc')}>
                                                                    <ArrowDown />
                                                                    По убыванию
                                                                </DropdownMenuItem>
                                                                <DropdownMenuSeparator />
                                                            </>
                                                        )}
                                                        <DropdownMenuRadioGroup
                                                            value={side ?? 'none'}
                                                            onValueChange={(value) =>
                                                                onPin(column.key, value === 'none' ? null : (value as 'left' | 'right'))
                                                            }
                                                        >
                                                            <DropdownMenuRadioItem value="left">
                                                                <Pin className="size-4 -rotate-45" />
                                                                Закрепить слева
                                                            </DropdownMenuRadioItem>
                                                            <DropdownMenuRadioItem value="right">
                                                                <Pin className="size-4 rotate-45" />
                                                                Закрепить справа
                                                            </DropdownMenuRadioItem>
                                                            <DropdownMenuRadioItem value="none">
                                                                <PinOff className="size-4" />
                                                                Не закреплять
                                                            </DropdownMenuRadioItem>
                                                        </DropdownMenuRadioGroup>
                                                        {onHide && column.key !== lockedKey && (
                                                            <>
                                                                <DropdownMenuSeparator />
                                                                <DropdownMenuItem onSelect={() => onHide(column.key)}>
                                                                    <EyeOff />
                                                                    Скрыть колонку
                                                                </DropdownMenuItem>
                                                            </>
                                                        )}
                                                    </DropdownMenuContent>
                                                </DropdownMenu>
                                            </div>
                                        </div>
                                    </th>
                                );
                            })}
                            {actions && (
                                <th
                                    scope="col"
                                    style={{ width: ACTIONS_WIDTH }}
                                    className="bg-sidebar sticky right-0 z-20 shadow-[-1px_0_0_var(--border)]"
                                >
                                    <span className="sr-only">Действия</span>
                                </th>
                            )}
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((row) => (
                            <tr key={rowKey(row)} className="border-t align-top">
                                {visible.map((column, index) => (
                                    <td
                                        key={column.key}
                                        style={stickyStyle(column)}
                                        className={cn(
                                            'truncate px-4 py-3',
                                            index === 0 && 'pl-6',
                                            index === visible.length - 1 && !actions && 'pr-6',
                                            stickyClass(column, false),
                                        )}
                                    >
                                        {renderCell(column, row)}
                                    </td>
                                ))}
                                {actions && (
                                    <td className="bg-card sticky right-0 z-[1] py-2 pr-3 pl-1 shadow-[-1px_0_0_var(--border)]">{actions(row)}</td>
                                )}
                            </tr>
                        ))}

                        {rows.length === 0 && (
                            <tr className="border-t">
                                <td colSpan={visible.length + (actions ? 1 : 0)} className="text-muted-foreground px-6 py-16 text-center">
                                    {empty}
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>

            {footer && (
                // From sm up rows-per-page and the paging share a line; a phone shows only the paging.
                <div className="flex shrink-0 flex-col items-stretch gap-2 border-t px-4 py-2.5 sm:flex-row sm:flex-wrap sm:items-center sm:gap-x-6 sm:gap-y-2 md:px-6 md:py-3">
                    {footer}
                </div>
            )}
        </Card>
    );
}

/** How many of a page's filters are narrowing anything right now. */
export function countActiveFilters(columns: ColumnDef[], filters: FilterValues, canFilter: (column: ColumnDef) => boolean): number {
    return columns.filter((column) => column.filter && canFilter(column) && isFilterActive(column.filter, filters)).length;
}
