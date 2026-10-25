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
    /** The one account outside that list, for the few things that are not rights. */
    sysadmin: boolean;
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
     * The entries under this one. A section holding several pages names them
     * here and keeps them behind its own chevron rather than spelling them out
     * in the menu's first level.
     */
    items?: SidebarNavItem[];
}

export interface SidebarNavGroup {
    title?: string;
    items: SidebarNavItem[];
}

export interface SharedData {
    name: string;
    quote: { message: string; author: string };
    auth: Auth;
    /** The number beside the bell; the list itself is fetched when the bell opens. */
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
    sex: 'male' | 'female';
    email: string;
    email_verified_at: string | null;
    created_at: string;
    updated_at: string;
    [key: string]: unknown; // This allows for additional properties...
}
