import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { Download, History } from 'lucide-react';
import { type ReactNode } from 'react';

/**
 * The band over a paper: what it is, what state it is in, and what can be done
 * with the whole of it.
 *
 * «Положение о работе» and «Профиль должности» wear the same one — the design
 * draws them as two papers of one set — so it is said once here and each page
 * hangs its own rows under it: the reading of the положение, the positions of
 * the профиль.
 *
 * The state and the edition are the design's words and nothing keeps them yet;
 * «Версии» and «Скачать .docx» are the same promise. The pencil is the only
 * thing here that does anything, and it belongs to whoever hands it over.
 */
export function PaperBand({
    title,
    status = 'Действует',
    edition = 'Редакция 2025',
    pencil,
    children,
}: {
    title: string;
    status?: string;
    edition?: string;
    pencil?: ReactNode;
    /** Rows under the band's own — each one a {@link PaperBandRow}. */
    children?: ReactNode;
}) {
    return (
        <div className="-mx-4 -mt-4 flex flex-col max-md:hidden md:-mx-6 md:-mt-5">
            {/* The head of the band is a plate, the way the name of every other
                block on this page sits on one: it fills the top of the card and
                takes the card's own corner with it. The rows under it are the
                paper's and stay white. */}
            <PaperBandRow className="bg-muted/60 rounded-t-xl py-2">
                <h2 className="text-[15px] font-semibold">{title}</h2>
                <StatusBadge tone="success">{status}</StatusBadge>
                <StatusBadge tone="neutral">{edition}</StatusBadge>

                <div className="ml-auto flex shrink-0 items-center gap-2">
                    <Button type="button" variant="outline" size="sm">
                        <History />
                        Версии
                    </Button>
                    <Button type="button" variant="outline" size="sm">
                        <Download />
                        Скачать .docx
                    </Button>
                    {pencil}
                </div>
            </PaperBandRow>

            {children}
        </div>
    );
}

/** One line of the band, ruled off from the next and flush with the card's edges. */
export function PaperBandRow({ className, children }: { className?: string; children: ReactNode }) {
    return <div className={cn('flex flex-wrap items-center gap-x-3 gap-y-2 border-b px-4 py-3 md:px-6', className)}>{children}</div>;
}

/** A word that will be a button once there is something behind it. */
export function PaperAction({ children }: { children: ReactNode }) {
    return (
        <button type="button" className="text-brand-strong text-[13px] font-medium whitespace-nowrap hover:underline dark:text-[#C5E27A]">
            {children}
        </button>
    );
}
