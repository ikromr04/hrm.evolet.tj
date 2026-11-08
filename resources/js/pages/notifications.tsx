import {
    countActiveFilters,
    cycleSort,
    DataTable,
    isSorted,
    MobileListTools,
    orderedColumns,
    TableMemoryButton,
    useDebouncedSearch,
    useRememberedQuery,
    useTableMemory,
    useTableView,
    type ColumnDef,
    type Sort,
    type ViewState,
} from '@/components/data-table';
import { Hint } from '@/components/data-table/hint';
import { PageContent } from '@/components/page-content';
import { PageTitle } from '@/components/page-title';
import { Pagination, type Paginated } from '@/components/pagination';
import { RowActions } from '@/components/row-actions';
import { SearchInput } from '@/components/search-input';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import AppLayout from '@/layouts/app-layout';
import { formatDate } from '@/lib/employee';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { ChevronDown, Mail, MailOpen, Trash2 } from 'lucide-react';
import { useEffect, useMemo, useState, type ReactNode } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Уведомления', href: '/notifications' }];

/** Which of the two lists is open; it comes from the address and goes back there. */
export type NotificationFilter = 'all' | 'unread';

/** Whoever the note is from. Null on a note filed before the sender was recorded. */
export interface NotificationSender {
    id: number;
    /** The surname and the name, short, as the note was written with them. */
    name: string;
}

/** One note the application has left this person. */
export interface NotificationItem {
    id: string;
    /**
     * Which kind of note it is, as the class that filed it named itself —
     * «sheet_request» and the rest.
     *
     * Nothing on this page reads it. It is kept because the row is the note,
     * and the day one kind of them wants drawing differently from another, the
     * page would have nothing to tell them apart by. Null on a note filed
     * before the kinds were named.
     */
    kind: string | null;
    title: string;
    body: string;
    /** Where the row leads. Null for a kind that only announces something. */
    url: string | null;
    read: boolean;
    at: string;
    from: NotificationSender | null;
}

/** What narrows the columns, under the names the server reads them by. */
interface Filters {
    from: string[];
    title: string[];
    body: string;
    at_from: string | null;
    at_to: string | null;
}

interface Props {
    filter: NotificationFilter;
    /**
     * Whether this reader may throw a note away.
     *
     * One account in the building, and the page asks before it draws the offer
     * rather than finding out by being refused. Reading and marking read are
     * everybody's — that is what a reader does with their own list — while what
     * goes with a deleted note is the record that anybody was told at all.
     */
    canDelete: boolean;
    /**
     * How much is in each list.
     *
     * Counted over everything rather than off `notes`, because «Непрочитанные
     * 3» is read before the choice is made and a number counted off the rows in
     * front of the reader would only ever count the list already open.
     */
    counts: Record<NotificationFilter, number>;
    /** What is being looked for across every field of a note. */
    q: string;
    sort: Sort;
    /** The columns the server can actually sort by. */
    sortable: string[];
    filters: Filters;
    /** Everything there is to tick in the two list filters, as the server knows it. */
    options: { from: { value: string; label: string }[]; title: { value: string; label: string }[] };
    perPage: number;
    perPageOptions: number[];
    notes: Paginated<NotificationItem>;
}

/**
 * The two tabs, in the order they stand in.
 *
 * There is no «Прочитанные» among them: a list of notes already dealt with is
 * a list nobody goes looking for, and «Все» holds them anyway, in the order
 * they arrived and beside the ones that still want answering.
 */
const narrowings: { key: NotificationFilter; label: string }[] = [
    { key: 'all', label: 'Все' },
    { key: 'unread', label: 'Непрочитанные' },
];

/**
 * Newest first, which is the order a list of notes is read in — and the order
 * it comes back to when the arrow on «Дата» has been pressed round twice.
 *
 * A note is about something that happened at a moment, so the day it happened
 * on is what the list is ordered by until somebody asks otherwise. The unread
 * are not lifted to the top of it: a list that reorders itself as it is read is
 * a list whose rows move out from under the cursor. They stand out where they
 * fell instead — dark and a shade heavier in a column that has read itself back
 * into grey — and the bell has already said how many of them there are.
 */
const DEFAULT_SORT: Sort = { key: 'at', direction: 'desc' };

