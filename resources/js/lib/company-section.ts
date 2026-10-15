import { companySections, useCan, type CompanySection } from '@/lib/access';
import { useSyncExternalStore } from 'react';

/**
 * Which tab of "Структура компании" the viewer was last on, so that the section
 * opens where they left it. The key is the section's, not a page's: the whole
 * point is that it outlives leaving the section altogether.
 */
const STORAGE_KEY = 'company.section';

/**
 * Whoever is drawing the tab down — the menu, which points at it — is told as
 * soon as it changes, because the two live far apart in the tree and the menu
 * must not be left pointing at the page before this one.
 */
const listeners = new Set<() => void>();

function subscribe(listener: () => void): () => void {
    listeners.add(listener);
    // Another tab of the browser writing it down is the same news.
    window.addEventListener('storage', listener);

    return () => {
        listeners.delete(listener);
        window.removeEventListener('storage', listener);
    };
}

export function rememberSection(key: string) {
    try {
        localStorage.setItem(STORAGE_KEY, key);
    } catch {
        // Storage can be unavailable (private mode); the tab just won't persist.
    }

    listeners.forEach((listener) => listener());
}

/** The key as it stands, or nothing at all where storage cannot be read. */
function readSection(): string | null {
    try {
        return localStorage.getItem(STORAGE_KEY);
    } catch {
        // No storage to read: the section is as good as never visited.
        return null;
    }
}

/**
 * The tab written down, as long as it is still one this viewer may open. A right
 * taken away since leaves nothing to go back to — and nothing to send anybody
 * to — so the answer is then the same as having never been here.
 */
export function savedSection(offered: CompanySection[]): CompanySection | null {
    const saved = readSection();

    return offered.find((section) => section.key === saved) ?? null;
}

/**
 * The tabs this viewer may open, in the order they stand in. A tab that led to a
 * refusal would be worse than no tab, so a right is asked for where a list has
 * one; the chart has none, being open to everybody who signs in.
 */
export function useCompanyTabs(): CompanySection[] {
    const can = useCan();

    return companySections.filter((section) => !section.view || can(section.view));
}

/**
 * Where "Структура компании" in the menu leads: the tab last open, so that one
 * press is one visit. The shell forwards a bare /departments to the same place
 * for whoever arrives by a typed address or a bookmark, but that forward is a
 * second visit, and a second visit is the heavy chart drawn and swept away in
 * front of the person who asked for something else.
 *
 * The menu is drawn before any of this has run, and reading storage can throw,
 * so it watches the key rather than reading it once: until there is an answer,
 * and whenever there is none to be had, the entry points at the section's own
 * address, which is where it has always pointed.
 */
export function useRememberedSectionHref(): string {
    const tabs = useCompanyTabs();
    const saved = useSyncExternalStore(subscribe, readSection, () => null);

    return tabs.find((section) => section.key === saved)?.href ?? companySections[0].href;
}
