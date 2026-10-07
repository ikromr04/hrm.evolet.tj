import { Button } from '@/components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Sheet, SheetContent, SheetDescription, SheetTitle } from '@/components/ui/sheet';
import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import { ArrowDown, ArrowUp, ChevronDown, ChevronRight, SlidersHorizontal } from 'lucide-react';
import { useState, type ReactNode } from 'react';
import { clearedFilter, FilterBody, isFilterActive } from './filters';
import { type ColumnDef, type FilterValues, type Sort } from './types';
import { isSorted } from './view';

/**
 * One row of a list on a phone, where a table of columns would only scroll
 * sideways: a face or an icon, a title, a line under it and, at the right, a
 * short note and the row's menu. With `href` the whole row opens the record.
 */
export function MobileRow({
    href,
    leading,
    title,
    subtitle,
    meta,
    trailing,
}: {
    href?: string | null;
    leading?: ReactNode;
    title: ReactNode;
    subtitle?: ReactNode;
    /** A short note on the right, such as a status or a date. */
    meta?: ReactNode;
    /** The row's own menu; it sits outside the link so a tap on it does not open the record. */
    trailing?: ReactNode;
}) {
    const body = (
        <>
            {leading && <span className="shrink-0">{leading}</span>}
            <span className="flex min-w-0 flex-1 flex-col gap-0.5">
                <span className="truncate text-[15px] leading-tight font-medium">{title}</span>
                {subtitle && <span className="text-muted-foreground truncate text-[13px] leading-tight">{subtitle}</span>}
            </span>
            {meta && <span className="text-muted-foreground shrink-0 text-right text-xs">{meta}</span>}
        </>
    );
    const main = 'flex min-h-14 min-w-0 flex-1 items-center gap-3 py-2 pl-4';

    return (
        <li className="flex items-center">
            {href ? (
                <Link href={href} prefetch className={cn(main, 'active:bg-accent/60', !trailing && 'pr-3')}>
                    {body}
                    {!trailing && <ChevronRight className="text-muted-foreground/60 size-4 shrink-0" aria-hidden="true" />}
                </Link>
            ) : (
                <div className={cn(main, !trailing && 'pr-4')}>{body}</div>
            )}
            {trailing && <span className="shrink-0 pr-2 pl-1">{trailing}</span>}
        </li>
    );
}

/**
 * Sorting and filtering on a phone. Column headers are not drawn there, so the
 * funnels and arrows that live in them on a desktop gather in one sheet behind
 * a single button; the count on it says how many filters are narrowing the list.
 */
