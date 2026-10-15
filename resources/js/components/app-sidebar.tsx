import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { companySections, seesDirectories, seesEquipment, useCan, type Permission } from '@/lib/access';
import { useRememberedSectionHref } from '@/lib/company-section';
import { type SharedData, type SidebarNavGroup } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { BookMarked, CircleUser, Laptop, LayoutGrid, Network, Users } from 'lucide-react';
import AppLogo from './app-logo';

/** Entries that only show for viewers who may open what is behind them. */
const navGroups = (can: (permission: Permission) => boolean, sysadmin: boolean, structureHref: string): SidebarNavGroup[] => [
    {
        items: [
            // The company at a glance is the one account's page, and it is nobody
            // else's first stop: everybody else starts at their own card.
            sysadmin ? { title: 'Главная', url: '/dashboard', icon: LayoutGrid } : { title: 'Профиль', url: '/profile', icon: CircleUser },
            ...(can('employees.view') ? [{ title: 'Сотрудники', url: '/employees', icon: Users }] : []),
            // The chart, the departments and the job titles are three tabs of one
            // section now, so the menu has one door for all three. It opens on the
            // tab this person was last on, and carries that address itself rather
            // than letting the section forward them once the chart has been drawn:
            // one press, one visit. Whichever of the three it points at, it lights
            // up on all of them.
            {
                title: 'Структура компании',
                url: structureHref,
                icon: Network,
                match: companySections.map((section) => section.href),
            },
            // Any part of the fleet — own, the department's or all of it — opens the section.
            ...(seesEquipment(can) ? [{ title: 'Оборудование', url: '/equipment', icon: Laptop }] : []),
        ],
    },
];

/** The same entries for the phone's tab bar, which draws them its own way. */
export function useMainNav() {
    const can = useCan();
    const { sysadmin } = usePage<SharedData>().props.auth;

    const structureHref = useRememberedSectionHref();

    return navGroups(can, sysadmin, structureHref)[0].items;
}

export function AppSidebar() {
    const can = useCan();
    const { sysadmin } = usePage<SharedData>().props.auth;
    const structureHref = useRememberedSectionHref();

    const footerGroup: SidebarNavGroup = {
        // Settings live under the avatar next to "Выйти", notifications behind
        // the bell in the header. Repeating either here would only mean two
        // doors to one room, and among company-wide entries settings would
        // read as something they are not.
        items: [
            // One list open is enough for the door: /directories lands on the
            // first one this person may read.
            ...(seesDirectories(can) ? [{ title: 'Справочники', url: '/directories', icon: BookMarked }] : []),
        ],
    };

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton
                            size="lg"
                            asChild
                            className="h-14 group-data-[collapsible=icon]:p-1! hover:bg-transparent active:bg-transparent"
                        >
                            <Link href="/dashboard" prefetch aria-label="Evolet HRM — на главную">
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                {navGroups(can, sysadmin, structureHref).map((group) => (
                    <NavMain key={group.items[0].title} group={group} />
                ))}
            </SidebarContent>

            <SidebarFooter>
                {footerGroup.items.length > 0 && <NavMain group={footerGroup} className="p-0" />}
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
