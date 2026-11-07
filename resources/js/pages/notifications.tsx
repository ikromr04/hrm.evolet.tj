import { PageContent } from '@/components/page-content';
import { PageTitle } from '@/components/page-title';
import { Panel } from '@/components/panel';
import { StatusBadge, type StatusTone } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { formatDate } from '@/lib/employee';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { Bell, CircleAlert, CircleCheck, FileText, type LucideIcon } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Уведомления', href: '/notifications' }];

/** One note the application has left this person. */
export interface NotificationItem {
    id: string;
    /** Which kind of note it is; see `kinds` below. Null for a row written before its kind was named. */
    kind: string | null;
    title: string;
    body: string;
    /** Where the row leads. Null for a kind that only announces something. */
    url: string | null;
    read: boolean;
    at: string;
}

/**
 * What each kind of note is called in the list and the mark it is read by.
 *
 * This is the one place the section knows anything about kinds, and it is a
 * table rather than a chain of conditions for that reason: a second kind of
 * note is a notification class on the server and a line here, and nothing else
 * on this page changes. A kind that is not in the table still draws — the plain
 * bell and no word for the state — so a class can go out before this list has
 * learnt its name, and the note is readable in the meantime rather than
 * missing.
 *
 * The word for the state is a `StatusBadge` and not a colour on the row: three
 * tinted rows in a column of fifteen are a pattern nobody can read off, while
 * the badge says «Принято» in writing and is coloured as every other state in
 * this application is.
 */
const kinds: Record<string, { label: string; tone: StatusTone; icon: LucideIcon; iconTone: string }> = {
    sheet_request: { label: 'На проверке', tone: 'info', icon: FileText, iconTone: 'text-[#3B82F6]' },
    sheet_request_accepted: { label: 'Принято', tone: 'success', icon: CircleCheck, iconTone: 'text-brand-strong dark:text-[#C5E27A]' },
    sheet_request_rejected: { label: 'Отклонено', tone: 'danger', icon: CircleAlert, iconTone: 'text-destructive' },
};

/**
 * «Уведомления» — everything the application has said to this one person,
 * newest first.
 *
 * Newest first and nothing else: the unread are not lifted to the top, because
 * a note is about something that happened at a moment, and a list that reorders
 * itself as it is read is a list whose rows move out from under the cursor. The
 * unread are marked instead — one dot at the left and a heading in a heavier
 * weight — and the bell has already said how many of them there are.
 *
 * A whole row is the target, as every list in this application has it: there is
 * one thing to do about a note, so the row does it rather than carrying a link
 * that does it. Pressing it marks the note read and then follows where it
 * leads, in that order — the mark is the point, and a reader who presses a row
 * and lands on the card has dealt with it whether or not they come back here.
 *
 * «Прочитать все» is in the header and only while there is something to clear.
 * It is for coming back to a bell with a dozen on it already knowing what all
 * of them are about, which is most of the times anybody opens this page at all.
 */
export default function Notifications({ items, unread }: { items: NotificationItem[]; unread: number }) {
    /**
     * Marked read, and then wherever the note leads.
     *
     * The marking is its own request answering with `back()`, so the list comes
     * back with this row already read before the browser moves on: a note whose
     * page is opened and then left by the arrow back must not be waiting
     * unread again.
     */
    const open = (item: NotificationItem) => {
        if (item.read) {
            if (item.url) router.visit(item.url);

            return;
        }

        router.post(
            route('notifications.read'),
            { id: item.id },
            {
                preserveScroll: true,
                onSuccess: () => {
                    if (item.url) router.visit(item.url);
                },
            },
        );
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs} fitViewport>
            <Head title="Уведомления" />

            <PageContent className="md:min-h-0">
                <PageTitle>Уведомления</PageTitle>

                {/* The block is named for what it holds rather than repeating
                    the page's own name over itself: the bar above says
                    «Уведомления», and so does the heading beside it. */}
                <Panel
                    title="Входящие"
                    count={items.length}
                    action={
                        unread > 0 && (
                            <Button
                                type="button"
                                variant="link"
                                onClick={() => router.post(route('notifications.read_all'), {}, { preserveScroll: true })}
                                className="text-brand-strong h-auto p-0 text-xs font-normal dark:text-[#C5E27A]"
                            >
                                Прочитать все
                            </Button>
                        )
                    }
                    // The one card of the page, holding the height the shell
                    // gives it: the list scrolls inside the card rather than
                    // growing the page under the bar, the way the tables of
                    // this application sit in theirs. On a phone there is no
                    // height to hold and the page itself scrolls.
                    className="md:min-h-0 md:flex-1 md:overflow-hidden"
                    bodyClassName="scroll-soft scroll-stable gap-1.5 overflow-y-auto md:min-h-0 md:flex-1"
                >
                    {items.length === 0 ? (
                        <p className="text-muted-foreground text-sm max-md:py-3.5 max-md:text-[15px]">Уведомлений пока нет</p>
                    ) : (
                        items.map((item) => <Row key={item.id} item={item} onOpen={() => open(item)} />)
                    )}
                </Panel>
            </PageContent>
        </AppLayout>
    );
}

/**
 * One note as one row: whether it has been read, what kind it is, what it says,
 * and when it was left.
 *
 * Two lines of words and not one. A heading on its own — «Личный листок
 * отклонён» — is the kind of thing fifteen of which say nothing; the sentence
 * under it names the colleague or the reason, which is what the reader came for
 * and what saves them opening the page to find out whether it concerns them.
 *
 * The dot keeps its place on a note that has been read instead of
 * disappearing: the headings of fifty rows have to start at one place, and a
 * column that steps in and out by eight points as it is read down is harder to
 * follow than one carrying a few invisible dots.
 *
 * The date is at the far end in figures of one width, as the lists of this
 * application have it, and it is a date and not an hour: everything here is
 * read in days — «отправлено вчера, приняли сегодня» — and «05 ноя. 2026» is
 * how this application writes a day everywhere else.
 */
function Row({ item, onOpen }: { item: NotificationItem; onOpen: () => void }) {
    const kind = item.kind === null ? undefined : kinds[item.kind];
    const Mark = kind?.icon ?? Bell;

    return (
        <button
            type="button"
            onClick={onOpen}
            className="bg-muted/40 hover:bg-muted flex w-full min-w-0 items-start gap-3 rounded-lg border px-3 py-2 text-left transition-colors"
        >
            {/* Said in the row's own words below, so the mark is a drawing. */}
            <span
                aria-hidden="true"
                className={cn('mt-1.5 size-2 shrink-0 rounded-full', item.read ? 'bg-transparent' : 'bg-brand-strong dark:bg-[#C5E27A]')}
            />

            <Mark className={cn('mt-0.5 size-4 shrink-0', kind?.iconTone ?? 'text-muted-foreground')} aria-hidden="true" />

            <span className="flex min-w-0 flex-1 flex-col gap-0.5">
                <span className="flex flex-wrap items-center gap-2">
                    <span className={cn('text-sm break-words', item.read ? 'font-normal' : 'font-semibold')}>{item.title}</span>
                    {kind && <StatusBadge tone={kind.tone}>{kind.label}</StatusBadge>}
                    {!item.read && <span className="sr-only">Не прочитано</span>}
                </span>

                <span className="text-muted-foreground text-[13px] break-words">{item.body}</span>
            </span>

            <span className="text-muted-foreground shrink-0 text-[13px] tabular-nums">{formatDate(item.at)}</span>
        </button>
    );
}
