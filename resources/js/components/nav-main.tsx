import { SidebarGroup, SidebarGroupLabel, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type SidebarNavGroup, type SidebarNavItem } from '@/types';
import { Link, usePage } from '@inertiajs/react';

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
                {group.items.map((item) => (
                    <SidebarMenuItem key={item.title}>
                        {item.url ? (
                            <SidebarMenuButton asChild isActive={navItemActive(item, page.url)} tooltip={item.title} className={activeClasses}>
                                <Link href={item.url} prefetch>
                                    <item.icon />
                                    <span>{item.title}</span>
                                </Link>
                            </SidebarMenuButton>
                        ) : (
                            <SidebarMenuButton aria-disabled>
                                <item.icon />
                                <span>{item.title}</span>
                            </SidebarMenuButton>
                        )}
                    </SidebarMenuItem>
                ))}
            </SidebarMenu>
        </SidebarGroup>
    );
}
