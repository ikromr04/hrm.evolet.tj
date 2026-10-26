import { cn } from '@/lib/utils';
import { type HTMLAttributes } from 'react';

/**
 * The four states a thing can be in, a quiet grey for «nothing in particular»,
 * and two hues that are not states at all.
 *
 * Those last two tell kinds apart — a hard skill from a soft one — and are
 * never given to a state: a reader who has learnt that green means «done»
 * should not meet green again meaning «a kind of skill». The pair is as far
 * apart as this palette allows, and every badge carries its word, so the
 * colour reinforces what is written rather than standing in for it.
 */
export type StatusTone = 'success' | 'warning' | 'info' | 'danger' | 'neutral' | 'teal' | 'violet';

export const statusTones: Record<StatusTone, string> = {
    success: 'bg-[#EEF5DC] text-[#4A6410] dark:bg-[#A8CF45]/15 dark:text-[#C5E27A]',
    warning: 'bg-[#FBEFD9] text-[#9A4A06] dark:bg-[#F5A524]/15 dark:text-[#F8C471]',
    info: 'bg-[#E4ECFD] text-[#1D4ED8] dark:bg-[#3B82F6]/15 dark:text-[#93B4FA]',
    danger: 'bg-[#FDE8E6] text-[#B42318] dark:bg-[#EF4444]/15 dark:text-[#F7A19A]',
    neutral: 'bg-[#F4F4F5] text-[#44474C] dark:bg-white/10 dark:text-neutral-300',
    teal: 'bg-[#D9F2EF] text-[#0B6B63] dark:bg-[#14B8A6]/15 dark:text-[#5EEAD4]',
    violet: 'bg-[#EBE4FD] text-[#5B21B6] dark:bg-[#A78BFA]/15 dark:text-[#C4B5FD]',
};

export function StatusBadge({ tone = 'neutral', className, ...props }: HTMLAttributes<HTMLSpanElement> & { tone?: StatusTone }) {
    return (
        <span
            className={cn('inline-flex h-[22px] items-center rounded-md px-2 text-xs font-medium whitespace-nowrap', statusTones[tone], className)}
            {...props}
        />
    );
}
