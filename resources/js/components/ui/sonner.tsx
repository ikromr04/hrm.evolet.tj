import { useAppearance } from '@/hooks/use-appearance';
import { type CSSProperties } from 'react';
import { Toaster as Sonner, type ToasterProps } from 'sonner';

/**
 * Where the application's short-lived messages appear.
 *
 * The one place a press can be answered without the page moving. A note sent to
 * a colleague, a row marked read, a request filed — nothing of the page changes
 * to say so, and until now the only answer was the banner at the top of the
 * content, which is above the fold and unseen by somebody pressing a button
 * halfway down a long card. The banner stays for what it was written for: a
 * sentence the server left for the *next* page, about a session that expired on
 * the way. This is for what happened just now, under the hand that did it.
 *
 * Mounted once, at the root, so every page has it; the messages themselves are
 * raised from wherever the press is handled, by sonner's own `toast()`.
 *
 * Told light from dark by our own hook rather than by next-themes, which this
 * application does not carry: the class on `<html>` is ours, and so is the
 * stored choice behind it. The colours are the theme's tokens handed over as
 * the three custom properties sonner paints with, so a toast is the same
 * surface as a popover and follows the theme without a stylesheet of its own.
 */
function Toaster({ ...props }: ToasterProps) {
    const { appearance } = useAppearance();

    return (
        <Sonner
            theme={appearance}
            className="toaster group"
            style={
                {
                    '--normal-bg': 'var(--popover)',
                    '--normal-text': 'var(--popover-foreground)',
                    '--normal-border': 'var(--border)',
                } as CSSProperties
            }
            {...props}
        />
    );
}

export { Toaster };
