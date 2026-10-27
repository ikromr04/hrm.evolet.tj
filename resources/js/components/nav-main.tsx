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

// max-md:h-10: in the phone's drawer the rows are finger targets.
const activeClasses =
    'max-md:h-10 data-[active=true]:bg-brand-soft data-[active=true]:text-foreground data-[active=true]:[&>svg]:text-brand-strong dark:data-[active=true]:bg-sidebar-accent';

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

    return (
        <Collapsible asChild defaultOpen={inside} className="group/branch">
            <SidebarMenuItem>
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
                                    <SidebarMenuSubButton asChild isActive={navItemActive(one, url, says)}>
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