/**
 * Where this table's arrangements are kept.
 *
 * Written here rather than in `TABLE_KEYS`, which is for the tables the main
 * menu links to with their sorting and filters already in the address: nothing
 * links to this list that way. The bell and the account menu lead to the plain
 * `/notifications`, which is this list as the page itself draws it, and the
 * kept query is picked up from storage on the way in.
 */
const VIEW_KEY = 'notifications.table.view.v1';
const QUERY_KEY = 'notifications.table.query';

// Nothing hidden and nothing held to an edge. A pinned column is drawn before
// every other whatever order a viewer has dragged their columns into, which is
// a surprise nobody asked for; whoever wants the boxes or the day stuck to an
// edge pins them from the column's own menu.
const defaultView = (): ViewState => ({ hidden: [], pinned: { left: [], right: [] } });

/**
 * The checkbox over the list, drawn for the state the shadcn box leaves plain.
 *
 * Radix shows its indicator for «indeterminate» as well as for «checked», so a
 * half-made selection would otherwise be a tick — which says «all of them» as
 * plainly as the full box does. The tick is hidden instead and a bar is drawn
 * across the box, which is what a partial selection looks like everywhere else
 * a person has met one, and the box is filled the same way the checked one is
 * so the two read as two states of one control.
 */
const partlyChecked = cn(
    'data-[state=indeterminate]:border-accent-foreground data-[state=indeterminate]:bg-primary data-[state=indeterminate]:text-primary-foreground relative',
    'data-[state=indeterminate]:[&_svg]:hidden',
    'data-[state=indeterminate]:after:absolute data-[state=indeterminate]:after:inset-x-[3px] data-[state=indeterminate]:after:top-1/2 data-[state=indeterminate]:after:h-[2px]',
    'data-[state=indeterminate]:after:-translate-y-1/2 data-[state=indeterminate]:after:rounded-full data-[state=indeterminate]:after:bg-current data-[state=indeterminate]:after:content-[""]',
);

/**
 * How a note that has been read differs from one that has not, said in the one
 * place a row can be reached from.
 *
 * Read or not is the row itself and not a mark beside it, the way a list of
 * mail has always said it: a note still waiting stands in the foreground colour
 * on the ground the table's head stands on, and one that has been dealt with
 * reads back into the muted colour on the card's own ground. A column of forty
 * read notes is then quiet, and the two that arrived this morning are the only
 * dark lines in it — which is something the eye finds without being told where
 * to look. Colour of text alone was too fine a difference to see down a column;
 * in the dark theme, where everything is a grey already, it was next to none.
 *
 * It is said as a class on the row, which the table takes and asks nothing
 * about: which of its rows are read is this page's business, and a table that
 * knew would be a table that knew what a notification is.
 */
/** The columns, each with the filter that narrows it. */
function buildColumns(options: Props['options']): ColumnDef[] {
    return [
        // Headed by the box that ticks the page rather than by a word; `head`
        // on the column is what puts it there. Locked, so it cannot be hidden:
        // a list of rows with nothing to tick them with would still offer the
        // two actions and never let anything be chosen for them.
        { key: 'pick', label: 'Выбор', width: 64, bare: true },
        { key: 'from', label: 'От кого', width: 200, filter: { type: 'multi', param: 'from', options: options.from } },
        { key: 'title', label: 'Тема', width: 300, filter: { type: 'multi', param: 'title', options: options.title } },
        // The column glad of whatever room the others leave: it is the sentence
        // that says what the note is actually about. The five together are
        // narrower than the card on an ordinary screen, which is what hands this
        // one the room left over instead of setting the table scrolling
        // sideways past a date nobody can then read.
        { key: 'body', label: 'Описание', width: 320, fill: true, filter: { type: 'text', param: 'body', placeholder: 'Слово из описания' } },
        { key: 'at', label: 'Дата', width: 150, filter: { type: 'dates', from: 'at_from', to: 'at_to' } },
    ];
}

/**
 * «Уведомления» — everything the application has said to this one person.
 *
 * One of the lists of this application and built out of the same machinery:
 * tabs that narrow it, a box to look through it from, columns that sort, filter,
 * drag, pin and hide, an arrangement that can be kept under a name, and the
 * paging under the rows. It used to be a hand-written table, on the grounds that
 * there was nothing here to sort by; a reader looking for what a particular
 * colleague sent, or for the fortnight a request went missing in, had to read
 * forty rows to find it, and every other list in the application already
 * answered that question.
 *
 * A whole row is the target, as the lists here have it: there is one thing to do
 * about a note, so the row does it rather than carrying a link that does it.
 * Pressing it marks the note read and then follows where it leads, in that
 * order — the mark is the point, and a reader who presses a row and lands on the
 * card has dealt with it whether or not they come back here.
 *
 * Which list is open, what is being looked for, how it is sorted and what is
 * filtered are all the address's business and not this component's: they arrive
 * as props and go back into the address when they change, so a list can be sent
 * to somebody, kept in a bookmark and left by the arrow back.
 */
