import { LucideIcon } from 'lucide-react';

export interface Auth {
    /**
     * Null for a visitor who is not signed in. Almost every page here is behind
     * the door and may read this without asking; the page for a wrong address is
     * shown on both sides of it, so it asks.
     */
    user: User | null;
    /** Every right in the catalogue with a yes or a no; see lib/access.ts. */
    can: Record<string, boolean>;
    /**
     * Whether this account passes every check whatever the list above says — a
     * system administrator or an HR specialist — for the few things that are
     * not rights at all.
     */
    everything: boolean;
    /**
     * Whether this account is shown its own card and nothing else — a head of
     * department or a specialist. The menu holds one group for them, since
     * every other section is a page they would be refused.
     */
    ownCardOnly: boolean;
    /**
     * The number beside the bell; the list itself is fetched when the bell
     * opens. Nought for a visitor who is not signed in, so the bar may read it
     * without asking whether there is anybody there.
     */
    notifications: number;
}

export interface BreadcrumbItem {
    title: string;
    href: string;
}

export interface NavGroup {
    title: string;
    items: NavItem[];
}

export interface NavItem {
    title: string;
    url: string;
    icon?: LucideIcon | null;
    isActive?: boolean;
}

export interface SidebarNavItem {
    title: string;
    /** The drawing beside it. A page inside a section is named and nothing else. */
    icon?: LucideIcon;
    /** Omit for modules that are not built yet; the item renders as disabled. */
    url?: string;
    /**
     * Addresses that light the entry up besides its own, for a section whose
     * pages do not all live under one of them: the menu should still say where
     * one is.
     */
    match?: string[];
    /**
     * Whether this entry is the page at its address and no page under it: for
     * the front page of a section, which other pages of that section live
     * beneath without being it.
     */
    exact?: boolean;
    /**
     * The entries under this one. A section holding several pages names them
     * here and keeps them behind its own chevron rather than spelling them out
     * in the menu's first level.
     */
    items?: SidebarNavItem[];
    /**
     * A figure beside the name, for a section that is waiting on somebody —
     * the unread notes, and nothing else so far.
     *
     * Only what is worth interrupting a reader over belongs here: a menu where
     * three entries carry numbers is a menu of numbers, and the one that
     * matters is lost among them. Nought is not a figure to show, so the entry
     * leaves it out rather than drawing a nought.
     */
    badge?: number;
}

export interface SidebarNavGroup {
    title?: string;
    items: SidebarNavItem[];
}

export interface SharedData {
    name: string;
    quote: { message: string; author: string };
    auth: Auth;
    /** What one step of a multi-step form hands to the next. */
    flash: {
        employee: { id: number; name: string } | null;
        equipment: { id: number; name: string; inventory_number: string } | null;
        /** A sentence for whoever lands back on a page after something went sideways. */
        notice: string | null;
    };
    [key: string]: unknown;
}

export interface User {
    id: number;
    name: string;
    surname: string;
    patronymic: string | null;
    avatar: string | null;
    /** Null where the card leaves it unsaid. */
    sex: 'male' | 'female' | null;
    email: string;
    email_verified_at: string | null;
    created_at: string;
    updated_at: string;
    [key: string]: unknown; // This allows for additional properties...
}
