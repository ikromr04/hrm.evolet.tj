import { cn } from '@/lib/utils';

/** One part of a paper, as the margin beside it lists them. */
export interface PaperPart {
    number: string;
    title: string;
}

/**
 * What a paper is made of, read down the margin at its left.
 *
 * Which part is being read cannot be known until the page follows the text, so
 * the first one wears the plate — as an open tab does — and the rest are words.
 * Nothing here scrolls anywhere yet.
 */
export function PaperContents({ parts }: { parts: PaperPart[] }) {
    return (
        <nav aria-label="Содержание" className="flex flex-col gap-2">
            <p className="text-muted-foreground px-3 text-[11px] font-semibold tracking-wide uppercase">Содержание</p>

            <ul className="flex flex-col gap-0.5">
                {parts.map((part, index) => (
                    <li key={part.number}>
                        <span
                            className={cn(
                                'flex items-baseline gap-2 rounded-md px-3 py-2 text-[13px]',
                                index === 0 ? 'bg-brand-soft font-semibold dark:bg-white/10' : 'text-muted-foreground',
                            )}
                        >
                            <span className="tabular-nums">{part.number}</span>
                            <span className="min-w-0">{part.title}</span>
                        </span>
                    </li>
                ))}
            </ul>
        </nav>
    );
}

/** Who signs the paper, in the corner the first page of one keeps it. */
export function PaperApproval() {
    return (
        <div className="bg-muted/40 rounded-lg border px-4 py-3">
            <p className="text-brand-strong text-[11px] font-semibold tracking-wide uppercase dark:text-[#C5E27A]">Утверждаю</p>
            <p className="mt-1 text-[13px] leading-relaxed">Глава Представительства «Evolet Healthcare Limited» в Республике Таджикистан</p>
            <p className="text-muted-foreground mt-1.5 text-[11px]">Подпись и дата: [после утверждения]</p>
        </div>
    );
}
