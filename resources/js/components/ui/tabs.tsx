import * as TabsPrimitive from '@radix-ui/react-tabs';
import * as React from 'react';

import { cn } from '@/lib/utils';

/**
 * How a strip of tabs is drawn.
 *
 * «default»: the open tab is a plate on a sunken strip, as shadcn draws it.
 * «line»: plain words, and a line under the open one alone — nothing runs
 * under the strip itself. What a page uses when the tabs stand over its whole
 * content rather than switching a control inside it.
 */
type TabsVariant = 'default' | 'line';

const TabsVariantContext = React.createContext<TabsVariant>('default');

const Tabs = TabsPrimitive.Root;

const TabsList = React.forwardRef<
    React.ElementRef<typeof TabsPrimitive.List>,
    React.ComponentPropsWithoutRef<typeof TabsPrimitive.List> & { variant?: TabsVariant }
>(({ className, variant = 'default', ...props }, ref) => (
    <TabsVariantContext.Provider value={variant}>
        <TabsPrimitive.List
            ref={ref}
            className={cn(
                'inline-flex items-center',
                variant === 'line'
                    ? 'h-auto w-full justify-start gap-5 rounded-none bg-transparent p-0'
                    : 'bg-muted text-muted-foreground h-9 w-fit justify-center rounded-lg p-[3px]',
                className,
            )}
            {...props}
        />
    </TabsVariantContext.Provider>
));
TabsList.displayName = TabsPrimitive.List.displayName;

const TabsTrigger = React.forwardRef<
    React.ElementRef<typeof TabsPrimitive.Trigger>,
    React.ComponentPropsWithoutRef<typeof TabsPrimitive.Trigger> & { variant?: TabsVariant }
>(({ className, variant, ...props }, ref) => {
    const inherited = React.useContext(TabsVariantContext);
    const drawn = variant ?? inherited;

    return (
        <TabsPrimitive.Trigger
            ref={ref}
            className={cn(
                'ring-offset-background focus-visible:ring-ring inline-flex items-center justify-center gap-1.5 text-sm font-medium whitespace-nowrap transition-colors focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-hidden disabled:pointer-events-none disabled:opacity-50 [&_svg]:pointer-events-none [&_svg]:size-4 [&_svg]:shrink-0',
                drawn === 'line'
                    ? // The line belongs to the word, so the tab keeps no padding
                      // of its own at the sides and the strip spaces them out. It
                      // stands 36px tall, as the controls beside it do, the line
                      // along its foot counted in.
                      'text-muted-foreground hover:text-foreground data-[state=active]:border-b-brand data-[state=active]:text-foreground h-9 shrink-0 rounded-none border-b-2 border-transparent px-0 data-[state=active]:font-semibold'
                    : 'data-[state=active]:bg-background data-[state=active]:text-foreground h-[calc(100%-1px)] rounded-md border border-transparent px-2.5 py-1 data-[state=active]:shadow-sm',
                className,
            )}
            {...props}
        />
    );
});
TabsTrigger.displayName = TabsPrimitive.Trigger.displayName;

const TabsContent = React.forwardRef<React.ElementRef<typeof TabsPrimitive.Content>, React.ComponentPropsWithoutRef<typeof TabsPrimitive.Content>>(
    ({ className, ...props }, ref) => (
        <TabsPrimitive.Content
            ref={ref}
            className={cn('ring-offset-background focus-visible:ring-ring flex-1 outline-hidden focus-visible:ring-2 focus-visible:ring-offset-2', className)}
            {...props}
        />
    ),
);
TabsContent.displayName = TabsPrimitive.Content.displayName;

export { Tabs, TabsContent, TabsList, TabsTrigger };
