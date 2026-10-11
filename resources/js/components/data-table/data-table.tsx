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
import { ArrowDown, ArrowLeft, ArrowRight, ArrowUp, ArrowUpDown, EllipsisVertical, EyeOff, GripVertical, Pin, PinOff } from 'lucide-react';
import { Fragment, useRef, useState, type CSSProperties, type DragEvent, type ReactNode } from 'react';
import { ColumnFilter, isFilterActive } from './filters';
import { HeaderHint } from './hint';
import { type CellRenderer, type ColumnDef, type FilterValues, type Sort, type ViewState } from './types';
import { orderedKeys } from './view';

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
    /**
     * Whether the headers carry a grip to drag a column into another place. A
     * page asks for it; the tables that have not are drawn exactly as before.
     */
    reorderable?: boolean;
    /** Where a dragged column was let go: beside `target`, on the side `place` says. */
    onReorder?: (key: string, target: string, place: 'before' | 'after') => void;
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
 * filter, pin to either edge or hide — and, where a page asks for it, drag into
 * another order — with the choice remembered between visits. Rows and cells
 * belong to the page; everything around them lives here.
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
    reorderable,
    onReorder,
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
    const center = orderedKeys(
        columns.map((column) => column.key),
        view.order,
    )
        .map(byKey)
        .filter((column) => !isHidden(column.key) && !pinSide(column.key));
    const visible = [...left, ...center, ...right];

    /* The column being dragged, and the gap the table is offering it. */
    const [dragged, setDragged] = useState<string | null>(null);
    const [dropAt, setDropAt] = useState<{ key: string; place: 'before' | 'after' } | null>(null);
    /* The header cells, so a drag can carry the whole cell as its picture. */
    const cells = useRef(new Map<string, HTMLTableCellElement>());
    const clearDrag = () => {
        setDragged(null);
        setDropAt(null);
    };

    /**
     * A column is dropped among its own kind only: the pins hold a column to an
     * edge, and a drag that crossed from the middle into a pinned run would have
     * to unpin it to mean anything.
     */
    const sameRun = (key: string, other: string) => pinSide(key) === pinSide(other);

    const dragOver = (event: DragEvent<HTMLTableCellElement>, column: ColumnDef) => {
        if (!dragged) return;

        // A column of another run, or the dragged one itself: nothing on offer,
        // and the cursor keeps its crossed circle because the drop is not taken.
        if (column.key === dragged || !sameRun(dragged, column.key)) {
            setDropAt(null);

            return;
        }

        // The drop is taken here, which is also what turns the cursor from a
        // crossed circle into a move arrow.
        event.preventDefault();
        event.dataTransfer.dropEffect = 'move';

        // The nearer half of the cell says which side of it the column lands on.
        const box = event.currentTarget.getBoundingClientRect();
        const place = event.clientX < box.left + box.width / 2 ? 'before' : 'after';

        setDropAt((current) => (current?.key === column.key && current.place === place ? current : { key: column.key, place }));
    };

    /**
     * The line down the edge the column would land on. It is a border rather
     * than something laid over the cell, so that the header's line and every
     * row's below it read as one line down the table, and so that nothing has
     * to be positioned inside cells that are sticky half the time.
     */
    const dropLine = (column: ColumnDef) =>
        dropAt?.key === column.key && (dropAt.place === 'before' ? 'border-l-2 border-l-ring' : 'border-r-2 border-r-ring');

    /**
     * The column a step to one side would swap with — the next one drawn in the
     * same run, which is what the move in a column's own menu does for anyone
     * who is not dragging a mouse. Read off the table as it stands, so a hidden
     * column is simply not in the way, and nothing at the end of its run has
     * anywhere to go.
     */
    const neighbour = (column: ColumnDef, by: -1 | 1): ColumnDef | undefined => {
        const run = pinSide(column.key) === 'left' ? left : pinSide(column.key) === 'right' ? right : center;

        return run[run.indexOf(column) + by];
    };

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
                                        ref={(cell) => {
                                            if (cell) cells.current.set(column.key, cell);
                                            else cells.current.delete(column.key);
                                        }}
                                        scope="col"
                                        aria-sort={active ? (sort.direction === 'asc' ? 'ascending' : 'descending') : undefined}
                                        style={{ width: column.width, ...stickyStyle(column) }}
                                        onDragOver={reorderable ? (event) => dragOver(event, column) : undefined}
                                        onDrop={
                                            reorderable
                                                ? (event) => {
                                                      event.preventDefault();
                                                      if (dragged && dropAt?.key === column.key) onReorder?.(dragged, column.key, dropAt.place);
                                                      clearDrag();
                                                  }
                                                : undefined
                                        }
                                        className={cn(
                                            'group/header px-4 py-2.5 font-semibold',
                                            index === 0 && 'pl-6',
                                            index === visible.length - 1 && !actions && 'pr-6',
                                            stickyClass(column, true),
                                            dragged === column.key && 'opacity-40',
                                            dropLine(column),
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
                                                {reorderable && (
                                                    <HeaderHint label="Перетащите, чтобы изменить порядок">
                                                        {/* A grip of its own, rather than the whole header: the
                                                            label beside it is the button that sorts, and a press
                                                            that might turn into a drag would make every sort and
                                                            every menu a gamble. It is draggable from the moment it
                                                            is drawn, because the browser decides whether a press is
                                                            a drag as the press happens and would already have said
                                                            no. Nothing here for a keyboard or a screen reader —
                                                            the menu beside it moves a column in words. */}
                                                        <span
                                                            draggable
                                                            onDragStart={(event) => {
                                                                // Firefox begins no drag at all with an empty clipboard.
                                                                event.dataTransfer.setData('text/plain', column.key);
                                                                event.dataTransfer.effectAllowed = 'move';
                                                                const cell = cells.current.get(column.key);
                                                                // The whole header travels with the cursor, not the grip alone.
                                                                if (cell) event.dataTransfer.setDragImage(cell, 24, 16);
                                                                setDragged(column.key);
                                                            }}
                                                            onDragEnd={clearDrag}
                                                            aria-hidden="true"
                                                            className="hover:bg-accent hover:text-foreground cursor-grab rounded p-1 opacity-0 group-hover/header:opacity-50 hover:opacity-100 active:cursor-grabbing"
                                                        >
                                                            <GripVertical className="size-3.5" />
                                                        </span>
                                                    </HeaderHint>
                                                )}

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

                                                        {/* The grip's move in words: the way a keyboard and a
                                                            screen reader have of putting a column elsewhere,
                                                            kept beside the pins because it is the same kind of
                                                            question about where a column stands. */}
                                                        {reorderable && (
                                                            <>
                                                                {([-1, 1] as const).map((by) => {
                                                                    const beside = neighbour(column, by);

                                                                    return (
                                                                        <DropdownMenuItem
                                                                            key={by}
                                                                            disabled={!beside}
                                                                            onSelect={() =>
                                                                                beside &&
                                                                                onReorder?.(column.key, beside.key, by < 0 ? 'before' : 'after')
                                                                            }
                                                                        >
                                                                            {by < 0 ? <ArrowLeft /> : <ArrowRight />}
                                                                            {by < 0 ? 'Переместить влево' : 'Переместить вправо'}
                                                                        </DropdownMenuItem>
                                                                    );
                                                                })}
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
                                            dragged === column.key && 'opacity-40',
                                            dropLine(column),
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
