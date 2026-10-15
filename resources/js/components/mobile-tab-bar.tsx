import { useMainNav } from '@/components/app-sidebar';
import { navItemActive } from '@/components/nav-main';
import { Sheet, SheetContent, SheetDescription, SheetTitle } from '@/components/ui/sheet';
import { UserInfo } from '@/components/user-info';
import { useAppearance } from '@/hooks/use-appearance';
import { readsEquipmentJournal, seesDirectories, useCan } from '@/lib/access';
import { cn } from '@/lib/utils';
import { type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { BookMarked, ChevronRight, CircleUser, Ellipsis, History, LogOut, Moon, Settings, Sun, type LucideIcon } from 'lucide-react';
import { useState, type ReactNode } from 'react';

/** Tab labels have about 70px each on a phone; the long names get a short one. */
const SHORT: Record<string, string> = { 'Структура компании': 'Структура' };

/**
 * The phone's way around the app: the sections a person may open sit under the
 * thumb, as in any mobile app, and whatever does not fit goes behind «Ещё».
 * From `md` up the sidebar does this job and the bar is not drawn.
 */
export function MobileTabBar() {
    const items = useMainNav();
    const { url } = usePage();
    const [more, setMore] = useState(false);

    // "Ещё" lights up for the pages it leads to, so the bar always says where one is.
    const inMore = !items.some((item) => item.url && navItemActive(item, url));

    return (
        <>
            <nav
                aria-label="Разделы"
                className="bg-background/90 supports-[backdrop-filter]:bg-background/75 fixed inset-x-0 bottom-0 z-40 border-t pb-[env(safe-area-inset-bottom)] backdrop-blur-xl md:hidden"
            >
                <ul className="flex h-14 items-stretch">
                    {items.map((item) => (
                        <li key={item.title} className="min-w-0 flex-1">
                            <Tab href={item.url!} icon={item.icon!} active={navItemActive(item, url)}>
                                {SHORT[item.title] ?? item.title}
                            </Tab>
                        </li>
                    ))}
                    <li className="min-w-0 flex-1">
                        <Tab icon={Ellipsis} active={inMore} onClick={() => setMore(true)}>
                            Ещё
                        </Tab>
                    </li>
                </ul>
            </nav>

            <MoreSheet open={more} onOpenChange={setMore} />
        </>
    );
}

function Tab({
    href,
    icon: Icon,
    active,
    onClick,
    children,
}: {
    href?: string;
    icon: LucideIcon;
    active: boolean;
    onClick?: () => void;
    children: ReactNode;
}) {
    const className = cn(
        'flex h-full w-full flex-col items-center justify-center gap-0.5 px-0.5 text-[10px] font-medium tracking-tight transition-colors',
        active ? 'text-brand-strong dark:text-[#C5E27A]' : 'text-muted-foreground active:text-foreground',
    );
    const body = (
        <>
            <Icon className="size-[22px]" strokeWidth={active ? 2.25 : 1.75} aria-hidden="true" />
            <span className="max-w-full truncate">{children}</span>
        </>
    );

    return href ? (
        <Link href={href} prefetch className={className} aria-current={active ? 'page' : undefined}>
            {body}
        </Link>
    ) : (
        <button type="button" onClick={onClick} className={className}>
            {body}
        </button>
    );
}

/** Everything that is not a tab: the lists, one's own card, settings, the theme and the way out. */
function MoreSheet({ open, onOpenChange }: { open: boolean; onOpenChange: (open: boolean) => void }) {
    const { auth } = usePage<SharedData>().props;
    const can = useCan();
    const { updateAppearance } = useAppearance();
    const close = () => onOpenChange(false);

    const toggleTheme = () => updateAppearance(document.documentElement.classList.contains('dark') ? 'light' : 'dark');

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent
                side="bottom"
                className="flex flex-col gap-3 rounded-t-2xl px-4 pt-3 pb-[calc(1rem+env(safe-area-inset-bottom))] md:hidden [&>button:last-child]:hidden"
            >
                <div className="bg-muted-foreground/30 mx-auto mb-1 h-1 w-10 rounded-full" aria-hidden="true" />
                <SheetTitle className="sr-only">Ещё</SheetTitle>
                <SheetDescription className="sr-only">Разделы, настройки и выход</SheetDescription>

                {auth.user && (
                    <Link href={route('profile')} prefetch onClick={close} className="bg-muted/60 flex items-center gap-3 rounded-xl p-3">
                        <UserInfo user={auth.user} showEmail />
                        <ChevronRight className="text-muted-foreground size-4 shrink-0" />
                    </Link>
                )}

                <Group>
                    {/* The one account starts at the dashboard, so its own card is reached from here. */}
                    {auth.sysadmin && <Row href={route('profile')} icon={CircleUser} label="Мой профиль" onClick={close} />}
                    {seesDirectories(can) && <Row href="/directories" icon={BookMarked} label="Справочники" onClick={close} />}
                    {readsEquipmentJournal(can) && <Row href="/equipment/journal" icon={History} label="Журнал операций" onClick={close} />}
                    <Row href={route('profile.edit')} icon={Settings} label="Настройки" onClick={close} />
                </Group>

                <Group>
                    <button type="button" onClick={toggleTheme} className={rowClass}>
                        <Sun className="size-5 dark:hidden" />
                        <Moon className="hidden size-5 dark:block" />
                        <span className="flex-1 text-left">
                            <span className="dark:hidden">Тёмная тема</span>
                            <span className="hidden dark:inline">Светлая тема</span>
                        </span>
                    </button>
                    <Link
                        href={route('logout')}
                        method="post"
                        as="button"
                        onClick={close}
                        className={cn(rowClass, 'text-[#B42318] dark:text-[#F7A19A]')}
                    >
                        <LogOut className="size-5" />
                        <span className="flex-1 text-left">Выйти</span>
                    </Link>
                </Group>
            </SheetContent>
        </Sheet>
    );
}

const rowClass = 'flex min-h-12 w-full items-center gap-3 px-3 text-[15px] active:bg-accent';

function Group({ children }: { children: ReactNode }) {
    return <div className="bg-muted/60 divide-border/70 flex flex-col divide-y overflow-hidden rounded-xl">{children}</div>;
}

function Row({ href, icon: Icon, label, onClick }: { href: string; icon: LucideIcon; label: string; onClick: () => void }) {
    return (
        <Link href={href} prefetch onClick={onClick} className={rowClass}>
            <Icon className="text-muted-foreground size-5" />
            <span className="flex-1">{label}</span>
            <ChevronRight className="text-muted-foreground size-4" />
        </Link>
    );
}
