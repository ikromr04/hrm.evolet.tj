import { SidebarProvider } from '@/components/ui/sidebar';
import { useState } from 'react';

interface AppShellProps {
    children: React.ReactNode;
    variant?: 'header' | 'sidebar';
}

/**
 * Whether the sidebar was left open, remembered between visits. A browser that
 * refuses storage — a private window, a policy — throws on the very first read,
 * and this one runs before anything else is drawn: unguarded, it takes the whole
 * application down rather than losing one remembered preference.
 */
function wasOpen(): boolean {
    try {
        return typeof window === 'undefined' || localStorage.getItem('sidebar') !== 'false';
    } catch {
        return true;
    }
}

export function AppShell({ children, variant = 'header' }: AppShellProps) {
    const [isOpen, setIsOpen] = useState(wasOpen);

    const handleSidebarChange = (open: boolean) => {
        setIsOpen(open);

        try {
            localStorage.setItem('sidebar', String(open));
        } catch {
            // The sidebar still opens and closes; only the remembering is lost.
        }
    };

    if (variant === 'header') {
        return <div className="flex min-h-screen w-full flex-col">{children}</div>;
    }

    return (
        <SidebarProvider defaultOpen={isOpen} open={isOpen} onOpenChange={handleSidebarChange}>
            {children}
        </SidebarProvider>
    );
}
