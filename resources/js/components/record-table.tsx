import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { cn } from '@/lib/utils';
import { type ReactNode } from 'react';

/**
 * A short list of records, read in columns: the heads on a ruled band and the
 * answers underneath.
 *
 * This is the small table the application reads a handful of things in — the
 * schools somebody went to, the jobs they held, the children on their card. It
 * is not the fleet's table: nothing here sorts, filters, hides a column or
 * remembers what the viewer did to it last time, and a list that wants any of
 * that wants `DataTable` instead.
 *
 * It stands on its own and has no name of its own: whatever holds it — a
 * `Panel`, a numbered part of the personnel sheet, a page — has already said
 * what the list is, and a second name inside the first says it twice.
 *
 * A cell is whatever the caller puts in it rather than a string: a diploma is
 * named in one of these, and the name of a paper is the way to the paper.
 */
export function RecordTable({
    head,
    rows,
    widths,
    className,
}: {
    /** The question each column asks, in order. */
    head: string[];
    /** One array of cells per record, in the order of the heads. */
    rows: ReactNode[][];
    /**
     * The columns as parts of the whole — `['38%', '22%', '40%']` — where this
     * table is read beside another asking the same questions.
     */
    widths?: string[];
    className?: string;
}) {
    return (
        <div className={cn('overflow-hidden rounded-lg border', className)}>
            {/* Laid out from the widths where they are given, and from what is
                written in the cells where they are not: a table read on its own
                is best sized by what it holds, and a table read beside another
                one asking the same questions is not — there the column has to
                be the width of the question and not of this card's answer to
                it. */}
            <Table className={cn(widths && 'table-fixed')}>
                {widths && (
                    <colgroup>
                        {widths.map((width, i) => (
                            <col key={i} style={{ width }} />
                        ))}
                    </colgroup>
                )}
                <TableHeader>
                    <TableRow className="bg-muted/40 hover:bg-muted/40">
                        {head.map((cell) => (
                            // Wrapping, because the column is as wide as the
                            // caller said and the question is as long as it is:
                            // a head held to one line in a column narrower than
                            // itself does not widen the column — it runs out of
                            // it and over the head of the next one, which is
                            // what «Дата рождения» and «Работа, учёба» did in
                            // the family's tables beside a sheet.
                            <TableHead key={cell} className="text-muted-foreground h-auto px-3 py-2 text-xs font-normal whitespace-normal">
                                {cell}
                            </TableHead>
                        ))}
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {rows.map((row, index) => (
                        // By where it stands, since a cell may be a link rather
                        // than a word and a row cannot be joined into a name of
                        // its own. The list is drawn whole every time and never
                        // reordered, so the place is the row.
                        <TableRow key={index} className="hover:bg-transparent">
                            {row.map((cell, i) => (
                                <TableCell key={i} className={cn('px-3 py-2.5 align-top break-words whitespace-normal', i === 0 && 'font-medium')}>
                                    {cell}
                                </TableCell>
                            ))}
                        </TableRow>
                    ))}
                </TableBody>
            </Table>
        </div>
    );
}
