import EvoletLogo from '@/components/evolet-logo';
import { Card } from '@/components/ui/card';
import { Link } from '@inertiajs/react';
import { type PropsWithChildren, type ReactNode } from 'react';

interface AuthLayoutProps {
    title?: string;
    description?: string;
    /** A line under the card, outside it: where to turn when the form cannot help. */
    note?: ReactNode;
}

/**
 * The screens somebody meets before they are signed in: the wordmark, one card
 * with the form in it, a line under the card and the year at the foot.
 *
 * A column 384px wide in the middle of the window, as the design draws it. The
 * card carries its own heading, so the form inside it starts with its first
 * field; whatever the page wants said outside the card — «Нет доступа?» — goes
 * in `note`, where it reads as an aside rather than part of the form.
 */
export default function AuthEvoletLayout({ children, title, description, note }: PropsWithChildren<AuthLayoutProps>) {
    return (
        <div className="bg-background flex min-h-dvh flex-col items-center px-4 py-6">
            <main className="flex w-full max-w-96 flex-1 flex-col justify-center gap-6 py-12">
                {/* As wide as the wordmark and its chip, not as wide as the
                    column: a link is pressed where it is drawn. */}
                <Link href={route('dashboard')} className="mx-auto flex w-fit items-center gap-2.5" aria-label="Evolet HRM">
                    <EvoletLogo className="h-9 w-36 dark:hidden" />
                    <EvoletLogo tone="light" className="hidden h-9 w-36 dark:block" />
                    <span className="border-border text-muted-foreground rounded border px-[5px] py-px text-[10px] leading-[14px] font-semibold">
                        HRM
                    </span>
                </Link>

                <Card className="flex flex-col gap-6 py-6">
                    {title && (
                        <div className="flex flex-col gap-1.5 px-6 text-center">
                            <h1 className="text-xl leading-7 font-semibold tracking-[-0.01em]">{title}</h1>
                            {description && <p className="text-muted-foreground text-sm">{description}</p>}
                        </div>
                    )}

                    <div className="px-6">{children}</div>
                </Card>

                {note && <p className="text-muted-foreground text-center text-sm">{note}</p>}
            </main>

            <footer className="text-muted-foreground text-xs">© {new Date().getFullYear()} Evolet · Faith in a healthy future</footer>
        </div>
    );
}
