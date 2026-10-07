import * as DialogPrimitive from '@radix-ui/react-dialog';
import { X } from 'lucide-react';
import * as React from 'react';

import { cn } from '@/lib/utils';

const Dialog = DialogPrimitive.Root;

const DialogTrigger = DialogPrimitive.Trigger;

const DialogPortal = DialogPrimitive.Portal;

const DialogClose = DialogPrimitive.Close;

const DialogOverlay = React.forwardRef<
    React.ElementRef<typeof DialogPrimitive.Overlay>,
    React.ComponentPropsWithoutRef<typeof DialogPrimitive.Overlay>
>(({ className, ...props }, ref) => (
    <DialogPrimitive.Overlay
        ref={ref}
        className={cn(
            'fixed inset-0 z-50 bg-black/80 data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0',
            className,
        )}
        {...props}
    />
));
DialogOverlay.displayName = DialogPrimitive.Overlay.displayName;

const DialogContent = React.forwardRef<
    React.ElementRef<typeof DialogPrimitive.Content>,
    React.ComponentPropsWithoutRef<typeof DialogPrimitive.Content>
>(({ className, children, onOpenAutoFocus, onInteractOutside, ...props }, ref) => (
    <DialogPortal>
        <DialogOverlay />
        <DialogPrimitive.Content
            ref={ref}
            // A window closes only by its buttons or Esc: a stray click on the dark
            // backdrop, often meant to close an open select's list, would lose the form.
            onInteractOutside={(event) => {
                onInteractOutside?.(event);
                event.preventDefault();
            }}
            // On a phone the first field would grab focus and the keyboard would
            // cover half the sheet before the person has read it; there the sheet
            // opens still, and a field that should take the cursor asks with autoFocus.
            onOpenAutoFocus={(event) => {
                onOpenAutoFocus?.(event);
                if (window.matchMedia('(max-width: 639px)').matches) event.preventDefault();
            }}
            className={cn(
                // "fixed left-[50%] top-[50%] z-50 grid w-full max-w-lg translate-x-[-50%] translate-y-[-50%] gap-4 border bg-background p-6 shadow-lg duration-200 data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95 data-[state=closed]:slide-out-to-left-1/2 data-[state=closed]:slide-out-to-top-[48%] data-[state=open]:slide-in-from-left-1/2 data-[state=open]:slide-in-from-top-[48%] sm:rounded-lg",
                // On a phone the window is a sheet that rises from the bottom edge, as
                // in a mobile app: full width, rounded on top, within reach of the
                // thumb, scrolling inside itself when the form is taller than the
                // screen. From sm up it is the usual centred card.
                'fixed inset-x-0 bottom-0 z-50 grid max-h-[92dvh] w-full gap-4 overflow-y-auto rounded-t-2xl border-t bg-background px-4 pt-7 pb-[calc(1rem+env(safe-area-inset-bottom))] shadow-lg duration-300 data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 max-sm:data-[state=closed]:slide-out-to-bottom max-sm:data-[state=open]:slide-in-from-bottom',
                'sm:inset-x-auto sm:bottom-auto sm:left-[50%] sm:top-[50%] sm:max-h-[calc(100dvh-2rem)] sm:max-w-lg sm:translate-x-[-50%] sm:translate-y-[-50%] sm:rounded-lg sm:border sm:p-6 sm:duration-200 sm:data-[state=closed]:zoom-out-95 sm:data-[state=open]:zoom-in-95',
                className,
            )}
            {...props}
        >
            <div className="bg-muted-foreground/30 absolute top-2 left-1/2 h-1 w-10 -translate-x-1/2 rounded-full sm:hidden" aria-hidden="true" />
            {children}
            <DialogPrimitive.Close className="absolute right-2 top-3 flex size-9 items-center justify-center rounded-full opacity-70 max-sm:bg-muted sm:right-4 sm:top-4 sm:size-auto sm:rounded-sm ring-offset-background transition-opacity hover:opacity-100 focus:outline-hidden focus:ring-2 focus:ring-ring focus:ring-offset-2 disabled:pointer-events-none data-[state=open]:bg-accent data-[state=open]:text-muted-foreground">
                <X className="h-4 w-4" />
                <span className="sr-only">Close</span>
            </DialogPrimitive.Close>
        </DialogPrimitive.Content>
    </DialogPortal>
));
DialogContent.displayName = DialogPrimitive.Content.displayName;

const DialogHeader = ({ className, ...props }: React.HTMLAttributes<HTMLDivElement>) => (
    <div className={cn('flex flex-col space-y-1.5 pr-8 text-left sm:pr-0', className)} {...props} />
);
DialogHeader.displayName = 'DialogHeader';

const DialogFooter = ({ className, ...props }: React.HTMLAttributes<HTMLDivElement>) => (
    <div className={cn('flex flex-col-reverse max-sm:gap-2 sm:flex-row sm:justify-end sm:space-x-2', className)} {...props} />
);
DialogFooter.displayName = 'DialogFooter';

const DialogTitle = React.forwardRef<React.ElementRef<typeof DialogPrimitive.Title>, React.ComponentPropsWithoutRef<typeof DialogPrimitive.Title>>(
    ({ className, ...props }, ref) => (
        <DialogPrimitive.Title ref={ref} className={cn('text-lg font-semibold leading-none tracking-tight', className)} {...props} />
    ),
);
DialogTitle.displayName = DialogPrimitive.Title.displayName;

const DialogDescription = React.forwardRef<
    React.ElementRef<typeof DialogPrimitive.Description>,
    React.ComponentPropsWithoutRef<typeof DialogPrimitive.Description>
>(({ className, ...props }, ref) => <DialogPrimitive.Description ref={ref} className={cn('text-sm text-muted-foreground', className)} {...props} />);
DialogDescription.displayName = DialogPrimitive.Description.displayName;

export { Dialog, DialogClose, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogOverlay, DialogPortal, DialogTitle, DialogTrigger };
