import * as LabelPrimitive from '@radix-ui/react-label';
import { cva, type VariantProps } from 'class-variance-authority';
import * as React from 'react';

import { cn } from '@/lib/utils';

const labelVariants = cva('text-sm font-medium leading-none peer-disabled:cursor-not-allowed peer-disabled:opacity-70');

/**
 * The star beside the name of a field the form will not do without.
 *
 * It is the one place in the app that draws it, so every form says «нужно»
 * the same way and in the theme's own red. A screen reader is told the same
 * thing by `aria-required` on the control itself and skips the star: read out
 * on its own, «звёздочка» names nothing.
 */
function RequiredMark() {
    return (
        <span aria-hidden className="text-destructive ml-0.5">
            *
        </span>
    );
}

const Label = React.forwardRef<
    React.ElementRef<typeof LabelPrimitive.Root>,
    React.ComponentPropsWithoutRef<typeof LabelPrimitive.Root> & VariantProps<typeof labelVariants> & { required?: boolean }
>(({ className, required, children, ...props }, ref) => (
    <LabelPrimitive.Root ref={ref} className={cn(labelVariants(), className)} {...props}>
        {children}
        {required && <RequiredMark />}
    </LabelPrimitive.Root>
));
Label.displayName = LabelPrimitive.Root.displayName;

export { Label, RequiredMark };
