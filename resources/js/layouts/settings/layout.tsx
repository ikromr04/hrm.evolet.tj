import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { type NavItem } from '@/types';
import { Link } from '@inertiajs/react';

const sidebarNavItems: NavItem[] = [
    {
        title: 'Учётная запись',
        url: '/settings/profile',
        icon: null,
    },
    {
        title: 'Пароль',
        url: '/settings/password',
        icon: null,
    },
    {
        title: 'Оформление',
        url: '/settings/appearance',
        icon: null,
    },
];

export default function SettingsLayout({ children }: { children: React.ReactNode }) {
    const currentPath = window.location.pathname;

    return (
        <div className="px-4 py-6 max-md:px-3 max-md:pt-3">
            {/* On a phone the top bar names the page and the chips below say where it sits, so the heading would only push the form down. */}
            <div className="max-md:hidden">
                <Heading title="Настройки" description="Учётная запись, пароль и оформление" />
            </div>

            {/* The three pages as one row of chips, the way a phone app switches between close siblings. */}
            <nav
                aria-label="Разделы настроек"
                className="-mx-3 mb-4 flex gap-2 overflow-x-auto px-3 [scrollbar-width:none] md:hidden [&::-webkit-scrollbar]:hidden"
            >
                {sidebarNavItems.map((item) => (
                    <Link
                        key={item.url}
                        href={item.url}
                        prefetch
                        aria-current={currentPath === item.url ? 'page' : undefined}
                        className={cn(
                            'flex h-8 shrink-0 items-center rounded-full px-3 text-sm whitespace-nowrap transition-colors',
                            currentPath === item.url
                                ? 'bg-brand-soft text-foreground font-semibold dark:bg-white/10'
                                : 'bg-card text-foreground active:bg-accent',
                        )}
                    >
                        {item.title}
                    </Link>
                ))}
            </nav>

            <div className="flex flex-col space-y-8 lg:flex-row lg:space-y-0 lg:space-x-12">
                <aside className="w-full max-w-xl max-md:hidden lg:w-48">
                    <nav className="flex flex-col space-y-1 space-x-0">
                        {sidebarNavItems.map((item) => (
                            <Button
                                key={item.url}
                                size="sm"
                                variant="ghost"
                                asChild
                                className={cn('w-full justify-start max-lg:h-9', {
                                    'bg-muted': currentPath === item.url,
                                })}
                            >
                                <Link href={item.url} prefetch>
                                    {item.title}
                                </Link>
                            </Button>
                        ))}
                    </nav>
                </aside>

                <div className="flex-1 md:max-w-2xl">
                    <section className="max-w-xl space-y-12">{children}</section>
                </div>
            </div>
        </div>
    );
}