export function MobileListTools({
    columns,
    filters,
    onFilter,
    canFilter = (column) => Boolean(column.filter),
    sort,
    sortable,
    onSort,
    defaultSort,
    defaultSortLabel,
    onReset,
    className,
}: {
    columns: ColumnDef[];
    filters: FilterValues;
    onFilter: (changes: FilterValues) => void;
    canFilter?: (column: ColumnDef) => boolean;
    sort: Sort;
    sortable: string[];
    onSort: (key: string, direction?: 'asc' | 'desc') => void;
    /** The order the list opens in; sorted otherwise, the list counts as changed and "reset all" brings it back. */
    defaultSort?: Sort;
    /** Names the opening order when it reads no column of the table, such as the date a row was added. */
    defaultSortLabel?: string;
    /** Clears the filters and the sorting in one visit, so neither undoes the other. */
    onReset?: () => void;
    className?: string;
}) {
    const [open, setOpen] = useState(false);
    const [expanded, setExpanded] = useState<string | null>(null);

    const filterable = columns.filter((column) => column.filter && canFilter(column));
    const sortColumns = columns.filter((column) => sortable.includes(column.key));
    const active = filterable.filter((column) => isFilterActive(column.filter!, filters));
    const changed = active.length + (defaultSort && isSorted(sort, defaultSort) ? 1 : 0);

    return (
        <>
            <Button
                type="button"
                variant="outline"
                size="icon"
                onClick={() => setOpen(true)}
                aria-label={changed > 0 ? `Сортировка и фильтры: ${changed}` : 'Сортировка и фильтры'}
                className={cn('relative size-10 shrink-0 md:hidden', className)}
            >
                <SlidersHorizontal className="size-[18px]" />
                {changed > 0 && (
                    <span className="bg-primary text-primary-foreground absolute -top-1.5 -right-1.5 flex size-5 items-center justify-center rounded-full text-[11px] font-semibold">
                        {changed}
                    </span>
                )}
            </Button>

            <Sheet open={open} onOpenChange={setOpen}>
                <SheetContent
                    side="bottom"
                    className="flex max-h-[88dvh] flex-col gap-0 rounded-t-2xl p-0 pb-[env(safe-area-inset-bottom)] md:hidden [&>button:last-child]:top-4 [&>button:last-child]:right-4"
                >
                    <div className="bg-muted-foreground/30 mx-auto mt-2 h-1 w-10 shrink-0 rounded-full" aria-hidden="true" />
                    <div className="flex items-center justify-between px-4 pt-2 pb-3">
                        <SheetTitle className="text-[17px]">Сортировка и фильтры</SheetTitle>
                    </div>
                    <SheetDescription className="sr-only">Порядок строк и условия отбора</SheetDescription>

                    <div className="scroll-soft flex-1 overflow-y-auto px-4 pb-4">
                        {sortColumns.length > 0 && (
                            // One row, not a line per column: a long list of columns would
                            // push the filters, which are what people come here for, off screen.
                            <>
                                <p className="text-muted-foreground mb-1.5 px-1 text-xs font-semibold tracking-wide uppercase">Сортировка</p>
                                <div className="mb-5 flex items-center gap-2">
                                    <Select value={sort.key} onValueChange={(key) => onSort(key, sort.direction)}>
                                        <SelectTrigger
                                            aria-label="Сортировать по"
                                            className="bg-muted/60 h-11 flex-1 rounded-xl border-0 text-[15px]"
                                        >
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {defaultSort && defaultSortLabel && <SelectItem value={defaultSort.key}>{defaultSortLabel}</SelectItem>}
                                            {sortColumns.map((column) => (
                                                <SelectItem key={column.key} value={column.key}>
                                                    {column.label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <div className="bg-muted/60 flex h-11 shrink-0 items-center rounded-xl p-1">
                                        {(['asc', 'desc'] as const).map((direction) => (
                                            <button
                                                key={direction}
                                                type="button"
                                                onClick={() => onSort(sort.key, direction)}
                                                aria-label={direction === 'asc' ? 'По возрастанию' : 'По убыванию'}
                                                aria-pressed={sort.direction === direction}
                                                className={cn(
                                                    'flex size-9 items-center justify-center rounded-lg',
                                                    sort.direction === direction
                                                        ? 'bg-background text-foreground shadow-xs'
                                                        : 'text-muted-foreground',
                                                )}
                                            >
                                                {direction === 'asc' ? <ArrowUp className="size-4" /> : <ArrowDown className="size-4" />}
                                            </button>
                                        ))}
                                    </div>
                                </div>
                            </>
                        )}

                        {filterable.length > 0 && (
                            <>
                                <p className="text-muted-foreground mb-1.5 px-1 text-xs font-semibold tracking-wide uppercase">Фильтры</p>
                                <div className="bg-muted/60 divide-border/70 flex flex-col divide-y overflow-hidden rounded-xl">
                                    {filterable.map((column) => {
                                        const on = isFilterActive(column.filter!, filters);
                                        const isOpen = expanded === column.key;

                                        return (
                                            <div key={column.key}>
                                                <button
                                                    type="button"
                                                    onClick={() => setExpanded(isOpen ? null : column.key)}
                                                    aria-expanded={isOpen}
                                                    className="flex min-h-12 w-full items-center gap-2 px-3 text-left text-[15px]"
                                                >
                                                    <span className="flex-1">{column.label}</span>
                                                    {on && <span className="bg-brand size-2 rounded-full" aria-label="включён" />}
                                                    <ChevronDown
                                                        className={cn('text-muted-foreground size-4 transition-transform', isOpen && 'rotate-180')}
                                                    />
                                                </button>
                                                {isOpen && (
                                                    <div className="bg-background mx-2 mb-2 rounded-lg p-3">
                                                        <FilterBody filter={column.filter!} filters={filters} onApply={onFilter} />
                                                        {on && (
                                                            <button
                                                                type="button"
                                                                onClick={() => onFilter(clearedFilter(column.filter!))}
                                                                className="text-muted-foreground mt-2 w-full py-1.5 text-center text-sm"
                                                            >
                                                                Сбросить этот фильтр
                                                            </button>
                                                        )}
                                                    </div>
                                                )}
                                            </div>
                                        );
                                    })}
                                </div>
                            </>
                        )}
                    </div>

                    <div className="flex gap-2 border-t px-4 py-3">
                        {changed > 0 && (
                            <Button
                                variant="outline"
                                className="h-11 flex-1"
                                onClick={() =>
                                    onReset ? onReset() : onFilter(Object.assign({}, ...active.map((column) => clearedFilter(column.filter!))))
                                }
                            >
                                Сбросить всё
                            </Button>
                        )}
                        <Button className="h-11 flex-1" onClick={() => setOpen(false)}>
                            Готово
                        </Button>
                    </div>
                </SheetContent>
            </Sheet>
        </>
    );
}