export default function Notifications({ filter, canDelete, counts, q, sort, sortable, filters, options, perPage, perPageOptions, notes }: Props) {
    // The rows of the page in front of the reader. Everything on this page
    // works on those and not on the list behind them: a tick is a tick on
    // something drawn, and so is every action the strip offers.
    const items = notes.data;
    /**
     * The notes with a tick against them, by id.
     *
     * Local and nowhere else: a selection is what the hand is holding this
     * minute, and an address carrying fifteen uuids is an address nobody can
     * read or send. Hidden columns are kept out of the address for the same
     * reason, and this is a shorter-lived thing than those.
     */
    const [selected, setSelected] = useState<string[]>([]);

    /**
     * The list underneath is another list now, so the ticks are let go.
     *
     * Keyed on the address, which is the one thing that changes for every one of
     * them: another tab, another word in the box, a filter, a different order, a
     * page further on. A tick against a note that is no longer drawn is a tick
     * nobody can take back — and it would be sent to the server all the same by
     * whichever action was pressed next.
     *
     * Marking one row read leaves the address alone, so the selection survives
     * it, which is what a reader who ticked eleven rows and then opened one of
     * them expects to come back to.
     */
    const { url } = usePage();
    useEffect(() => setSelected([]), [url]);

    /**
     * What is selected and still on the page.
     *
     * Read off `items` rather than trusted from state, so an id left over from
     * a list that has since been reloaded — a note deleted in another window, a
     * row that moved out of «Непрочитанные» — counts for nothing instead of
     * being sent to the server as part of the next action.
     */
    const chosen = items.filter((item) => selected.includes(item.id));

    const toggle = (id: string, on: boolean) => setSelected((held) => (on ? [...held, id] : held.filter((other) => other !== id)));

    /**
     * Which of the two marks the selection is offered.
     *
     * One button and not two: the row of actions is read in the second it
     * appears, and a pair of opposites side by side is a question rather than
     * an offer. A selection with anything unread in it is offered «прочитано» —
     * that is what somebody who has just ticked eleven rows came to do — and
     * only a selection every note of which has already been read is offered the
     * way back. So the button always changes something: it is never the mark
     * that half the selection already carries.
     */
    const marking = chosen.some((item) => !item.read);

    /** The box over the list: the whole page, part of it, or none of it. */
    const taken: boolean | 'indeterminate' = items.length > 0 && chosen.length === items.length ? true : chosen.length > 0 ? 'indeterminate' : false;

    const columns = useMemo(() => buildColumns(options), [options]);
    const defaults = useMemo(defaultView, []);
    const { view, setView, pin, toggleHidden, reorder, order } = useTableView(
        VIEW_KEY,
        columns.map((column) => column.key),
        defaults,
    );
    const isHidden = (key: string) => view.hidden.includes(key);

    useRememberedQuery(QUERY_KEY, VIEW_KEY);
    // Everything the viewer has done to this list — the columns and the
    // questions both — is kept by one press and forgotten by the next.
    const memory = useTableMemory({
        viewKey: VIEW_KEY,
        queryKey: QUERY_KEY,
        columnKeys: columns.map((column) => column.key),
        view,
        setView,
        fallback: defaults,
    });

    /**
     * The box in the head, handed to the column that stands under it.
     *
     * The table draws it and knows nothing else about it: what it is ticking,
     * what it means and what happens when it is pressed are this page's, since
     * this page is the only thing that knows what a row of the column under it
     * means. Nothing to tick is not a reason to leave the head empty — an empty
     * head would read as a column with no name — so the box is drawn and
     * disabled.
     */
    const drawn = useMemo(
        () =>
            columns.map((column) =>
                column.key === 'pick'
                    ? {
                          ...column,
                          head: (
                              <Hint label="Выбрать все">
                                  {/* The box is wrapped rather than made the
                                      hint's own trigger: a trigger is handed the
                                      tooltip's `data-state`, and on the box
                                      itself that writes over the state the box
                                      is drawn from — «ticked», «half» — leaving
                                      an empty square whatever is selected. */}
                                  <span className="inline-flex">
                                      <Checkbox
                                          aria-label="Выбрать все"
                                          checked={taken}
                                          disabled={items.length === 0}
                                          onCheckedChange={(on) => setSelected(on ? items.map((item) => item.id) : [])}
                                          className={partlyChecked}
                                      />
                                  </span>
                              </Hint>
                          ),
                      }
                    : column,
            ),
        [columns, items, taken],
    );

    /**
     * The list asked for again, narrowed another way.
     *
     * What is not narrowed is left out of the address rather than written as
     * «filter=all&q=»: the plain `/notifications` is the whole list, and that is
     * the address the bell and the account menu lead to. The opening order is
     * left out for the same reason; any other goes whole, since the server reads
     * a column named without a direction as ascending.
     *
     * `replace`, as every list of this application does it: a word arrived at
     * letter by letter would otherwise take eight presses of the arrow back to
     * leave, and the arrow back belongs to the page somebody came from.
     * `preserveState` is what keeps the box being typed in, the scroll and this
     * page's own ticks from being thrown away on the way.
     */
    const visit = (next: { filter?: NotificationFilter; q?: string; filters?: Partial<Filters>; sort?: Sort; perPage?: number }) => {
        const asked = {
            filter: next.filter ?? filter,
            q: next.q ?? q,
            filters: { ...filters, ...next.filters },
            sort: next.sort ?? sort,
            perPage: next.perPage ?? perPage,
        };

        type Value = string | number | (string | number)[] | null;
        const params: Record<string, Value> = {
            ...asked.filters,
            filter: asked.filter === 'all' ? null : asked.filter,
            q: asked.q,
            sort: isSorted(asked.sort, DEFAULT_SORT) ? asked.sort.key : null,
            direction: isSorted(asked.sort, DEFAULT_SORT) ? asked.sort.direction : null,
            per_page: asked.perPage === perPageOptions[0] ? null : asked.perPage,
        };

        const query = Object.fromEntries(
            Object.entries(params).filter(([, value]) => (Array.isArray(value) ? value.length > 0 : value !== null && value !== '')),
        ) as Record<string, Exclude<Value, null>>;

        router.get(route('notifications.index'), query, { preserveState: true, preserveScroll: true, replace: true });
    };

    const applyFilters = (changes: Partial<Filters>) => visit({ filters: changes });
    // Typing searches on its own, once the typing stops.
    const [search, setSearch] = useDebouncedSearch(q, (asked) => visit({ q: asked }));
    const sortBy = (key: string, direction?: 'asc' | 'desc') => visit({ sort: direction ? { key, direction } : cycleSort(sort, key, DEFAULT_SORT) });

    /** Done with the selection: the list comes back, and the ticks are let go. */
    const done = () => setSelected([]);

    const mark = (ids: string[], read: boolean) => router.post(route('notifications.read'), { ids, read }, { preserveScroll: true, onSuccess: done });

    const destroy = (ids: string[]) => router.delete(route('notifications.destroy'), { data: { ids }, preserveScroll: true, onSuccess: done });

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
            { ids: [item.id], read: true },
            {
                preserveScroll: true,
                onSuccess: () => {
                    if (item.url) router.visit(item.url);
                },
            },
        );
    };

    const cell = (column: ColumnDef, item: NotificationItem): ReactNode => {
        switch (column.key) {
            case 'pick':
                return (
                    // A press on the box is a press on a button, which the
                    // table leaves to answer for itself rather than opening the
                    // row underneath it.
                    <span className="flex items-center">
                        <Checkbox
                            checked={selected.includes(item.id)}
                            onCheckedChange={(on) => toggle(item.id, on === true)}
                            aria-label={`Выбрать: ${item.title}`}
                        />
                    </span>
                );
            case 'from':
                // A note with nobody against it — those filed before the sender
                // was recorded — is drawn the way every missing answer in this
                // application is drawn, with a dash, and muted whatever state
                // the row is in: a missing answer is not news about anybody. Not
                // «Система»: nobody checked whether it was the system, and a row
                // that says so is a row that invented it.
                //
                // The name is text and not a way to their card, though the
                // application has one: the row leads to the note's own page, and
                // a link inside a row that is itself pressed is two answers to
                // one press.
                return item.from ? (
                    <span className={item.read ? 'font-medium' : 'font-semibold'}>{item.from.name}</span>
                ) : (
                    <span className="text-muted-foreground">—</span>
                );
            case 'title':
                return (
                    // The weight never drops to the sentence's: a heading and
                    // its sentence are a heading and its sentence whether or not
                    // the note has been read.
                    <span className={item.read ? 'font-medium' : 'font-semibold'}>
                        {item.title}
                        {/* The colour and the weight in words, for whoever is
                            read the row rather than looking at it. */}
                        {!item.read && <span className="sr-only"> Не прочитано</span>}
                    </span>
                );
            case 'body':
                // What the note is actually about — the colleague, the reason —
                // which is what saves the reader opening the page to find out
                // whether it concerns them. Muted in both states: it is the
                // second thing on the row whichever state the note is in, and a
                // row where everything is dark has nothing left to say which
                // half of it matters.
                return <span className={cn('text-muted-foreground', item.read ? 'font-normal' : 'font-medium')}>{item.body}</span>;
            default:
                // A date and not an hour: everything here is read in days —
                // «отправлено вчера, приняли сегодня» — and «08 ноя. 2026» is
                // how this application writes a day everywhere else. Figures of
                // one width, so a column of them lines up.
                return <span className={cn('tabular-nums', !item.read && 'font-semibold')}>{formatDate(item.at)}</span>;
        }
    };

    /**
     * Whether the list has been narrowed at all, which is two different pieces
     * of news when nothing comes back: an account nothing has been said to yet,
     * and a list narrowed to nothing. The second would otherwise be read as the
     * first.
     */
    const narrowed = filter !== 'all' || q !== '' || countActiveFilters(columns, filters as unknown as Record<string, unknown>, () => true) > 0;

    return (
        <AppLayout breadcrumbs={breadcrumbs} fitViewport>
            <Head title="Уведомления" />

            <PageContent className="md:min-h-0">
                <PageTitle>Уведомления</PageTitle>

                {/* On a phone this is one line — the search and the button behind
                    which sorting and filters wait — with the lists as chips under
                    it. It is allowed to wrap there once something has been
                    ticked, so that what can be done with the selection comes down
                    onto a line of its own instead of squeezing the box people
                    type in. */}
                <div className={cn('flex flex-wrap items-center gap-2', chosen.length === 0 && 'max-md:flex-nowrap')}>
                    {/* Which of the two lists is open, with how much is in each
                        beside the word: the figure is read before the choice is
                        made, and «Непрочитанные 0» saves opening it at all.
                        Choosing one is a visit, so the value is the address's and
                        the strip only says which of them is open. */}
                    <Tabs value={filter} onValueChange={(next) => visit({ filter: next as NotificationFilter })} className="shrink-0 max-md:hidden">
                        <TabsList aria-label="Списки уведомлений">
                            {narrowings.map((one) => (
                                <TabsTrigger key={one.key} value={one.key}>
                                    {one.label}
                                    <span className="text-muted-foreground text-xs tabular-nums">{counts[one.key]}</span>
                                </TabsTrigger>
                            ))}
                        </TabsList>
                    </Tabs>

                    {/* The application's own field, with the magnifier laid over
                        its left padding and our own cross at the other end. */}
                    <SearchInput
                        id="notifications-search"
                        value={search}
                        onChange={setSearch}
                        placeholder="Поиск по всем полям"
                        className="w-80 min-w-48 max-md:w-auto max-md:min-w-0 max-md:flex-1 md:mr-auto"
                        inputClassName="max-md:bg-card text-sm max-md:rounded-xl max-md:border-transparent max-md:shadow-none"
                    />

                    <MobileListTools
                        columns={drawn}
                        filters={filters as unknown as Record<string, unknown>}
                        onFilter={(changes) => applyFilters(changes as Partial<Filters>)}
                        sort={sort}
                        sortable={sortable}
                        onSort={sortBy}
                        defaultSort={DEFAULT_SORT}
                        className="bg-card rounded-xl border-transparent shadow-none"
                    />

                    {/* Words and no signs. A sign is for a button that has to fit
                        in a row of a table, and this strip is as wide as the page:
                        these two do something to everything that has been ticked,
                        and what that is should be read rather than guessed from a
                        picture of an envelope.

                        They stand beside the selection's own box rather than
                        inside the table, because a table head is for the columns
                        and this is not about a column. They come and go with the
                        selection: a strip of two actions standing over a list
                        nothing is ticked in is two buttons that do nothing. */}
                    {chosen.length > 0 && (
                        // Buttons, and the height of the one they stand beside:
                        // they do something to everything that has been ticked,
                        // which is not the weight of a line of text, and a row
                        // of controls where one is a button and the next a piece
                        // of writing reads as two different kinds of offer.
                        <div className="flex items-center gap-2 max-md:w-full">
                            <Button
                                type="button"
                                className="h-9"
                                onClick={() =>
                                    mark(
                                        chosen.map((item) => item.id),
                                        marking,
                                    )
                                }
                            >
                                {marking ? 'Отметить как прочитанное' : 'Отметить как непрочитанное'}
                            </Button>

                            {/* The one of the two that cannot be taken back is
                                drawn as the one that cannot be taken back — and
                                offered to the one account that may do it. */}
                            {canDelete && (
                                <Button type="button" variant="destructive" className="h-9" onClick={() => destroy(chosen.map((item) => item.id))}>
                                    Удалить
                                </Button>
                            )}
                        </div>
                    )}

                    {/* The columns ride in the same menu as the view they are
                        part of. The column of boxes is left out of the list: it
                        cannot be hidden, and a line that can only be read and not
                        pressed is a line nobody needs to read. */}
                    <TableMemoryButton memory={memory} className="h-9 max-md:hidden">
                        {orderedColumns(columns, order)
                            .filter((column) => column.key !== 'pick')
                            .map((column) => (
                                <DropdownMenuCheckboxItem
                                    key={column.key}
                                    checked={!isHidden(column.key)}
                                    disabled={column.key === 'title'}
                                    onCheckedChange={(checked) => toggleHidden(column.key, !checked)}
                                    onSelect={(event) => event.preventDefault()}
                                >
                                    {column.label}
                                </DropdownMenuCheckboxItem>
                            ))}
                    </TableMemoryButton>
                </div>

                {/* The same two lists as chips on one line that scrolls sideways,
                    bleeding to the screen edges like a native segmented strip. */}
                <nav
                    aria-label="Списки уведомлений"
                    className="-mx-3 flex gap-2 overflow-x-auto px-3 [scrollbar-width:none] md:hidden [&::-webkit-scrollbar]:hidden"
                >
                    {narrowings.map((one) => (
                        <button
                            key={one.key}
                            type="button"
                            onClick={() => visit({ filter: one.key })}
                            aria-current={filter === one.key ? 'page' : undefined}
                            className={cn(
                                'flex h-8 shrink-0 items-center gap-1.5 rounded-full px-3 text-sm font-medium whitespace-nowrap transition-colors',
                                filter === one.key ? 'bg-brand-soft text-foreground font-semibold dark:bg-white/10' : 'bg-card text-foreground',
                            )}
                        >
                            {one.label}
                            <span className="text-muted-foreground text-xs tabular-nums">{counts[one.key]}</span>
                        </button>
                    ))}
                </nav>

                <DataTable
                    columns={drawn}
                    rows={items}
                    rowKey={(item) => item.id}
                    renderCell={cell}
                    sort={sort}
                    sortable={sortable}
                    onSort={sortBy}
                    filters={filters as unknown as Record<string, unknown>}
                    onFilter={(changes) => applyFilters(changes as Partial<Filters>)}
                    view={view}
                    onPin={pin}
                    reorderable
                    onReorder={reorder}
                    onHide={(key) => toggleHidden(key, true)}
                    lockedKey="pick"
                    // The same two things the strip offers a selection, offered
                    // to one row without ticking it first: they float over the
                    // day the note arrived on while the cursor is on the row, as
                    // the actions of every other table here do. Icons on a row
                    // and words in the strip — a row has no space for a
                    // sentence, and the strip has nothing else to say.
                    actions={(item) => (
                        <RowActions
                            of={item.title}
                            actions={[
                                {
                                    key: 'read',
                                    label: item.read ? 'Отметить как непрочитанное' : 'Отметить как прочитанное',
                                    Icon: item.read ? Mail : MailOpen,
                                    onSelect: () => mark([item.id], !item.read),
                                },
                                ...(canDelete
                                    ? [{ key: 'delete', label: 'Удалить', Icon: Trash2, danger: true, onSelect: () => destroy([item.id]) }]
                                    : []),
                            ]}
                        />
                    )}
                    rowClass={(item) => (item.read ? 'text-muted-foreground' : 'bg-sidebar text-foreground hover:bg-(--row-hover)')}
                    onRowClick={open}
                    empty={<span className="text-sm">{narrowed ? 'В этом списке пусто' : 'Уведомлений пока нет'}</span>}
                    mobileRow={(item) => (
                        <MobileNote
                            key={item.id}
                            item={item}
                            selected={selected.includes(item.id)}
                            onSelect={(on) => toggle(item.id, on)}
                            onOpen={() => open(item)}
                        />
                    )}
                    footer={
                        <>
                            {/* A phone keeps the paging and leaves the page
                                    size to the default: there is no room for
                                    both, and the size is the setting of the two
                                    that nobody changes on a telephone. */}
                            <div className="text-muted-foreground flex items-center gap-2 text-sm max-md:hidden">
                                Строк на странице
                                <DropdownMenu>
                                    <DropdownMenuTrigger asChild>
                                        <Button variant="outline" size="sm" className="h-8 gap-1 px-2.5 tabular-nums">
                                            {perPage}
                                            <ChevronDown className="text-muted-foreground" />
                                        </Button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent align="start" className="min-w-20">
                                        <DropdownMenuRadioGroup value={String(perPage)} onValueChange={(value) => visit({ perPage: Number(value) })}>
                                            {perPageOptions.map((option) => (
                                                <DropdownMenuRadioItem key={option} value={String(option)} className="tabular-nums">
                                                    {option}
                                                </DropdownMenuRadioItem>
                                            ))}
                                        </DropdownMenuRadioGroup>
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            </div>

                            <Pagination paginator={notes} className="min-w-0 flex-1" />
                        </>
                    }
                />
            </PageContent>
        </AppLayout>
    );
}

