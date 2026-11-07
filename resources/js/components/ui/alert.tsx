import { cva, type VariantProps } from 'class-variance-authority';
import * as React from 'react';

import { cn } from '@/lib/utils';

/**
 * A callout: one line saying what has happened, a sentence saying what it
 * means, and a sign in front of both.
 *
 * Laid out as a grid with a column for that sign, which is how shadcn draws it
 * now — the older absolute-positioning copy this file used to hold could not
 * keep a second line of the heading clear of the icon, and left the text at the
 * page's own size, so a callout read louder than the block it stood in.
 * Without an icon the first column collapses to nothing and nothing indents.
 *
 * The tones are the application's own, the ones StatusBadge is painted in, and
 * they are variants here rather than classes passed in at the call: a callout
 * whose colour is handed to it from outside ends up with a muted grey sentence
 * on a coloured ground, because the description carries a colour of its own.
 */
const alertVariants = cva(
    'relative grid w-full grid-cols-[0_1fr] items-start gap-y-0.5 rounded-lg border px-4 py-3 text-sm has-[>svg]:grid-cols-[1rem_1fr] has-[>svg]:gap-x-3 [&>svg]:size-4 [&>svg]:translate-y-0.5 [&>svg]:text-current',
    {
        variants: {
            variant: {
                default: 'bg-card text-card-foreground [&>[data-slot=alert-description]]:text-muted-foreground',
                destructive:
                    'border-destructive/40 text-destructive bg-destructive/5 dark:border-destructive [&>[data-slot=alert-description]]:text-current/90',
                warning:
                    'border-transparent bg-[#FBEFD9] text-[#9A4A06] dark:bg-[#F5A524]/15 dark:text-[#F8C471] [&>[data-slot=alert-description]]:text-current/90',
                info: 'border-transparent bg-[#E4ECFD] text-[#1D4ED8] dark:bg-[#3B82F6]/15 dark:text-[#93B4FA] [&>[data-slot=alert-description]]:text-current/90',
                success:
                    'border-transparent bg-[#EEF5DC] text-[#4A6410] dark:bg-[#A8CF45]/15 dark:text-[#C5E27A] [&>[data-slot=alert-description]]:text-current/90',
            },
        },
        defaultVariants: {
            variant: 'default',
        },
    },
);

const Alert = React.forwardRef<HTMLDivElement, React.HTMLAttributes<HTMLDivElement> & VariantProps<typeof alertVariants>>(
    ({ className, variant, ...props }, ref) => (
        <div ref={ref} role="alert" data-slot="alert" className={cn(alertVariants({ variant }), className)} {...props} />
    ),
);
Alert.displayName = 'Alert';

const AlertTitle = React.forwardRef<HTMLParagraphElement, React.HTMLAttributes<HTMLHeadingElement>>(({ className, ...props }, ref) => (
    <h5 ref={ref} data-slot="alert-title" className={cn('col-start-2 min-h-4 font-medium tracking-tight', className)} {...props} />
));
AlertTitle.displayName = 'AlertTitle';

const AlertDescription = React.forwardRef<HTMLParagraphElement, React.HTMLAttributes<HTMLParagraphElement>>(({ className, ...props }, ref) => (
    <div
        ref={ref}
        data-slot="alert-description"
        className={cn('col-start-2 grid justify-items-start gap-1 text-sm [&_p]:leading-relaxed', className)}
        {...props}
    />
));
AlertDescription.displayName = 'AlertDescription';

export { Alert, AlertDescription, AlertTitle };
