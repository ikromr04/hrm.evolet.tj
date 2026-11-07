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
import {
    ArrowDown,
    ArrowLeft,
    ArrowRight,
    ArrowUp,
    ArrowUpDown,
    ChevronsLeftRight,
    ChevronsRightLeft,
    EllipsisVertical,
    EyeOff,
    GripVertical,
    Pin,
    PinOff,
    RotateCcw,
} from 'lucide-react';
import { Fragment, useEffect, useRef, useState, type CSSProperties, type DragEvent, type PointerEvent, type ReactNode } from 'react';
import { ColumnFilter, isFilterActive } from './filters';
import { Hint } from './hint';
import { type CellRenderer, type ColumnDef, type FilterValues, type Sort, type TableView } from './types';
import { MAX_COLUMN_WIDTH, MIN_COLUMN_WIDTH, orderedKeys } from './view';

/**
 * How much of a column's width a press of «шире» or «уже» is worth: enough that
 * two or three presses are a real change, little enough to stop where you meant
 * to. The menu stays open between presses, so stepping is a matter of pressing
 * again rather than of finding the menu again.
 */
const WIDTH_STEP = 40;

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

    /**
     * What the viewer has made of the columns. Hiding, pinning and the order are
     * asked back of the page; a width the view carries its own way of writing
     * down, and a view without one is a table whose columns cannot be dragged
     * wider.
     */
    view: TableView;
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

    /**
     * Whether every boundary between columns is drawn as a line, turning the
     * whole table into a grid.
     *
     * Off unless a page asks: the tables in this app are lists of rows and read
     * as lists, with a line between rows and nothing between columns. A page
     * asks where its cells cover blocks of rows — a cell standing over twelve
     * rows has nothing to say where it ends sideways, and the lines are what
     * make the block read as a block rather than as text that happens to line
     * up. Where only some of the columns are read that way, the columns
     * themselves say so with `fenced` and the rest of the table stays a list.
     *
     * The lines belong to the cells, so they are pinned, dragged and scrolled
     * sideways along with them, and they are the same line the rows are divided
     * by — the one border colour, one pixel of it.
     */
    gridlines?: boolean;

    /**
     * How far down a cell reaches, where a page's rows come in blocks that share
     * a value: the number of rows the cell covers, or 0 for a column whose cell
     * a row above has already covered, which is then not drawn at all.
     *
     * Left out, every cell covers its own row and the table is the flat grid it
     * has always been — which is what the pages that do not pass this get, down
     * to the markup. A page that does pass it owes the table a consistent
     * answer: a cell of n rows has to be followed by n-1 rows answering 0 in
     * that column, or the browser is handed a table whose rows are not the same
     * width.
     *
     * Only the page can say where a block begins, since only the page knows what
     * its rows mean and what order they are in — and a block is only a block
     * while the order keeps its rows together, so a page that groups has to stop
     * when the order changes under it.
     */
    rowSpan?: (column: ColumnDef, row: Row) => number;

    /**
     * What a page wants said about a whole row rather than about a cell of it:
     * a class for the row, and what a press on it means.
     *
     * The table knows what a row looks like and nothing about what one is. A
     * list of notes has read rows and unread ones — which of them is which is
     * the page's business, and reaching for it from here would be the table
     * learning what a notification is.
     *
     * A row given a press takes the cursor and the keyboard with it, and
     * anything inside it that answers a press of its own — a link, a button,
     * the box that ticks it — is left to answer alone rather than doing two
     * things at once.
     */
    rowClass?: (row: Row) => string | undefined;
    onRowClick?: (row: Row) => void;

    /**
     * What can be done to a row, floated over its right-hand end while the
     * cursor is on it.
     *
     * It takes no width of its own. A column of buttons standing open down the
     * whole table is read once and ignored afterwards, and it costs every other
     * column the room it sits in; these appear on the row being pointed at and
     * lie over whatever that row's last column was showing, the way the actions
     * of a mail list do.
     */
    actions?: (row: Row) => ReactNode;
    /**
     * What to do when one row is dragged onto another: the key of the row that
     * was dragged, the key it was dropped on, and which side of it.
     *
     * A list whose order is its own — the papers a company asks for, read from
     * the top down in the order it asks for them — is arranged by hand, and
     * this is the hand. Given nothing, the rows do not move: most tables are
     * read in whatever order they are sorted into, and a row that could be
     * dragged in one of those would be promising something it cannot keep.
     */
    onRowDrop?: (dragged: string, onto: string, place: 'before' | 'after') => void;
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
 * filter, pin to either edge, hide, drag wider or narrower — and, where a page
 * asks for it, drag into another order — with the choice remembered between
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
    reorderable,
    onReorder,
    onHide,
    lockedKey,
    gridlines,
    rowSpan,
    rowClass,
    onRowClick,
    actions,
    onRowDrop,
    empty,
    footer,
    mobileRow,
}: DataTableProps<Row>) {
    /**
     * The column whose edge is being dragged right now, with the width it has
     * reached. The width lives here while the hand is down — the view is written
     * to storage on every change, and a drag of a hundred pixels is a hundred
     * changes — and goes into the view once, when the hand comes up.
     */
    const [resizing, setResizing] = useState<{ key: string; width: number } | null>(null);
    /* Where the drag began: the pointer, and the width the column had then. */
    const resizeFrom = useRef({ x: 0, width: 0 });

    /**
     * The least a column may be drawn at: enough for its own name, the arrow
     * that sorts it and the signs beside them.
     *
     * Measured rather than written down. A page says how wide a column should
     * be for what stands in its cells, and nobody keeps those numbers in step
     * with the letters of the heading — «Тип хранения» lands in a column sized
     * for «Да» and «Нет», and the heading is cut to «Тип хран…», which is the
     * one thing in a table that must not be guessed at: a cell may be opened,
     * widened or read in the row beside it, but a heading cut short leaves the
     * column unnamed.
     *
     * Only what is already in the header is measured, so this settles at once:
     * once the column is wide enough, what it needs is what it has.
     */
    const [headFloor, setHeadFloor] = useState<Record<string, number>>({});

    /**
     * Measured again once the letters are the ones they will be.
     *
     * The first paint is drawn in whatever font the system had to hand, and
     * the real one arrives a moment later and is wider: a heading measured
     * before the swap fits, and is cut short by the swap without anything
     * happening that would measure it again.
     */
    const [lettered, setLettered] = useState(false);

    useEffect(() => {
        let waiting = true;

        document.fonts?.ready.then(() => waiting && setLettered(true));

        return () => {
            waiting = false;
        };
    }, []);

    // After the paint rather than before it: what a heading is missing can only
    // be read off a header that has been laid out, and each pass measures what
    // the last one left. Two frames at the most, which is below seeing.
    useEffect(() => {
        const floors: Record<string, number> = {};

        for (const [key, cell] of cells.current) {
            const label = cell.firstElementChild?.querySelector('span');
            if (!label) continue;

            // How much of the name is not showing. A heading that fits asks
            // for nothing: it is left without a floor at all, so a column
            // nobody has had to widen can still be dragged as narrow as its
            // owner likes.
            const clipped = label.scrollWidth - label.clientWidth;

            if (clipped <= 0) continue;

            // As wide as it stands plus what is missing, and a pixel over:
            // every one of these numbers is rounded on the way out, and a name
            // that fits to the last half-pixel is a name that ends in «…» on
            // half the screens it is read on.
            floors[key] = Math.ceil(cell.getBoundingClientRect().width + clipped) + 1;
        }

        setHeadFloor((held) => {
            // Only ever wider, and measured again from what the last pass left:
            // a clipped name says how much of itself is showing rather than how
            // much there is, so one pass closes most of the gap and the next
            // closes the rest. It settles the moment nothing is clipped, which
            // is what makes it safe to measure again on every change.
            const next = { ...held };

            for (const [key, at] of Object.entries(floors)) {
                next[key] = Math.max(at, held[key] ?? 0);
            }

            const same = Object.keys(next).length === Object.keys(held).length && Object.entries(next).every(([key, at]) => held[key] === at);

            return same ? held : next;
        });
    }, [columns, view.hidden, view.pinned, sort, filters, headFloor, lettered]);

    /**
     * How wide a column is drawn: what the hand is doing to it this instant,
     * else what the viewer dragged it to before, else the page's own answer —
     * and never narrower than its own heading.
     */
    const widthOf = (column: ColumnDef) =>
        Math.max(resizing?.key === column.key ? resizing.width : (view.widths?.[column.key] ?? column.width), headFloor[column.key] ?? 0);

    /** Whether a column stands at a width of the viewer's own choosing. */
    const isResized = (column: ColumnDef) => view.widths?.[column.key] !== undefined;

    const clampWidth = (width: number) => Math.min(MAX_COLUMN_WIDTH, Math.max(MIN_COLUMN_WIDTH, Math.round(width)));

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

    /**
     * How tall the header strip is, published to everything inside the table as
     * `--table-head`.
     *
     * A page whose cells cover several rows keeps the label of such a cell in
     * view while its rows go past, and that label has to come to rest under the
     * header rather than behind it. The height is measured rather than written
     * down: it is a header's font, its padding and whatever a column's menu and
     * funnel put in it, and all three have changed before.
     *
     * Nothing is measured for a table that has no cells covering several rows,
     * which is every other table in the app.
     */
    const head = useRef<HTMLTableSectionElement>(null);
    const [headHeight, setHeadHeight] = useState(0);
    const spanning = Boolean(rowSpan);

    useEffect(() => {
        const element = head.current;

        if (!spanning || !element) return;

        const observer = new ResizeObserver(([entry]) => setHeadHeight(entry.contentRect.height));

        observer.observe(element);

        return () => observer.disconnect();
    }, [spanning]);

    /* The column being dragged, and the gap the table is offering it. */
    const [dragged, setDragged] = useState<string | null>(null);
    const [dropAt, setDropAt] = useState<{ key: string; place: 'before' | 'after' } | null>(null);
    /* The header cells, so a drag can carry the whole cell as its picture. */
    const cells = useRef(new Map<string, HTMLTableCellElement>());
    const clearDrag = () => {
        setDragged(null);
        setDropAt(null);
    };

    /** The row being dragged into another place, and where it would land. */
    const [draggedRow, setDraggedRow] = useState<string | null>(null);
    const [rowDropAt, setRowDropAt] = useState<{ key: string; place: 'before' | 'after' } | null>(null);
    const clearRowDrag = () => {
        setDraggedRow(null);
        setRowDropAt(null);
    };

    /**
     * Where a row would land if it were let go here: above the row under the
     * cursor or below it, by which half of it the cursor is over. Read from the
     * cell rather than from the row, because a row is as tall as its tallest
     * cell and the cursor is over one of them.
     */
    const rowDragOver = (event: DragEvent<HTMLTableRowElement>, key: string) => {
        if (draggedRow === null || key === draggedRow) {
            setRowDropAt(null);

            return;
        }

        event.preventDefault();
        event.dataTransfer.dropEffect = 'move';

        const box = event.currentTarget.getBoundingClientRect();

        setRowDropAt({ key, place: event.clientY < box.top + box.height / 2 ? 'before' : 'after' });
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

    /**
     * The column that takes whatever room is left over, where the columns do not
     * fill the card by themselves.
     *
     * A fixed table hands a box wider than its columns out in proportion to what
     * each column asks for, so every column would grow a little whenever one of
     * them grew, and a dragged edge would trail behind the hand dragging it. One
     * column is therefore left without a width of its own: the browser gives it
     * the whole of what is left over and leaves every other column at exactly
     * the width it declares. The table fills its card with something worth
     * reading rather than with a gap, and an edge follows the pointer one for
     * one from the first press.
     *
     * A page says which column that should be — the one whose content is glad of
     * the room, a name or a list of duties — and where a page says nothing the
     * last of the scrolling columns takes it, that being where the spare room
     * would have shown. Only a column that scrolls can do the work: a pinned one
     * is held to an edge and sized by the offsets of its neighbours.
     *
     * The trade is worth saying plainly: this one column changes width when its
     * neighbours change, because filling is its job, and it is the only column
     * that does. Dragging it is still worth something — its own width is the
     * floor it fills up from, so widening it past the room there is makes the
     * table scroll — but the filling wins over the width written down for it: a
     * column asked to leave no gap cannot also be the one that leaves one.
     */
    const fills = center.find((column) => column.fill) ?? center.at(-1);

    /**
     * The width a column is drawn at, which for the filling column is not the
     * width it declares. A drag and the menu's steps both start from what the
     * eye sees, so that an edge follows the hand from the first press and «шире»
     * widens by as much as it says.
     */
    const drawnWidth = (column: ColumnDef) =>
        column === fills ? Math.round(cells.current.get(column.key)?.getBoundingClientRect().width ?? widthOf(column)) : widthOf(column);

    /**
     * The drag of a column's edge begins. The handle captures the pointer, so
     * the rest of the gesture comes back to it however far out of the cell — or
     * out of the table, or out of the window — the hand wanders, and the browser
     * hands the capture back by itself if the gesture is taken away.
     *
     * The press is deliberately not prevented: a prevented press is a press the
     * browser reports no double-click for, and a double-click on the handle is
     * how a width is put back. What prevention would have been for — the header
     * being selected as text by the drag — the table answers with a class while
     * a drag is on.
     */
    const startResize = (event: PointerEvent<HTMLSpanElement>, column: ColumnDef) => {
        // The main button only; the others belong to the browser's own menus.
        if (event.button !== 0) return;

        event.currentTarget.setPointerCapture(event.pointerId);
        resizeFrom.current = { x: event.clientX, width: drawnWidth(column) };
        setResizing({ key: column.key, width: drawnWidth(column) });
    };

    const moveResize = (event: PointerEvent<HTMLSpanElement>, column: ColumnDef) => {
        if (resizing?.key !== column.key) return;

        setResizing({ key: column.key, width: clampWidth(resizeFrom.current.width + event.clientX - resizeFrom.current.x) });
    };

    /**
     * The hand comes up, or the gesture is taken away mid-drag, and the width
     * the column reached is written into the view. A press that moved nothing
     * writes nothing down: a column nobody has dragged should go on taking
     * whatever width its page gives it, through any later change of mind.
     */
    const endResize = (column: ColumnDef) => {
        if (resizing?.key !== column.key) return;

        if (resizing.width !== resizeFrom.current.width) view.resize?.(column.key, resizing.width);
        setResizing(null);
    };

    // The actions take no width, so the table is as wide as its columns and the
    // last of them runs to the edge.
    const tableWidth = visible.reduce((sum, column) => sum + widthOf(column), 0);

    /**
     * Where a pinned column comes to rest: as far in as the columns pinned
     * before it reach. The widths they are drawn at, not the ones their page
     * wrote down — a column dragged wider pushes the ones pinned after it along,
     * and both edges are read the same way, outwards from the edge they hold to.
     */
    const stickyStyle = (column: ColumnDef): CSSProperties => {
        const side = pinSide(column.key);

        if (side === 'left') {
            const index = left.indexOf(column);
            return { left: left.slice(0, index).reduce((sum, c) => sum + widthOf(c), 0) };
        }
        if (side === 'right') {
            const index = right.indexOf(column);
            return { right: right.slice(index + 1).reduce((sum, c) => sum + widthOf(c), 0) };
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
        //
        // Every pinned column carries the line on the side it is held to — the
        // left-hand ones on their right, the right-hand ones on their left — so
        // a pin is read as a pin wherever it stands in the run.
        //
        // The line is drawn inside the cell rather than beside it: the borders
        // of this table are collapsed, and a collapsed table paints nothing a
        // cell puts outside its own edge, shadows included. Inside, it does.
        return cn(
            'lg:sticky',
            header ? 'bg-sidebar z-20' : 'bg-card z-[1] transition-colors group-hover/row:bg-(--row-hover)',
            side === 'left' && 'lg:shadow-[inset_-1px_0_0_var(--border)]',
            side === 'right' && 'lg:shadow-[inset_1px_0_0_var(--border)]',
        );
    };

    /**
     * The line down a cell's right edge, where a boundary is to be drawn.
     *
     * Every boundary belongs to the column on its left, so each one is drawn
     * once however many of the columns meeting at it asked for it: a whole grid
     * and a pair of fenced columns are the same line in the same place, and a
     * fenced column standing to the right of a plain one is fenced by its
     * neighbour's border. The boundary past the last column is nobody's: it is
     * the card's own edge, or the row's actions, which draw the line between
     * themselves and it already — which is also why a fenced column has nothing
     * to draw on its left when it is the first one in the table.
     *
     * Where a pin draws the line too — the shadow that marks the edge the
     * columns scroll under — the border stands down from the desktop up and the
     * shadow has it, so the boundary is one line wide and not two. Below that
     * the pins do not hold and the border is the only line there is.
     */
    const gridline = (column: ColumnDef, index: number) => {
        const next = visible[index + 1];

        if (!next || (!gridlines && !column.fenced && !next.fenced)) return '';

        const side = pinSide(column.key);
        const pinDraws = side === 'left' || pinSide(next.key) === 'right';

        return cn('border-r', pinDraws && 'lg:border-r-0');
    };

    return (
        // As tall as what it holds, and no taller than there is room for.
        //
        // It neither grows nor is given a height: a card of four rows is four
        // rows tall and the page ends under it, instead of a frame standing to
        // the foot of the window with a field of nothing in it. `min-h-0` is
        // what lets it shrink past its own content when the rows outrun the
        // window — the page is locked to the viewport, the card gives way, and
        // the box of rows inside it starts to scroll while the head and the
        // paging stay where they are.
        <Card className="flex flex-col gap-0 overflow-hidden rounded-xl p-0 max-md:rounded-2xl max-md:border-0 max-md:shadow-none md:min-h-0">
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
            <div
                // No reserved gutter here, unlike the panes that scroll inside a
                // dialog or a menu: a table short enough to need no bar would
                // otherwise keep an empty channel down its right-hand edge, in
                // the one place a reader looks for the end of a row. The cost is
                // that the rows shift by the width of a bar when a page of them
                // first runs past the window, which happens once and at the
                // moment the bar itself appears to explain it.
                className={cn('scroll-framed relative overflow-auto md:min-h-0 md:flex-1', mobileRow && 'max-md:hidden')}
                // This box is the one that scrolls, so this is where a cell inside
                // it reads how far down the header reaches.
                style={{ '--table-head': `${headHeight}px` } as CSSProperties}
            >
                <table
                    // While an edge is being dragged the whole table takes the
                    // two-headed arrow and stops selecting text, so that a hand
                    // that wanders off the handle mid-drag — which it will, the
                    // handle being two millimetres wide — neither loses the
                    // cursor nor paints the headers blue on the way.
                    className={cn('min-w-full table-fixed border-collapse text-sm', resizing && 'cursor-col-resize select-none')}
                    style={{ width: tableWidth }}
                >
                    <thead ref={head} className="bg-sidebar sticky top-0 z-30">
                        {/* A name too long for the column it heads wraps, as the
                            cells under it do: a header cut off mid-word says
                            less about its column than two short lines do. */}
                        <tr className="text-muted-foreground text-left text-[13px] break-words whitespace-normal">
                            {visible.map((column, index) => {
                                const canSort = sortable.includes(column.key);
                                // What the header announces as sorted, which is
                                // only ever a column a viewer can sort. A list
                                // may open in the order of a column it does not
                                // offer to sort — the journals open newest
                                // first — and a header that said «descending»
                                // there would promise a control that is not on
                                // it: read out, the column answers "sorted
                                // descending" and then has nothing to press.
                                const active = canSort && sort.key === column.key;
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
                                        // The filling column asks for no width at all, which is
                                        // what makes the browser hand it the room left over.
                                        style={{ width: column === fills ? undefined : widthOf(column), ...stickyStyle(column) }}
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
                                            // relative: the handle that widens the column lies
                                            // along this cell's own right edge.
                                            //
                                            'group/header relative px-4 py-2.5 font-semibold',
                                            index === 0 && 'pl-6',
                                            index === visible.length - 1 && !actions && 'pr-6',
                                            stickyClass(column, true),
                                            gridline(column, index),
                                            dragged === column.key && 'opacity-40',
                                            dropLine(column),
                                        )}
                                    >
                                        {/* The label gives way when a column is dragged narrow:
                                            it is cut short, and the funnel and the menu stay
                                            where they are, since the menu is what a column too
                                            narrow to read is widened again from. */}
                                        <div className="flex min-w-0 items-center gap-0.5">
                                            {canSort ? (
                                                <button
                                                    type="button"
                                                    onClick={() => onSort(column.key)}
                                                    className={cn(
                                                        'hover:text-foreground -ml-1 inline-flex min-w-0 items-center gap-1.5 rounded px-1 py-0.5',
                                                        active && 'text-foreground',
                                                    )}
                                                >
                                                    <span className="truncate">{column.label}</span>
                                                    {/* The arrow stays on a column that is sorted,
                                                        because that is the column saying how the
                                                        table is ordered. On the rest it is an
                                                        offer, and an offer repeated over every
                                                        heading is a row of arrows that mean
                                                        nothing until one of them is pressed. */}
                                                    <SortIcon
                                                        className={cn(
                                                            'size-3.5 shrink-0 transition-opacity',
                                                            active ? 'opacity-100' : 'opacity-0 group-hover/header:opacity-40',
                                                        )}
                                                        aria-hidden="true"
                                                    />
                                                </button>
                                            ) : (
                                                // A column that hands the header something of
                                                // its own — the box that ticks the whole page —
                                                // has that drawn where its name would stand; the
                                                // name is still what every list of columns says.
                                                (column.head ?? <span className="truncate">{column.label}</span>)
                                            )}

                                            <div data-head-controls className="ml-auto flex shrink-0 items-center gap-0.5">
                                                {reorderable && (
                                                    <Hint label="Перетащите, чтобы изменить порядок">
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
                                                    </Hint>
                                                )}

                                                {column.filter && canFilter(column) && (
                                                    <ColumnFilter column={column} filters={filters} onApply={onFilter} wide={wideFilter?.(column)} />
                                                )}

                                                <DropdownMenu>
                                                    <Hint label={canSort ? 'Сортировка и вид колонки' : 'Вид колонки'}>
                                                        <DropdownMenuTrigger asChild>
                                                            <button
                                                                type="button"
                                                                aria-label={`Действия с колонкой: ${column.label}`}
                                                                className={cn(
                                                                    'hover:bg-accent hover:text-foreground rounded p-1 transition-opacity',
                                                                    'opacity-0 group-hover/header:opacity-50 hover:opacity-100',
                                                                    // Open, it stays: the menu itself is
                                                                    // drawn elsewhere on the page, so the
                                                                    // heading is no longer under the
                                                                    // cursor while it is being read.
                                                                    'focus-visible:opacity-100 data-[state=open]:opacity-100',
                                                                )}
                                                            >
                                                                <EllipsisVertical className="size-3.5" />
                                                            </button>
                                                        </DropdownMenuTrigger>
                                                    </Hint>
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

                                                        {/* The drag of an edge in words, for anyone
                                                            not dragging a mouse — and the way back
                                                            for a column dragged too far. The menu
                                                            is held open on a press, because a width
                                                            is arrived at by stepping: closing it
                                                            would make every further step a matter
                                                            of finding the menu again. */}
                                                        {view.resize && (
                                                            <>
                                                                <DropdownMenuItem
                                                                    disabled={drawnWidth(column) >= MAX_COLUMN_WIDTH}
                                                                    onSelect={(event) => {
                                                                        event.preventDefault();
                                                                        view.resize?.(column.key, drawnWidth(column) + WIDTH_STEP);
                                                                    }}
                                                                >
                                                                    <ChevronsLeftRight />
                                                                    Шире
                                                                </DropdownMenuItem>
                                                                <DropdownMenuItem
                                                                    disabled={drawnWidth(column) <= MIN_COLUMN_WIDTH}
                                                                    onSelect={(event) => {
                                                                        event.preventDefault();
                                                                        view.resize?.(column.key, drawnWidth(column) - WIDTH_STEP);
                                                                    }}
                                                                >
                                                                    <ChevronsRightLeft />
                                                                    Уже
                                                                </DropdownMenuItem>
                                                                <DropdownMenuItem
                                                                    disabled={!isResized(column)}
                                                                    onSelect={() => view.resize?.(column.key, null)}
                                                                >
                                                                    <RotateCcw />
                                                                    Ширина по умолчанию
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

                                        {/* The width, dragged. The handle is the column's own
                                            boundary rather than another button in the row of
                                            them: it lies along the cell's right edge, clear of
                                            the grip and the menu, it is the only thing in a
                                            header that answers to a two-headed arrow, and what
                                            it shows under the pointer is a line down the whole
                                            header — where the grip is a hand on a pair of dots,
                                            inside the cell, beside the funnel. A double-press
                                            puts the column back to the width its page gives it.
                                            Nothing here for a keyboard or a screen reader, for
                                            the same reason as the grip: the menu beside it says
                                            the same thing in words. */}
                                        {view.resize && (
                                            <span
                                                aria-hidden="true"
                                                onPointerDown={(event) => startResize(event, column)}
                                                onPointerMove={(event) => moveResize(event, column)}
                                                onLostPointerCapture={() => endResize(column)}
                                                onDoubleClick={() => view.resize?.(column.key, null)}
                                                className="group/resize absolute inset-y-0 right-0 z-10 flex w-2 cursor-col-resize touch-none justify-end select-none"
                                            >
                                                <span
                                                    className={cn(
                                                        'bg-ring w-0.5 opacity-0 group-hover/header:opacity-30 group-hover/resize:opacity-100',
                                                        resizing?.key === column.key && 'opacity-100',
                                                    )}
                                                />
                                            </span>
                                        )}
                                    </th>
                                );
                            })}
                            {actions && (
                                // Width nought, and no rule down its left: there
                                // is nothing under this head to divide from the
                                // column before it. The word stays for whoever
                                // is read the table aloud.
                                <th scope="col" className="w-0 p-0">
                                    <span className="sr-only">Действия</span>
                                </th>
                            )}
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((row) => (
                            // The first row draws no line of its own: the head
                            // already carries one along its bottom edge, and two
                            // lines in one place read as a thicker one.
                            // Under the cursor the row lifts a shade, and fades
                            // back when the cursor leaves it. The row itself is
                            // not what lights up: each cell washes over its own
                            // ground, so the thin colour falls once everywhere
                            // rather than twice over the pinned cells, which
                            // carry a ground of their own.
                            <tr
                                key={rowKey(row)}
                                draggable={onRowDrop !== undefined}
                                onDragStart={
                                    onRowDrop === undefined
                                        ? undefined
                                        : (event) => {
                                              // Firefox begins no drag at all with an empty clipboard.
                                              event.dataTransfer.setData('text/plain', String(rowKey(row)));
                                              event.dataTransfer.effectAllowed = 'move';
                                              setDraggedRow(String(rowKey(row)));
                                          }
                                }
                                onDragOver={onRowDrop === undefined ? undefined : (event) => rowDragOver(event, String(rowKey(row)))}
                                onDrop={
                                    onRowDrop === undefined
                                        ? undefined
                                        : (event) => {
                                              event.preventDefault();

                                              if (draggedRow !== null && rowDropAt?.key === String(rowKey(row))) {
                                                  onRowDrop(draggedRow, rowDropAt.key, rowDropAt.place);
                                              }

                                              clearRowDrag();
                                          }
                                }
                                onDragEnd={onRowDrop === undefined ? undefined : clearRowDrag}
                                // A press anywhere on the row, except on
                                // something that answers presses itself, and the
                                // same from a keyboard: a table row cannot be a
                                // button, so both are written out.
                                tabIndex={onRowClick === undefined ? undefined : 0}
                                onClick={
                                    onRowClick === undefined
                                        ? undefined
                                        : (event) => {
                                              if ((event.target as HTMLElement).closest('a, button, input, label, [role="checkbox"]')) return;

                                              onRowClick(row);
                                          }
                                }
                                onKeyDown={
                                    onRowClick === undefined
                                        ? undefined
                                        : (event) => {
                                              if (event.target !== event.currentTarget) return;
                                              if (event.key !== 'Enter' && event.key !== ' ') return;

                                              // The space bar scrolls the page otherwise, out
                                              // from under the row just pressed.
                                              event.preventDefault();
                                              onRowClick(row);
                                          }
                                }
                                className={cn(
                                    'group/row border-t align-top transition-colors first:border-t-0 hover:bg-(--row-hover)',
                                    onRowClick !== undefined &&
                                        'focus-visible:ring-ring cursor-pointer focus-visible:ring-2 focus-visible:outline-hidden',
                                    rowClass?.(row),
                                    // The row being carried fades, and the one it
                                    // would land against draws a line along the
                                    // edge it would land on: the list says where
                                    // the row is going while the hand is still
                                    // holding it.
                                    onRowDrop !== undefined && 'cursor-grab',
                                    draggedRow === String(rowKey(row)) && 'opacity-40',
                                    rowDropAt?.key === String(rowKey(row)) &&
                                        (rowDropAt.place === 'before'
                                            ? 'shadow-[inset_0_2px_0_var(--color-brand-strong)]'
                                            : 'shadow-[inset_0_-2px_0_var(--color-brand-strong)]'),
                                )}
                            >
                                {visible.map((column, index) => {
                                    const span = rowSpan ? rowSpan(column, row) : 1;

                                    // Covered by a cell further up the column, so
                                    // there is no cell of this row to draw. The row's
                                    // own top border is not drawn across it either —
                                    // the borders are collapsed, and a row border is
                                    // only laid along the cells that begin in that
                                    // row — which is what makes a block of rows read
                                    // as one cell rather than as a run of empty ones.
                                    if (span < 1) return null;

                                    return (
                                        <td
                                            key={column.key}
                                            rowSpan={span > 1 ? span : undefined}
                                            style={stickyStyle(column)}
                                            className={cn(
                                                // A cell says everything it holds: the text
                                                // wraps onto as many lines as the column's
                                                // width asks for, and the row grows to the
                                                // tallest of them. A run of characters with
                                                // nowhere to break — an address, a long
                                                // email — is broken rather than let out past
                                                // the column's edge, and what still will not
                                                // fit is cut there: a cell that spilled would
                                                // write over the column beside it, and over
                                                // the row's own actions at the end.
                                                'overflow-hidden px-4 py-3 break-words whitespace-normal',
                                                index === 0 && 'pl-6',
                                                index === visible.length - 1 && !actions && 'pr-6',
                                                stickyClass(column, false),
                                                gridline(column, index),
                                                dragged === column.key && 'opacity-40',
                                                dropLine(column),
                                            )}
                                        >
                                            {renderCell(column, row)}
                                        </td>
                                    );
                                })}
                                {actions && (
                                    // A cell of no width, holding a box that
                                    // hangs out of it to the left. Pinned to the
                                    // right edge, so it keeps its place while
                                    // the table is scrolled sideways.
                                    <td className="sticky right-0 z-[2] w-0 p-0">
                                        <div
                                            className={cn(
                                                'absolute inset-y-0 right-0 flex items-center',
                                                // Out of the way until the row
                                                // is pointed at — and out of
                                                // reach too, or a press meant
                                                // for the column underneath
                                                // would land on a button nobody
                                                // can see.
                                                'pointer-events-none opacity-0 transition-opacity',
                                                'group-hover/row:pointer-events-auto group-hover/row:opacity-100',
                                                'focus-within:pointer-events-auto focus-within:opacity-100',
                                            )}
                                        >
                                            {/* What the row was showing does not
                                                stop at the buttons: it is let
                                                run under them and faded out, so
                                                the join reads as the row being
                                                covered rather than cut. */}
                                            <span aria-hidden className="h-full w-10 bg-linear-to-r from-transparent to-(--row-hover)" />
                                            <span className="flex h-full items-center gap-1 bg-(--row-hover) pr-4 pl-1">{actions(row)}</span>
                                        </div>
                                    </td>
                                )}
                            </tr>
                        ))}

                        {rows.length === 0 && (
                            <tr className="border-t first:border-t-0">
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
                // The same ground the head stands on: the two bands hold the rows between them and
                // read as one frame. Neither is ruled off from the rows — the change of ground is
                // the edge, and a line on top of it only says the same thing twice.
                <div className="bg-sidebar flex shrink-0 flex-col items-stretch gap-2 px-4 py-2.5 sm:flex-row sm:flex-wrap sm:items-center sm:gap-x-6 sm:gap-y-2 md:px-6 md:py-3">
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