/**
 * One note on a phone, where four columns do not fit 390 points.
 *
 * The heading, the sentence under it, and the name and the day on a line of
 * their own: nothing is dropped, and the row is still the row. The sentence is
 * given a line rather than cut to three words after the heading — it is the half
 * of the row that says what the note is about — and the day keeps its line
 * whatever the name does, a date broken over two lines being read twice.
 *
 * Read or not is said the same way as in the table: the row stands on the head's
 * ground in the foreground colour while the note is waiting, and falls back to
 * the card's ground and the muted colour once it has been dealt with.
 *
 * The box is here too. It is the only way a phone has of marking several notes
 * read or throwing them away, and the two actions it brings out come down onto a
 * line of their own above the list.
 */
function MobileNote({
    item,
    selected,
    onSelect,
    onOpen,
}: {
    item: NotificationItem;
    selected: boolean;
    onSelect: (on: boolean) => void;
    onOpen: () => void;
}) {
    return (
        <li className={cn('flex items-center', item.read ? 'text-muted-foreground' : 'bg-sidebar text-foreground')}>
            <span className="shrink-0 pl-4">
                <Checkbox checked={selected} onCheckedChange={(on) => onSelect(on === true)} aria-label={`Выбрать: ${item.title}`} />
            </span>

            <button
                type="button"
                onClick={onOpen}
                className="active:bg-accent/60 flex min-h-14 min-w-0 flex-1 flex-col items-start gap-0.5 py-2.5 pr-4 pl-3 text-left"
            >
                <span className={cn('w-full truncate text-[15px] leading-tight', item.read ? 'font-medium' : 'font-semibold')}>{item.title}</span>
                {!item.read && <span className="sr-only">Не прочитано</span>}

                <span className={cn('text-muted-foreground w-full truncate text-[13px] leading-tight', item.read ? 'font-normal' : 'font-medium')}>
                    {item.body}
                </span>

                <span className="flex w-full min-w-0 items-center gap-1.5 text-[13px] leading-tight">
                    <span className={cn('truncate', item.from ? (item.read ? 'font-medium' : 'font-semibold') : 'text-muted-foreground')}>
                        {item.from?.name ?? '—'}
                    </span>
                    <span aria-hidden="true" className="shrink-0">
                        ·
                    </span>
                    <span className={cn('shrink-0 whitespace-nowrap tabular-nums', !item.read && 'font-semibold')}>{formatDate(item.at)}</span>
                </span>
            </button>
        </li>
    );
}
