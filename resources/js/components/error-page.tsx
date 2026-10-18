import EvoletLogo from '@/components/evolet-logo';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowLeft, type LucideIcon } from 'lucide-react';
import { type ReactNode } from 'react';

/**
 * What a page says when it cannot be the page that was asked for.
 *
 * Said plainly and without drama: a number, a line about what happened, and the
 * two things one actually wants — back where they came from, or to the start.
 * Signed in, it sits inside the usual shell, so the sidebar and the search are
 * still there and this is a detour rather than a dead end.
 *
 * It is also the page shown when the application itself is what failed, so it
 * leans on as little as it can: no shared data at all is still a page.
 */
export function ErrorPage({
    code,
    title,
    description,
    icon: Icon,
    note,
    actions,
    standalone = false,
}: {
    code: number;
    title: string;
    description: string;
    icon: LucideIcon;
    /** A line under the buttons, where one is worth saying. */
    note?: ReactNode;
    /** Instead of the two ways out, where neither leads anywhere — the whole system is closed. */
    actions?: ReactNode;
    /** Outside the shell even when signed in: every link of the sidebar would lead back here. */
    standalone?: boolean;
}) {
    // A failure can come before anything has worked out who is asking — the
    // database is down, the system is closed for an update — and the shared data
    // is then simply not there. Nobody signed in is the honest reading of that.
    const user = (usePage().props as Partial<SharedData>).auth?.user ?? null;
    const inShell = user !== null && !standalone;

    // Inside the shell the bar above already calls the page this, so saying it
    // again here is the same name twice rather than a second heading. Outside
    // the shell there is no bar, and this line is the only heading there is.
    const Title = inShell ? 'p' : 'h1';

    const body = (
        <div className="flex flex-1 items-center justify-center p-6 max-md:px-4">
            <div className="flex w-full max-w-md flex-col items-center gap-6 text-center max-md:gap-5">
                <span className="bg-muted text-muted-foreground flex size-16 items-center justify-center rounded-2xl">
                    <Icon className="size-8" />
                </span>

                <div className="flex flex-col items-center gap-2">
                    {/* The number is the whole explanation, so it is allowed to be large. */}
                    <span className="text-brand-strong text-5xl font-bold tracking-tight tabular-nums dark:text-[#C5E27A]">{code}</span>
                    <Title className="text-xl font-semibold tracking-tight">{title}</Title>
                    <p className="text-muted-foreground text-sm leading-relaxed text-balance">{description}</p>
                </div>

                {/* On a phone the two ways out are full-width buttons, the main one on top where the thumb meets it first. */}
                <div className="flex flex-wrap items-center justify-center gap-2 max-md:w-full max-md:max-w-xs max-md:flex-col-reverse max-md:items-stretch">
                    {actions ?? (
                        <>
                            <Button variant="outline" onClick={() => window.history.back()} className="max-md:h-11 max-md:text-[15px]">
                                <ArrowLeft />
                                Назад
                            </Button>
                            <Button asChild className="max-md:h-11 max-md:text-[15px]">
                                <Link href={user ? '/' : route('login')}>{user ? 'На главную' : 'Войти'}</Link>
                            </Button>
                        </>
                    )}
                </div>

                {user && note}
            </div>
        </div>
    );

    return (
        <>
            <Head title={title} />
            {/* No trail: a wrong address sits nowhere in the system, so there is
                no way down to it to draw. The bar names it instead. */}
            {inShell ? (
                <AppLayout title={title} fitViewport>
                    {body}
                </AppLayout>
            ) : (
                <Guest>{body}</Guest>
            )}
        </>
    );
}

/** Without an account there is no shell to sit in, so the page brings its own. */
function Guest({ children }: { children: ReactNode }) {
    return (
        <div className="bg-background flex min-h-dvh flex-col gap-4 p-6 max-sm:px-4 sm:p-10">
            <Link href={route('login')} className="flex items-center gap-2.5 self-center">
                <EvoletLogo className="h-8 w-32 dark:hidden" />
                <EvoletLogo tone="light" className="hidden h-8 w-32 dark:block" />
                <span className="border-border bg-background text-muted-foreground rounded-md border px-1.5 py-0.5 text-[11px] font-semibold tracking-wider">
                    HRM
                </span>
            </Link>

            <main className="flex flex-1 items-center justify-center">{children}</main>

            <p className="text-muted-foreground text-center text-xs">© {new Date().getFullYear()} Evolet</p>
        </div>
    );
}
