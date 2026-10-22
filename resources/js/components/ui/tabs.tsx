import * as TabsPrimitive from '@radix-ui/react-tabs';
import * as React from 'react';

import { cn } from '@/lib/utils';

const Tabs = TabsPrimitive.Root;

const TabsList = React.forwardRef<React.ElementRef<typeof TabsPrimitive.List>, React.ComponentPropsWithoutRef<typeof TabsPrimitive.List>>(
    ({ className, ...props }, ref) => (
        <TabsPrimitive.List
            ref={ref}
            className={cn('bg-muted text-muted-foreground inline-flex h-9 w-fit items-center justify-center rounded-lg p-[3px]', className)}
            {...props}
        />
    ),
);
TabsList.displayName = TabsPrimitive.List.displayName;

const TabsTrigger = React.forwardRef<React.ElementRef<typeof TabsPrimitive.Trigger>, React.ComponentPropsWithoutRef<typeof TabsPrimitive.Trigger>>(
    ({ className, ...props }, ref) => (
        <TabsPrimitive.Trigger
            ref={ref}
            className={cn(
                'ring-offset-background focus-visible:ring-ring data-[state=active]:bg-background data-[state=active]:text-foreground inline-flex h-[calc(100%-1px)] items-center justify-center gap-1.5 rounded-md border border-transparent px-2.5 py-1 text-sm font-medium whitespace-nowrap transition-colors focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-hidden disabled:pointer-events-none disabled:opacity-50 data-[state=active]:shadow-sm [&_svg]:pointer-events-none [&_svg]:size-4 [&_svg]:shrink-0',
                className,
            )}
            {...props}
        />
    ),
);
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
