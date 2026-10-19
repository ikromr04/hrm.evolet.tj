import { createContext, useContext, useState, type ReactNode } from 'react';
import { createPortal } from 'react-dom';

const ActionSlot = createContext<HTMLDivElement | null>(null);

/**
 * The end of a shell's row of tabs, lent to the page under it.
 *
 * A list owns the button that adds to it — only the list knows what it adds,
 * who may, and what window that opens — but the design puts the button on the
 * line of tabs, which the shell owns. So the shell leaves an empty place at the
 * end of that line and the page fills it from where it stands. The place is the
 * desktop's: on a phone the line is a scrolling strip of chips with no room at
 * its end, and a list there offers its own button instead.
 */
export function PageActionsProvider({ children }: { children: (target: ReactNode) => ReactNode }) {
    const [slot, setSlot] = useState<HTMLDivElement | null>(null);

    return (
        <ActionSlot.Provider value={slot}>
            {children(<div ref={setSlot} className="ml-auto flex shrink-0 items-center gap-2 max-md:hidden" />)}
        </ActionSlot.Provider>
    );
}

/** What a page wraps its own buttons in to put them there. */
export function PageActions({ children }: { children: ReactNode }) {
    const slot = useContext(ActionSlot);

    return slot ? createPortal(children, slot) : null;
}
