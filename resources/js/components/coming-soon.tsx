import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import { ArrowLeft, Construction } from 'lucide-react';
import { useId } from 'react';

/**
 * A section that is drawn but not built yet, as the design draws it: a dashed
 * box with what it will be said plainly inside it.
 *
 * It is said here once because it is the same promise wherever it stands — a
 * page of its own, or a tab beside tabs that work — and because an empty panel
 * reads as one that failed to load rather than one nobody has written.
 *
 * The way back is the page's to decide: an address where there is one to go to,
 * the step the reader came from where there is not, and nothing at all inside a
 * tab, which is left by pressing the tab beside it.
 */
export function ComingSoon({ what, back = true, className }: { what: string; back?: boolean | string; className?: string }) {
    const heading = useId();

    return (
        <section
            aria-labelledby={heading}
            className={cn(
                // 420px, as the drawing has it: the box says the section is
                // coming and then stops. It does not stretch to the foot of the
                // window — an empty page is not more empty for being tall.
                'flex min-h-[420px] flex-col items-center justify-center gap-6 rounded-xl border border-dashed p-12 text-center',
                className,
            )}
        >
            <div className="flex max-w-96 flex-col items-center gap-2">
                <span className="bg-muted mb-2 flex size-10 items-center justify-center rounded-lg">
                    <Construction className="size-5" aria-hidden="true" />
                </span>
                <h2 id={heading} className="text-lg leading-7 font-medium tracking-[-0.01em]">
                    Скоро появится
                </h2>
                <p className="text-muted-foreground text-sm leading-[22px]">Раздел «{what}» пока в разработке.</p>
            </div>

            {back === true && (
                <Button type="button" variant="outline" className="h-8 gap-1.5 px-3" onClick={() => window.history.back()}>
                    <ArrowLeft />
                    Назад
                </Button>
            )}

            {typeof back === 'string' && (
                <Button variant="outline" className="h-8 gap-1.5 px-3" asChild>
                    <Link href={back} prefetch>
                        <ArrowLeft />
                        Назад
                    </Link>
                </Button>
            )}
        </section>
    );
}
