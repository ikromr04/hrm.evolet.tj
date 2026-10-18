import EvoletLogo, { EvoletMark } from '@/components/evolet-logo';

// Wrapped in a span: sidebar menu buttons force direct <svg> children to 16px.
export default function AppLogo() {
    return (
        // The chip stands next to the wordmark rather than away at the far edge:
        // it says which Evolet system this is, and that belongs with the name.
        <span className="flex w-full items-center gap-4">
            <EvoletMark className="hidden size-6 group-data-[collapsible=icon]:block" />
            <EvoletLogo className="h-8 w-32 group-data-[collapsible=icon]:hidden dark:hidden" />
            <EvoletLogo tone="light" className="hidden h-8 w-32 dark:block dark:group-data-[collapsible=icon]:hidden" />
            <span className="border-sidebar-border bg-background text-muted-foreground rounded-[5px] border px-[5px] py-0.5 text-[10px] font-semibold tracking-[0.04em] group-data-[collapsible=icon]:hidden">
                HRM
            </span>
        </span>
    );
}
