import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { seesDirectories, seesEquipment, useCan, type Permission } from '@/lib/access';
import { type SharedData, type SidebarNavGroup } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { Bell, BookMarked, Briefcase, Building2, CircleUser, Laptop, LayoutGrid, Network, Users } from 'lucide-react';
import AppLogo from './app-logo';

/**
 * The menu, in the three groups the design names: what this person opens about
 * themselves, what the company is made of, and what it owns.
 *
 * Every entry only shows for somebody who may open what is behind it. The
 * structure of the company used to be one door for three pages — the chart, the
 * departments and the jobs — and is three doors now, as the design has it: each
 * is a page in its own right and the menu says so.
 */
const navGroups = (can: (permission: Permission) => boolean, sysadmin: boolean): SidebarNavGroup[] => [
    {
        title: 'Обзор',
        items: [
            // The company at a glance is the one account's page, and it is nobody
            // else's first stop: everybody else starts at their own card.
            sysadmin ? { title: 'Главная', url: '/', icon: LayoutGrid } : { title: 'Профиль', url: '/profile', icon: CircleUser },
            // Put aside until it is designed; the page behind it says so.
            { title: 'Уведомления', url: '/notifications', icon: Bell },
        ],
    },
    {
        title: 'Организация',
        items: [
            { title: 'Оргструктура', url: '/org-chart', icon: Network },
            ...(can('employees.view') ? [{ title: 'Сотрудники', url: '/employees', icon: Users }] : []),
            ...(can('directories.view.departments') ? [{ title: 'Подразделения', url: '/departments', icon: Building2 }] : []),
            ...(can('directories.view.positions') ? [{ title: 'Должности', url: '/positions', icon: Briefcase }] : []),
        ],
    },
    {
        title: 'Инвентаризация',
        // Any part of the fleet — own, the department's or all of it — opens the section.
        items: seesEquipment(can) ? [{ title: 'Оборудование', url: '/equipment', icon: Laptop }] : [],
    },
];

export function AppSidebar() {
    const can = useCan();
    const { sysadmin } = usePage<SharedData>().props.auth;

    const footerGroup: SidebarNavGroup = {
        // Settings live under the avatar next to «Выйти». Repeating them here
        // would only mean two doors to one room, and among company-wide entries
        // settings would read as something they are not.
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
                            className="group-data-[collapsible=icon]:p-1! hover:bg-transparent active:bg-transparent"
                        >
                            <Link href="/" prefetch aria-label="Evolet HRM — на главную">
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                {navGroups(can, sysadmin)
                    .filter((group) => group.items.length > 0)
                    .map((group) => (
                        <NavMain key={group.title} group={group} />
                    ))}
            </SidebarContent>

            <SidebarFooter>
                {footerGroup.items.length > 0 && <NavMain group={footerGroup} className="p-0" />}
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
