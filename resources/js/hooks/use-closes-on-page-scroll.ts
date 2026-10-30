import { useEffect, useState } from 'react';

/**
 * A panel that hangs off a control: the page does not scroll while the pointer
 * is on it, and the panel closes if the page scrolls anyway.
 *
 * Both halves are the same thought. A panel like this is anchored to the box
 * that opened it, so a page that scrolls out from under it leaves it pointing
 * at nothing — and a wheel turned while the pointer is on the panel is meant
 * for the panel, not for the page behind it. So the wheel is spent here, and a
 * page that scrolls by any other means — a keystroke, the scrollbar, a touch
 * outside — takes the panel away rather than dragging it along.
 *
 * What is inside the panel still scrolls: a list of options is read by
 * scrolling it, and scrolling it must not close the thing being read. Such a
 * list keeps the page still at its own ends by `overscroll-contain`; what this
 * adds is the rest of the panel, which has nothing to scroll and would
 * otherwise pass the wheel straight through to the page.
 *
 * Returns a ref to put on the panel. It is a callback rather than an object on
 * purpose: the panel is mounted a commit later than the state that opens it,
 * so an effect watching only that state would look for the panel before it
 * existed and find nothing. Held as state, the node itself is what the effect
 * waits for.
 */
export function useClosesOnPageScroll(open: boolean, setOpen: (open: boolean) => void) {
    const [panel, setPanel] = useState<HTMLDivElement | null>(null);

    useEffect(() => {
        if (!open || panel === null) {
            return;
        }

        /** Whether the pointer is over something inside that scrolls on its own. */
        const overAList = (target: EventTarget | null) => {
            for (let el = target instanceof Element ? target : null; el !== null && el !== panel.parentElement; el = el.parentElement) {
                const style = getComputedStyle(el);

                if (/(auto|scroll)/.test(style.overflowY) && el.scrollHeight > el.clientHeight) {
                    return true;
                }
            }

            return false;
        };

        // By hand rather than as a React prop, because React listens for the
        // wheel passively and a passive listener is not allowed to refuse the
        // scroll it is told about.
        const hold = (event: WheelEvent) => {
            if (!overAList(event.target)) {
                event.preventDefault();
            }
        };

        const away = (event: Event) => {
            // Anything floating over the page scrolling is not the page moving:
            // a list of options being read, or a panel this one opened in turn
            // — the month and the year of a calendar are each a panel of their
            // own, standing beside this one rather than inside it, and the list
            // in them scrolls to what is chosen the moment it opens. Asking
            // only whether the scroll happened inside *this* panel shut the
            // calendar the instant its own month was pressed.
            if (event.target instanceof Element && event.target.closest('[data-radix-popper-content-wrapper]') !== null) {
                return;
            }

            setOpen(false);
        };

        panel.addEventListener('wheel', hold, { passive: false });

        // Not on the frame the panel opened on: opening moves the focus into it,
        // and the browser scrolls whatever it focuses into view. That scroll is
        // the panel arriving, not the page leaving, and listening for it a frame
        // too early shuts the panel the moment it opens.
        let listening = false;
        const frame = requestAnimationFrame(() => {
            listening = true;
            window.addEventListener('scroll', away, true);
        });

        return () => {
            cancelAnimationFrame(frame);
            panel.removeEventListener('wheel', hold);

            if (listening) {
                window.removeEventListener('scroll', away, true);
            }
        };
    }, [open, panel, setOpen]);

    return setPanel;
}
