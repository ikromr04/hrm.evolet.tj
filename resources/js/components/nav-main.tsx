import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarMenuSub,
    SidebarMenuSubButton,
    SidebarMenuSubItem,
} from '@/components/ui/sidebar';
import { type SidebarNavGroup, type SidebarNavItem } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import { useLayoutEffect, useRef, useState } from 'react';

/** Whether the page being read is one of the entry's own — its address, or one it also answers for. */
export function navItemActive(item: SidebarNavItem, url: string, says?: string): boolean {
    const here = url.split('?')[0];

    return [item.url, ...(item.match ?? [])].some((address) => {
        if (address === undefined) return false;

        // A page may name the entry it belongs to, for pages the address
        // cannot tell apart: a unit's card is at /equipment/42 whichever part
        // of the stock it is of, and a desk belongs under «Мебель».
        if (says !== undefined) return address.split('?')[0] === says;

        // The address of an entry may carry the sorting and the filters the
        // company reads that list with; which list it is is the path alone, and
        // a reader who has sorted it another way is still reading that list.
        const path = address.split('?')[0];

        // The main page is the root address, which opens every other one:
        // matched by the prefix it would light up on all of them.
        if (path === '/') return here === '/';

        // A page that other pages live under — the front page of a section —
        // is itself and nothing else: «Обзор» at /inventory would otherwise
        // light up on every page of «Инвентаризация» beside the one being read.
        return item.exact ? here === path : here.startsWith(path);
    });
}

/**
 * The page being read, marked in the green the badges of a job title wear —
 * `statusTones.success`, the same two colours in both themes, so that one green
 * means «это ты читаешь» and «вот эта должность» all over the app rather than
 * two greens a shade apart.
 */
const activeMark = 'data-[active=true]:bg-[#EEF5DC] dark:data-[active=true]:bg-[#A8CF45]/15';

// max-md:h-10: in the phone's drawer the rows are finger targets.
const activeClasses = `max-md:h-10 ${activeMark} data-[active=true]:text-foreground data-[active=true]:[&>svg]:text-brand-strong`;

/** The same mark on a page inside a section, which wears no icon of its own. */
const activeSubClasses = `${activeMark} data-[active=true]:text-foreground`;

export function NavMain({ group, className }: { group: SidebarNavGroup; className?: string }) {
    const page = usePage<{ menu?: string }>();
    // Which entry of the menu the page being read says it belongs to, where it
    // says anything: pages of one section that no address tells apart.
    const says = page.props.menu;

    return (
        <SidebarGroup className={className ?? 'px-2 py-0'}>
            {group.title && <SidebarGroupLabel>{group.title}</SidebarGroupLabel>}
            <SidebarMenu>
                {group.items.map((item) =>
                    item.items ? (
                        <NavBranch key={item.title} item={item} url={page.url} says={says} />
                    ) : (
                        <SidebarMenuItem key={item.title}>
                            {item.url ? (
                                <SidebarMenuButton
                                    asChild
                                    isActive={navItemActive(item, page.url, says)}
                                    tooltip={item.title}
                                    className={activeClasses}
                                >
                                    <Link href={item.url} prefetch>
                                        {item.icon && <item.icon />}
                                        <span>{item.title}</span>
                                    </Link>
                                </SidebarMenuButton>
                            ) : (
                                // A section that is not built yet: the entry keeps
                                // its place in the order, greyed and unpressable, so
                                // the menu says what is coming without promising it
                                // opens today.
                                <SidebarMenuButton aria-disabled tooltip={`${item.title} — скоро`} className="opacity-50 hover:bg-transparent">
                                    {item.icon && <item.icon />}
                                    <span>{item.title}</span>
                                </SidebarMenuButton>
                            )}
                        </SidebarMenuItem>
                    ),
                )}
            </SidebarMenu>
        </SidebarGroup>
    );
}

/**
 * An entry that holds a few of its own: the section on one line, its pages
 * under it, and a chevron that turns as they are opened and shut.
 *
 * It opens by itself when the page being read is one of them, so somebody who
 * follows a link into a section finds the menu already saying where they are.
 * Collapsed to icons the sidebar hides the lot, as it hides every sub-menu.
 */
function NavBranch({ item, url, says }: { item: SidebarNavItem; url: string; says?: string }) {
    const inside = (item.items ?? []).some((one) => one.url && navItemActive(one, url, says));
    const branch = useRef<HTMLLIElement>(null);
    const [open, setOpen] = useState(inside);
    /** The section the page is in opens by itself; nothing was pressed, so nothing moves. */
    const pressed = useRef(false);

    /**
     * A section near the foot of a menu that scrolls opens below what can be
     * seen: the arrow turns, the pages appear, and nothing on screen changes —
     * which reads as a section that refused to open. So the menu comes down to
     * the whole of it, and only as far as it must: a section already in view
     * stays where it is.
     *
     * Measured rather than waited for. The pages take a fifth of a second to
     * unfold, and a scroll that waited for them would read as the menu thinking
     * it over; their full height is known the moment they are in the document,
     * so the menu moves by it at once and the pages unfold into the room made
     * for them.
     */
    useLayoutEffect(() => {
        const row = branch.current;
        const pages = row?.querySelector<HTMLElement>('[data-sidebar="menu-sub"]');
        const menu = row?.closest<HTMLElement>('[data-sidebar="content"]');
        if (!open || !pressed.current || !row || !pages || !menu) return;

        // The pages are in the document but still folded up, so the row is
        // measured without them and their own height added to it.
        const below = row.getBoundingClientRect().bottom + pages.scrollHeight - menu.getBoundingClientRect().bottom;
        // A little under the last page, so it does not sit on the very edge.
        if (below > 0) menu.scrollTop += below + 8;
    }, [open]);

    return (
        <Collapsible
            asChild
            open={open}
            onOpenChange={(next) => {
                pressed.current = true;
                setOpen(next);
            }}
            className="group/branch"
        >
            <SidebarMenuItem ref={branch}>
                <CollapsibleTrigger asChild>
                    <SidebarMenuButton tooltip={item.title} className={activeClasses}>
                        {item.icon && <item.icon />}
                        <span>{item.title}</span>
                        <ChevronRight className="ml-auto transition-transform duration-200 group-data-[state=open]/branch:rotate-90" />
                    </SidebarMenuButton>
                </CollapsibleTrigger>

                <CollapsibleContent>
                    <SidebarMenuSub>
                        {(item.items ?? []).map((one) => (
                            <SidebarMenuSubItem key={one.title}>
                                {one.url ? (
                                    <SidebarMenuSubButton asChild isActive={navItemActive(one, url, says)} className={activeSubClasses}>
                                        <Link href={one.url} prefetch>
                                            <span>{one.title}</span>
                                        </Link>
                                    </SidebarMenuSubButton>
                                ) : (
                                    // Not built yet: the page holds its place in the
                                    // order, greyed and unpressable, as its section does.
                                    <SidebarMenuSubButton aria-disabled title={`${one.title} — скоро`} className="opacity-50 hover:bg-transparent">
                                        <span>{one.title}</span>
                                    </SidebarMenuSubButton>
                                )}
                            </SidebarMenuSubItem>
                        ))}
                    </SidebarMenuSub>
                </CollapsibleContent>
            </SidebarMenuItem>
        </Collapsible>
    );
}
