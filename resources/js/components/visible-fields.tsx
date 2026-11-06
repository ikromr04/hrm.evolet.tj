import { createContext, useContext } from 'react';

/**
 * Which lines of the card being drawn this viewer reads.
 *
 * A position is given the lines it needs, so a card is drawn from the answer
 * rather than from one flag: whatever is not on the list is not shown, and a
 * block with nothing left on it is not shown either.
 *
 * It lives here rather than beside the card it was written for, because the
 * block that asks it — {@link Panel} — is the furniture of every page now, and
 * furniture cannot import the page it stands on.
 */
export const VisibleFields = createContext<string[] | null>(null);

/**
 * Whether a line is this viewer's to read. A field nobody asked about always
 * is, and so is every field where no list was given at all — a form rendered
 * outside a card hides nothing.
 */
export function useShows(): (field?: string) => boolean {
    const visible = useContext(VisibleFields);

    return (field) => field === undefined || visible === null || visible.includes(field);
}
