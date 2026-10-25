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
export function navItemActive(item: SidebarNavItem, url: string): boolean {
    return [item.url, ...(item.match ?? [])].some((address) => {
        if (address === undefined) return false;

        // The main page is the root address, which opens every other one:
        // matched by the prefix it would light up on all of them.
        return address === '/' ? url === '/' || url.startsWith('/?') : url.startsWith(address);
    });
}

// max-md:h-10: in the phone's drawer the rows are finger targets.
const activeClasses =
    'max-md:h-10 data-[active=true]:bg-brand-soft data-[active=true]:text-foreground data-[active=true]:[&>svg]:text-brand-strong dark:data-[active=true]:bg-sidebar-accent';

export function NavMain({ group, className }: { group: SidebarNavGroup; className?: string }) {
    const page = usePage();

    return (
        <SidebarGroup className={className ?? 'px-2 py-0'}>
            {group.title && <SidebarGroupLabel>{group.title}</SidebarGroupLabel>}
            <SidebarMenu>
                {group.items.map((item) =>
                    item.items ? (
                        <NavBranch key={item.title} item={item} url={page.url} />
                    ) : (
                        <SidebarMenuItem key={item.title}>
                            {item.url ? (
                                <SidebarMenuButton asChild isActive={navItemActive(item, page.url)} tooltip={item.title} className={activeClasses}>
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
function NavBranch({ item, url }: { item: SidebarNavItem; url: string }) {
    const inside = (item.items ?? []).some((one) => one.url && navItemActive(one, url));

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
                                    <SidebarMenuSubButton asChild isActive={navItemActive(one, url)}>
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
