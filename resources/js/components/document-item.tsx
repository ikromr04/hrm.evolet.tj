import { type DocumentRow } from '@/components/employee-documents';
import { fileSize } from '@/lib/documents';
import { cn } from '@/lib/utils';
import { CircleAlert, CircleCheck, CircleDashed, Clock, type LucideIcon } from 'lucide-react';

/**
 * Where one of the papers the company asks for stands.
 *
 * Four states and no fifth: it is accepted, it is waiting for somebody to look
 * at it, it was sent back, or nobody has handed it in. «Not handed in» is a
 * state like the others rather than an absence — the line exists because the
 * company asks for the paper, and it is read in the same list as the rest.
 */
export type DocumentState = 'approved' | 'pending' | 'rejected' | 'missing';

/**
 * What each state is called and the mark it is read by.
 *
 * One family of circles in the palette's own four colours, because the four
 * lines stand under one another in one block: a tick, a clock, an exclamation
 * and an outline nobody has filled in. The mark never has to carry the state on
 * its own — the heading of the group says it in writing, and the window the
 * line opens says who decided it and when.
 */
export const documentStates: Record<DocumentState, { title: string; icon: LucideIcon; tone: string }> = {
    approved: { title: 'Принят', icon: CircleCheck, tone: 'text-brand-strong dark:text-[#C5E27A]' },
    pending: { title: 'На рассмотрении', icon: Clock, tone: 'text-[#3B82F6]' },
    rejected: { title: 'Отклонён', icon: CircleAlert, tone: 'text-destructive' },
    missing: { title: 'Не вложен', icon: CircleDashed, tone: 'text-[#F5A524]' },
};

/** Which of the four a row is in: its own answer, or none at all. */
export const documentState = (row: DocumentRow): DocumentState => row.status ?? 'missing';

/**
 * One paper on a card: where it stands, what it is called, and the whole line
 * to press.
 *
 * Every paper is one line, whichever of the four it is in. A card is read to
 * find out what is still wanted of somebody, and a list answers that at a
 * glance only while its lines are the same height — the files attached to a
 * paper, who passed it and what it was sent back for are all read about one
 * paper at a time, which is what the window the line opens is for.
 *
 * There is one thing to do about a line, so the line does it rather than
 * carrying a sign that does it: a list of fourteen papers would otherwise be a
 * column of fourteen signs down the right of the block, each of them a smaller
 * target than the line it sits on.
 */
export function DocumentItem({
    row,
    documents,
    onOpen,
}: {
    row: DocumentRow;
    /** The papers of this card and what this viewer may do with them. */
    documents: { canEdit: boolean; canReview: boolean };
    /** Open the window this paper is read and answered in. */
    onOpen: () => void;
}) {
    const state = documentState(row);
    const { icon: Mark, tone } = documentStates[state];

    const inside = (
        <>
            <Mark className={cn('size-4 shrink-0', tone)} />
            <span className="min-w-0 flex-1 text-sm break-words">
                {row.name}
                {/* Marked where it is wanted of everybody and not here yet:
                    once it is attached, that it was obligatory is no longer
                    anything to act on. */}
                {row.is_required && state === 'missing' && <span className="text-destructive"> *</span>}
            </span>

            {/* What is on file, weighed: the whole paper rather than the files
                it is made of, since the line names the paper and the window
                behind it names the files. A paper nobody has handed in weighs
                nothing and says nothing. */}
            {row.files.length > 0 && (
                <span className="text-muted-foreground shrink-0 text-[13px] tabular-nums">
                    {fileSize(row.files.reduce((weight, file) => weight + file.size, 0))}
                </span>
            )}
        </>
    );

    const line = 'bg-muted/40 flex w-full min-w-0 items-center gap-3 rounded-lg border px-2 py-1.5 text-left transition-colors';

    // Pressable for whoever may do something about it, and for whoever may pass
    // papers. For anybody else the line is a line: it says what is on file, and
    // a window that opened on nothing but what it already says is a window
    // nobody wanted opened.
    return documents.canEdit || documents.canReview ? (
        <button type="button" className={cn(line, 'hover:bg-muted')} onClick={onOpen}>
            {inside}
        </button>
    ) : (
        <div className={line}>{inside}</div>
    );
}
