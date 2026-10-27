import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { readsEquipmentJournal, seesDirectories, seesEquipment, useCan, type Permission } from '@/lib/access';
import { TABLE_KEYS, useListHref } from '@/lib/tables';
import { type SharedData, type SidebarNavGroup } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import {
    Bell,
    BookMarked,
    Boxes,
    Briefcase,
    Building2,
    CircleUser,
    FileText,
    Flag,
    Heart,
    Layers,
    LayoutGrid,
    Network,
    Package,
    Target,
    TreePalm,
    TrendingUp,
    UserSearch,
    Users,
} from 'lucide-react';
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
/**
 * A page inside a section, named and nothing else: the sub-menu draws no icons,
 * and a page that is not built yet carries no address either.
 */
const named = ({ title }: { title: string }) => ({ title });

/**
 * The menu, built for whoever is reading it.
 *
 * A list is linked to the way the company reads it: the sorting, the tab and
 * the filters of its arrangement travel in the address, so pressing «Сотрудники»
 * opens the staff list already as it is meant to be read rather than drawing it
 * one way and rearranging it a moment later.
 */
const navGroups = (can: (permission: Permission) => boolean, sysadmin: boolean, href: (url: string, key: string) => string): SidebarNavGroup[] => [
    {
        title: 'Обзор',
        items: [
            // The company at a glance is the one account's page, and it is nobody
            // else's first stop: everybody else starts at their own card.
            // No address for the first of them either: what a main page should
            // hold has not been decided, so it names itself and waits. Everybody
            // who is not a sysadmin still starts at their own card.
            sysadmin ? { title: 'Главная', icon: LayoutGrid } : { title: 'Профиль', url: '/profile', icon: CircleUser },
            { title: 'Уведомления', icon: Bell },
        ],
    },
    {
        title: 'Организация',
        items: [
            { title: 'Оргструктура', url: '/org-chart', icon: Network },
            ...(can('employees.view') ? [{ title: 'Сотрудники', url: href('/employees', TABLE_KEYS.employees), icon: Users }] : []),
            ...(can('directories.view.departments')
                ? [{ title: 'Подразделения', url: href('/departments', TABLE_KEYS.departments), icon: Building2 }]
                : []),
            ...(can('directories.view.positions') ? [{ title: 'Должности', url: href('/positions', TABLE_KEYS.positions), icon: Briefcase }] : []),
            // No address: the ladder a grade is a step on is not written down
            // yet, so the entry holds its place and cannot be opened.
            { title: 'Грейды', icon: Layers },
        ],
    },
    {
        // What the company does rather than what it is made of. The design names
        // nine of these; one of them is built, and the rest stand in the order
        // they will be opened in, greyed and unpressable, so the menu reads as
        // the plan it is rather than growing a line at a time.
        title: 'Процессы',
        items: [
            { title: 'Рекрутмент ATS', icon: UserSearch },
            { title: 'Онбординг', icon: Flag },
            { title: 'Отпуск', icon: TreePalm },
            {
                title: 'Эффективность / KPI',
                icon: Target,
                items: [{ title: 'Посещаемость' }, { title: 'KPI' }, { title: 'OKR' }].map(named),
            },
            {
                title: 'Развитие сотрудников',
                icon: TrendingUp,
                items: [{ title: 'Навыки' }, { title: 'Компетенции' }, { title: 'Оценки' }, { title: 'Курсы' }, { title: 'Карьерные пути' }].map(
                    named,
                ),
            },
            {
                title: 'Total Reward',
                icon: Package,
                items: [{ title: 'Зарплата' }, { title: 'Премии' }, { title: 'Штрафы' }, { title: 'Помощь сотрудникам' }].map(named),
            },
            { title: 'Заявки', icon: FileText },
            {
                title: 'Инвентаризация',
                icon: Boxes,
                items: [
                    // Everything the section holds at once, which is why it is
                    // read before any one kind of it. It is to hold the fleet as
                    // a list, so it opens to whoever may read some part of the
                    // fleet — as the page under it does, and nobody else sees
                    // either of them.
                    ...(seesEquipment(can)
                        ? [
                              { title: 'Обзор', url: href('/inventory', TABLE_KEYS.inventory) },
                              { title: 'Рабочее оборудование', url: href('/equipment', TABLE_KEYS.equipment) },
                          ]
                        : []),
                    named({ title: 'Мебель' }),
                    named({ title: 'Оргтехника офиса' }),
                    // What has happened to all of it, after the lists of what
                    // there is: read by whoever may read the journal of some
                    // part of the fleet.
                    ...(readsEquipmentJournal(can)
                        ? [{ title: 'Журнал операций', url: href('/inventory/journal', TABLE_KEYS.inventoryJournal) }]
                        : []),
                ],
            },
            {
                title: 'Корпоративная культура',
                icon: Heart,
                items: [
                    { title: 'Тимбилдинги' },
                    { title: 'Спортивные мероприятия' },
                    { title: 'Благотворительная помощь' },
                    { title: 'Корпоративные бенефиты' },
                ].map(named),
            },
        ],
    },
];

export function AppSidebar() {
    const can = useCan();
    const listHref = useListHref();
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
                {navGroups(can, sysadmin, listHref)
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
