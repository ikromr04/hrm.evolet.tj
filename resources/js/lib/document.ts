/**
 * What a document of fixed sections is made of, and how it reads.
 *
 * Two of them are kept this way — a department's «Положение о работе» and a
 * position's «Профиль должности» — and they are the same thing twice: a seeded
 * standard text, the owner's own wording where it was rewritten, an order of its
 * own, a heading of its own, and no way to add or lose a section. So the shapes
 * are said once here and both pages speak them.
 *
 * This is the half everybody loads: the payload and the stylesheet a section is
 * read through. The editor is the other half, fetched only by somebody who opens
 * one — which is why none of it lives beside this.
 */

export interface DocumentSection {
    id: number;
    /** The standard text, this owner's wording of it, or a section only it has. */
    origin: 'template' | 'override' | 'own';
    template_section_id: number | null;
    /** Where the section stands in the document, said as the document says it. */
    number: string;
    title: string;
    order: number;
    /** As it is read: sanitized by the server, placeholders answered. */
    body: string | null;
    /** As it was typed, braces and all — what the editor puts back in the box. */
    body_written: string | null;
    /**
     * Whether the application writes this section itself out of what it already
     * knows. Such a section has no body and nothing to type into it; the page
     * that owns the document draws it.
     */
    computed?: boolean;
}

/** A document as a page receives it. */
export interface Paper {
    can_edit: boolean;
    /**
     * What the document calls itself, as this owner has it. The server answers
     * it already defaulted, so there is always something to draw, and the
     * capitals in it are the owner's own — never added here, or what somebody
     * typed and what they read back would differ.
     */
    title: string;
    sections: DocumentSection[];
}

/**
 * Which section is being written, and whether this owner has a text of its own
 * there yet.
 *
 * A standard section edited for the first time is written as a copy — the save
 * carries the section it stands for, and after it the owner has a text of its
 * own in that place. Whoever is typing is simply editing this document; which of
 * the two requests it takes is nobody's business but the form's.
 *
 * There is no third mode: a document is the sections it is made of, and nothing
 * here adds another.
 */
export type Editing = { mode: 'standard' | 'theirs'; section: DocumentSection };

/**
 * Where a document's own addresses are, by the names the routes are registered
 * under. Both documents answer the same verbs, so the card asks for them rather
 * than knowing either.
 */
export interface Addresses {
    title: string;
    store: string;
    update: string;
    destroy: string;
}

/**
 * How the document reads: paragraphs at the measure of a printed page, clauses
 * as a numbered list, and a table ruled the way the sheet this document came
 * from ruled its two columns.
 *
 * One string, laid over both the section as it is read and the inside of the
 * editor, so that what somebody types is what everybody else will see. A cell
 * breaks its words rather than widening the table: a document opened on a phone
 * must not push the page sideways.
 */
export const BODY = [
    'text-sm leading-relaxed',
    '[&_p]:my-0 [&_p+p]:mt-2',
    // Headings inside a section, so they are smaller than the section's own
    // title: this is the third level of a document, not the first.
    '[&_h2]:mt-4 [&_h2]:mb-2 [&_h2]:text-[15px] [&_h2]:font-semibold [&_h2]:first:mt-0',
    '[&_h3]:mt-3 [&_h3]:mb-1.5 [&_h3]:text-sm [&_h3]:font-semibold',
    '[&_ol]:my-2 [&_ol]:list-outside [&_ol]:list-decimal [&_ol]:pl-6',
    '[&_ul]:my-2 [&_ul]:list-outside [&_ul]:list-disc [&_ul]:pl-6',
    '[&_li]:pl-1 [&_li+li]:mt-1.5 [&_li]:marker:tabular-nums',
    '[&_li>ol]:my-1.5 [&_li>ul]:my-1.5',
    '[&_blockquote]:border-border [&_blockquote]:my-2 [&_blockquote]:border-l-2 [&_blockquote]:pl-3 [&_blockquote]:italic',
    '[&_hr]:border-border [&_hr]:my-4',
    '[&_a]:text-brand-strong [&_a]:underline [&_a]:underline-offset-2 dark:[&_a]:text-[#C5E27A]',
    // A highlight is a pale wash whichever theme the reader is in, so the words
    // on it are dark in both; the page's own colour would vanish into it.
    '[&_mark]:rounded-sm [&_mark]:px-0.5 [&_mark]:text-black',
    '[&_table]:border-border [&_table]:my-3 [&_table]:w-full [&_table]:table-fixed [&_table]:border-collapse [&_table]:border',
    '[&_th]:border-border [&_th]:bg-muted/50 [&_th]:border [&_th]:px-3 [&_th]:py-2 [&_th]:text-left [&_th]:align-top [&_th]:font-medium [&_th]:break-words',
    '[&_td]:border-border [&_td]:border [&_td]:px-3 [&_td]:py-2 [&_td]:align-top [&_td]:break-words',
    '[&_strong]:font-semibold',
].join(' ');

/** The measure of a line of the document, read or written. */
export const MEASURE = 'max-w-[72ch]';

/**
 * The same measure, laid on the blocks rather than on the box around them.
 *
 * What is written in is wider than what is written: the toolbar above it needs
 * its whole row, and an editable surface narrowed to the measure would leave a
 * strip beside the text where a click lands on nothing. So the surface fills its
 * frame and each paragraph, list and table in it keeps the width a line should
 * be read at.
 */
export const MEASURE_BLOCKS = '[&>*]:max-w-[72ch]';
