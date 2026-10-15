import InputError from '@/components/input-error';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { BODY, MEASURE, type Addresses, type DocumentSection, type Editing, type Paper } from '@/lib/document';
import { cn } from '@/lib/utils';
import { router } from '@inertiajs/react';
import { ChevronDown, GripVertical, LoaderCircle, Pencil, Undo2 } from 'lucide-react';
import { Suspense, lazy, useMemo, useRef, useState, type DragEvent, type KeyboardEvent as ReactKeyboardEvent, type ReactNode } from 'react';

/**
 * The two fields a section is written in, and the editor one of them needs.
 *
 * Fetched when somebody opens a section to write it, and never otherwise: the
 * page is read by everybody in the company and written by whoever keeps the
 * structure, and a rich-text editor is a great deal of code to hand to a reader
 * who has no button that would open it.
 */
const SectionForm = lazy(() => import('@/components/document-section-form'));

/**
 * What a block says of itself before it is opened: that its text was written
 * here rather than being the standard one.
 *
 * A section its owner wrote itself says nothing, because that is what
 * every section of every document is — a badge on all of them would mark no
 * difference at all. The standard text is the one thing worth pointing out, and
 * nothing seeds one today, so this is quiet until something does.
 */
function Badge({ origin }: { origin: DocumentSection['origin'] }) {
    return origin === 'override' ? <StatusBadge tone="warning">Свой текст</StatusBadge> : null;
}

/**
 * A section as it is read.
 *
 * Only `body` is ever put on the page: the server answers with it sanitized and
 * the placeholders already resolved, and it is the one text here that has been
 * through that. What a client typed goes to the server and comes back as this
 * or not at all.
 */
function Body({ html }: { html: string | null }) {
    if (html === null || html.trim() === '') return <p className="text-muted-foreground text-sm">Раздел пока пуст.</p>;

    return <div className={cn(BODY, MEASURE)} dangerouslySetInnerHTML={{ __html: html }} />;
}

/**
 * Which block of the document a section is, rather than which row it is written
 * in: the owner's own wording of a standard section is read in that
 * section's place, so writing one — or putting the standard text back — swaps
 * the row underneath a block that was left open, and the block stays open.
 */
function blockOf(section: DocumentSection): number {
    return section.template_section_id ?? section.id;
}

/** '01', '02', … — how the document numbers its sections. */
function numberAt(index: number): string {
    return String(index + 1).padStart(2, '0');
}

/**
 * Writing one section of the document: its heading, and what it says.
 *
 * The dialog opens at once, named after the section it is about, and the two
 * fields arrive into it — the editor behind them is fetched the first time
 * anybody writes anything, and a dialog that opened empty while it came would
 * read as a page loading rather than as a dialog.
 */
function SectionDialog({
    editing,
    owner,
    addresses,
    whose,
    onClose,
}: {
    editing: Editing;
    owner: number;
    addresses: Addresses;
    whose: string | null;
    onClose: () => void;
}) {
    const section = editing.section;

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            {/* Wide enough for the whole toolbar to stand in one row on a
                desktop — it measures 756px, and 896 less the dialog's own
                padding leaves room to spare without reaching for a width that
                would only make the title field longer than anyone reads. The
                text inside keeps its own measure regardless. */}
            <DialogContent className="max-h-[90svh] overflow-y-auto sm:max-w-4xl">
                <DialogHeader>
                    <DialogTitle>{`Раздел ${section.number} ${section.title}`}</DialogTitle>
                    {/* Said only where something happens that nobody could
                        guess: rewriting a standard section leaves this document
                        with a text of its own in that place. */}
                    {editing.mode === 'standard' && (
                        <DialogDescription>
                            Стандартный текст станет собственным текстом {whose === null ? 'этого документа' : `«${whose}»`}.
                        </DialogDescription>
                    )}
                </DialogHeader>

                <Suspense fallback={<FormWaiting />}>
                    <SectionForm editing={editing} owner={owner} addresses={addresses} onClose={onClose} />
                </Suspense>
            </DialogContent>
        </Dialog>
    );
}

/**
 * The shape of the form while it is on its way: the two fields and the two
 * buttons, at the size they will be, so that the dialog is the size it will
 * stay and nothing under the cursor moves when the real thing arrives.
 */
function FormWaiting() {
    return (
        <div className="flex flex-col gap-5" aria-hidden="true">
            <div className="grid gap-2">
                <Skeleton className="h-4 w-20" />
                <Skeleton className="h-9 w-full max-md:h-11" />
            </div>
            <div className="grid gap-2">
                <Skeleton className="h-4 w-24" />
                <div className="border-input overflow-hidden rounded-md border">
                    <div className="border-border/70 flex gap-1 border-b p-1">
                        {Array.from({ length: 6 }, (_, at) => (
                            <Skeleton key={at} className="size-9 max-md:size-11" />
                        ))}
                    </div>
                    <div className="min-h-40 p-3">
                        <Skeleton className="h-4 w-4/5" />
                    </div>
                </div>
            </div>
            <div className="flex justify-end gap-2">
                <Skeleton className="h-9 w-24 max-md:h-11" />
                <Skeleton className="h-9 w-28 max-md:h-11" />
            </div>
        </div>
    );
}

/** The section whose own text is about to be given up for the standard one. */
type Restoring = { section: DocumentSection } | null;

/**
 * Putting the standard wording back in a section this owner rewrote.
 *
 * It is a delete where the server is concerned — the text of its own is what
 * goes — but nothing is lost from the document: the section stays where it is
 * and reads the shared words again. So the confirmation says that, and not a
 * word about deleting anything.
 */
function RestoreDialog({
    restoring,
    owner,
    addresses,
    whose,
    onClose,
}: {
    restoring: Restoring;
    owner: number;
    addresses: Addresses;
    whose: string | null;
    onClose: () => void;
}) {
    const [processing, setProcessing] = useState(false);
    const section = restoring?.section ?? null;

    const confirm = () => {
        if (!section) return;

        router.delete(route(addresses.destroy, [owner, section.id]), {
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
            onSuccess: onClose,
        });
    };

    return (
        <Dialog open={section !== null} onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle className="break-words">
                        Вернуть стандартный текст: {section?.number} {section?.title}?
                    </DialogTitle>
                    <DialogDescription asChild>
                        <div className="flex flex-col gap-1.5">
                            <p>
                                {whose === null
                                    ? 'Написанный здесь текст будет удалён, и в разделе снова встанет стандартный.'
                                    : `Текст, написанный для «${whose}», будет удалён, и в разделе снова встанет стандартный.`}
                            </p>
                            <p>Отменить нельзя.</p>
                        </div>
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter className="gap-2">
                    <Button type="button" variant="outline" className="max-md:h-11" onClick={onClose}>
                        Отмена
                    </Button>
                    <Button type="button" variant="destructive" className="max-md:h-11" onClick={confirm} disabled={processing}>
                        {processing && <LoaderCircle className="animate-spin" />}
                        Вернуть стандартный
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

/**
 * What the document calls itself, and the way to correct it.
 *
 * The default the server hands over names its owner in the nominative —
 * «ПОЛОЖЕНИЕ О РАБОТЕ ДЕПАРТАМЕНТ АНАЛИТИКИ И СТАТИСТИКИ» — because nothing in
 * this application guesses a Russian case. That is precisely why the line can be
 * typed over: the ending is a person's to fix, once, and it stays fixed.
 *
 * The title is drawn exactly as it arrives. The capitals are the owner's own,
 * not a stylesheet's, so that what somebody typed and what they read back are
 * the same string.
 */
function DocumentTitle({ title, owner, addresses, canEdit }: { title: string; owner: number; addresses: Addresses; canEdit: boolean }) {
    const [writing, setWriting] = useState(false);
    const [draft, setDraft] = useState(title);
    const [processing, setProcessing] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const open = () => {
        setDraft(title);
        setError(null);
        setWriting(true);
    };

    const save = () => {
        router.put(
            route(addresses.title, owner),
            { title: draft.trim() },
            {
                preserveScroll: true,
                onStart: () => {
                    setProcessing(true);
                    setError(null);
                },
                onFinish: () => setProcessing(false),
                onSuccess: () => setWriting(false),
                onError: (errors) => setError(errors.title ?? 'Не удалось сохранить название.'),
            },
        );
    };

    if (!writing) {
        return (
            <div className="flex min-w-0 flex-1 basis-[18rem] items-start gap-1">
                <h2 className="min-w-0 flex-1 py-1 text-[17px] leading-snug font-semibold break-words">{title}</h2>
                {canEdit && (
                    <TooltipProvider delayDuration={400}>
                        <Tooltip>
                            <TooltipTrigger asChild>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    className="text-muted-foreground size-9 shrink-0 max-md:size-11"
                                    aria-label="Изменить заголовок документа"
                                    onClick={open}
                                >
                                    <Pencil className="size-4" />
                                </Button>
                            </TooltipTrigger>
                            <TooltipContent className="px-2 py-1 text-xs font-normal">Изменить заголовок</TooltipContent>
                        </Tooltip>
                    </TooltipProvider>
                )}
            </div>
        );
    }

    return (
        // The whole row while it is being typed, so that a refusal has somewhere
        // to be read without pushing anything beside it out of line.
        <form
            className="flex w-full flex-col gap-2"
            onSubmit={(event) => {
                event.preventDefault();
                save();
            }}
        >
            <div className="flex flex-wrap items-start gap-2">
                <Input
                    className="min-w-0 flex-1 basis-[18rem] font-semibold max-md:h-11"
                    autoFocus
                    value={draft}
                    aria-label="Заголовок документа"
                    aria-invalid={!!error}
                    disabled={processing}
                    onChange={(event) => setDraft(event.target.value)}
                    onKeyDown={(event) => event.key === 'Escape' && setWriting(false)}
                />
                <div className="flex shrink-0 gap-2">
                    <Button type="button" variant="outline" className="max-md:h-11" onClick={() => setWriting(false)}>
                        Отмена
                    </Button>
                    <Button type="submit" className="max-md:h-11" disabled={processing}>
                        {processing && <LoaderCircle className="animate-spin" />}
                        Сохранить
                    </Button>
                </div>
            </div>
            <p className="text-muted-foreground text-[13px]">Пустое поле вернёт заголовок по умолчанию.</p>
            <InputError message={error ?? undefined} />
        </form>
    );
}

/** Which section a drag is over, and which of its edges the dragged one lands on. */
type DropAt = { id: number; place: 'before' | 'after' } | null;

/**
 * A document of fixed sections as its owner reads it: a heading it may correct,
 * and a list of blocks, each opening onto what the section says.
 *
 * Everything written here is written for this one owner and reaches nobody else.
 * A standard section edited for the first time becomes a text of its own in that
 * place, which the block says so that nobody wonders why it reads differently
 * from the sheet next door — and the way back to the standard text is beside it.
 *
 * Whoever may not edit sees the document and nothing else: no buttons, no
 * handles, nothing to press.
 *
 * Two pages draw this: a department's «Положение о работе» and a position's
 * «Профиль должности». They differ in their addresses, in what to call their
 * owner, in what an empty document means, and in whether some section is one the
 * application fills in by itself — so those four are asked for, and the rest is
 * the same document twice.
 */
export function DocumentCard({
    owner,
    addresses,
    paper,
    whose = null,
    empty,
    fixed,
}: {
    /** The department or the position the document belongs to. */
    owner: number;
    addresses: Addresses;
    paper: Paper;
    /** What to call the owner in the sentences that name it, where there is a name worth saying. */
    whose?: string | null;
    /** What a document with no sections at all means here. */
    empty: ReactNode;
    /** A section the application writes itself, drawn by the page that knows how. */
    fixed?: (section: DocumentSection) => ReactNode | null;
}) {
    const { can_edit, title, sections } = paper;

    // Everything closed: the card is then the document's contents, and what to
    // read is chosen from it rather than scrolled past.
    const [opened, setOpened] = useState<number[]>([]);
    const [editing, setEditing] = useState<Editing | null>(null);
    const [restoring, setRestoring] = useState<Restoring>(null);
    const [orderError, setOrderError] = useState<string | null>(null);

    /**
     * The order the document is drawn in while the server's is not the one to
     * draw: a section being carried by the keyboard, or a move already sent and
     * not yet answered.
     */
    const [pending, setPending] = useState<DocumentSection[] | null>(null);
    const [dragged, setDragged] = useState<number | null>(null);
    const [dropAt, setDropAt] = useState<DropAt>(null);
    /** The section the keyboard has picked up and is carrying, if any. */
    const [held, setHeld] = useState<number | null>(null);

    /** The header rows, so that a drag can carry the one it started from. */
    const headers = useRef(new Map<number, HTMLDivElement>());

    /**
     * The document as it is drawn. Normally that is the order and the numbers
     * the server sent; while a move is in flight it is the order asked for, with
     * the numbers read off the new positions — the document's own rule, applied
     * here for the moment it takes to answer so that a section does not sit in
     * its new place under its old number.
     */
    const list = useMemo(
        () => (pending === null ? sections : pending.map((section, index) => ({ ...section, number: numberAt(index) }))),
        [pending, sections],
    );

    const toggle = (block: number) => setOpened((open) => (open.includes(block) ? open.filter((other) => other !== block) : [...open, block]));

    /**
     * The document in a new order. The whole list of ids goes up, because what
     * the server writes down is the order the document is read in rather than
     * the one section that moved.
     */
    const reorder = (next: DocumentSection[]) => {
        setOrderError(null);
        setPending(next);

        router.put(
            route(addresses.order, owner),
            { ids: next.map((section) => section.id) },
            {
                preserveScroll: true,
                // Either way the server's answer is the one on screen: on
                // success it arrives renumbered, and on a refusal the document
                // goes back to the order it was in and says why.
                onSuccess: () => setPending(null),
                onError: (errors) => {
                    setPending(null);
                    setOrderError(errors.ids ?? 'Не удалось переставить разделы.');
                },
            },
        );
    };

    /**
     * A section carried a step up or down. Nothing is sent yet: the keyboard
     * carries a section past as many of its neighbours as it likes, and the
     * document is written down once, where it is put back.
     */
    const carry = (index: number, to: number) => {
        if (to < 0 || to >= list.length) return;

        const next = [...list];
        [next[index], next[to]] = [next[to], next[index]];

        setPending(next);
    };

    /**
     * A section picked up, carried and put down with the keyboard, which is the
     * whole of reordering for anybody not holding a mouse: dragging is a mouse
     * gesture and the browser offers no other way to begin one.
     *
     * Escape puts the document back exactly as it was, so that a section picked
     * up by mistake costs nothing.
     */
    const keyboardMove = (event: ReactKeyboardEvent, section: DocumentSection, index: number) => {
        if (event.key === ' ' || event.key === 'Enter') {
            event.preventDefault();

            if (held !== section.id) setHeld(section.id);
            else {
                setHeld(null);
                if (pending !== null) reorder(pending);
            }

            return;
        }

        if (held !== section.id) return;

        if (event.key === 'Escape') {
            event.preventDefault();
            setHeld(null);
            setPending(null);

            return;
        }

        if (event.key === 'ArrowUp' || event.key === 'ArrowDown') {
            event.preventDefault();
            carry(index, index + (event.key === 'ArrowUp' ? -1 : 1));
        }
    };

    /** A section still in hand when the focus leaves is put down where it stands. */
    const release = (section: DocumentSection) => {
        if (held !== section.id) return;

        setHeld(null);
        if (pending !== null) reorder(pending);
    };

    const clearDrag = () => {
        setDragged(null);
        setDropAt(null);
    };

    const dragOver = (event: DragEvent<HTMLLIElement>, section: DocumentSection) => {
        if (dragged === null) return;

        // Over itself there is nothing on offer, and the cursor keeps its
        // crossed circle because the drop is not taken.
        if (section.id === dragged) {
            setDropAt(null);

            return;
        }

        // Taking the drop is also what turns the cursor from a crossed circle
        // into a move arrow.
        event.preventDefault();
        event.dataTransfer.dropEffect = 'move';

        // The nearer half of the block says which of its edges the section
        // lands on.
        const box = event.currentTarget.getBoundingClientRect();
        const place = event.clientY < box.top + box.height / 2 ? 'before' : 'after';

        setDropAt((current) => (current?.id === section.id && current.place === place ? current : { id: section.id, place }));
    };

    /** The dragged section lifted out of the list and put back at the edge it was dropped on. */
    const drop = (at: { id: number; place: 'before' | 'after' }) => {
        const moving = list.find((section) => section.id === dragged);
        const target = list.find((section) => section.id === at.id);

        clearDrag();

        if (!moving || !target || moving === target) return;

        const next = list.filter((section) => section !== moving);
        next.splice(next.indexOf(target) + (at.place === 'after' ? 1 : 0), 0, moving);

        reorder(next);
    };

    /**
     * The line along the edge the section would land on, drawn inside the block
     * rather than as its border.
     *
     * The blocks are separated by borders of their own and the first has none,
     * so a border here would be the one the first block is told not to draw —
     * and the top of the list, where a section is most often dropped, would be
     * the one place with no sign of where it was going.
     */
    const dropLine = (section: DocumentSection) =>
        dropAt?.id === section.id &&
        (dropAt.place === 'before' ? 'shadow-[inset_0_2px_0_0_var(--color-ring)]' : 'shadow-[inset_0_-2px_0_0_var(--color-ring)]');

    return (
        <section className="flex flex-col gap-4 px-4 py-4 md:px-6 md:py-5">
            <header className="flex flex-wrap items-start justify-between gap-x-3 gap-y-2">
                <DocumentTitle title={title} owner={owner} addresses={addresses} canEdit={can_edit} />

                {list.length > 0 && (
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        className="shrink-0 max-md:h-10"
                        onClick={() => setOpened(opened.length === list.length ? [] : list.map(blockOf))}
                    >
                        {opened.length === list.length ? 'Свернуть все' : 'Развернуть все'}
                    </Button>
                )}
            </header>

            {list.length === 0 ? (
                // Every owner is seeded with its sections, so an empty document
                // is not one waiting to be written — it is one that failed to
                // arrive, and the page says which document it was.
                <p className="text-muted-foreground max-w-[60ch] py-2 text-sm">{empty}</p>
            ) : (
                <ul className="flex flex-col">
                    {list.map((section, index) => {
                        const open = opened.includes(blockOf(section));

                        return (
                            <li
                                key={section.id}
                                onDragOver={can_edit ? (event) => dragOver(event, section) : undefined}
                                onDrop={
                                    can_edit
                                        ? (event) => {
                                              event.preventDefault();
                                              if (dropAt?.id === section.id) drop(dropAt);
                                              else clearDrag();
                                          }
                                        : undefined
                                }
                                className={cn(
                                    'border-t first:border-t-0',
                                    // The one being dragged reads as already
                                    // gone from where it stands.
                                    dragged === section.id && 'opacity-40',
                                    // The one in hand reads as lifted off the
                                    // page, because nothing else on screen says
                                    // that the arrow keys are moving it.
                                    held === section.id && 'bg-accent/40 ring-ring rounded-md ring-2',
                                    dropLine(section),
                                )}
                            >
                                <Collapsible open={open} onOpenChange={() => toggle(blockOf(section))}>
                                    <div ref={(row) => void (row ? headers.current.set(section.id, row) : headers.current.delete(section.id))}>
                                        <div className="flex items-start gap-1">
                                            {/* A grip of its own, rather than the whole
                                                header: the header beside it is the button
                                                that opens the block, and a press that
                                                might turn into a drag would make opening
                                                one a gamble.

                                                A drag is a mouse gesture and the browser
                                                knows no other way to begin one, so the
                                                same grip is also a button: it picks the
                                                section up, the arrow keys carry it, and
                                                it is put down again — which is the whole
                                                of reordering for anybody at a keyboard. */}
                                            {can_edit && (
                                                <button
                                                    type="button"
                                                    draggable
                                                    onDragStart={(event) => {
                                                        // Firefox begins no drag at all with an empty clipboard.
                                                        event.dataTransfer.setData('text/plain', String(section.id));
                                                        event.dataTransfer.effectAllowed = 'move';
                                                        const row = headers.current.get(section.id);
                                                        // The heading travels with the cursor, not the grip alone.
                                                        if (row) event.dataTransfer.setDragImage(row, 24, 16);
                                                        setDragged(section.id);
                                                    }}
                                                    onDragEnd={clearDrag}
                                                    onKeyDown={(event) => keyboardMove(event, section, index)}
                                                    onBlur={() => release(section)}
                                                    aria-label={
                                                        held === section.id
                                                            ? `Раздел ${section.number} ${section.title} поднят: стрелками вверх и вниз — переместить, Enter — отпустить, Esc — отменить`
                                                            : `Переместить раздел ${section.number} ${section.title}`
                                                    }
                                                    className={cn(
                                                        // Not on a phone: a drag
                                                        // is a mouse gesture and
                                                        // touch begins none, so
                                                        // there it would be a
                                                        // dead target taking the
                                                        // width a heading needs.
                                                        'text-muted-foreground/60 hover:bg-accent hover:text-foreground mt-1.5 flex size-8 shrink-0 cursor-grab items-center justify-center rounded active:cursor-grabbing max-md:hidden',
                                                        held === section.id && 'bg-accent text-foreground cursor-grabbing',
                                                    )}
                                                >
                                                    <GripVertical className="size-4" />
                                                </button>
                                            )}

                                            <CollapsibleTrigger className="hover:text-foreground/80 flex min-w-0 flex-1 items-start gap-2.5 py-3 text-left max-md:min-h-12">
                                                <ChevronDown
                                                    className={cn(
                                                        'text-muted-foreground mt-0.5 size-4 shrink-0 transition-transform',
                                                        !open && '-rotate-90',
                                                    )}
                                                    aria-hidden="true"
                                                />
                                                <span className="text-muted-foreground w-6 shrink-0 text-sm font-semibold tabular-nums">
                                                    {section.number}
                                                </span>
                                                <span className="flex min-w-0 flex-1 flex-wrap items-center gap-x-2 gap-y-1">
                                                    <span className="text-sm font-semibold break-words">{section.title}</span>
                                                    <Badge origin={section.origin} />
                                                </span>
                                            </CollapsibleTrigger>

                                            {/* A section the application writes itself has
                                                nothing to type into it, so it carries no
                                                pencil — the way to change what it says is to
                                                change what it is drawn from. */}
                                            {can_edit && !section.computed && (
                                                <div className="flex shrink-0 items-center gap-0.5 py-2">
                                                    {/* The pencil of the rest of the
                                                        application, and beside the heading
                                                        rather than inside it: the heading is
                                                        the button that opens the block, and
                                                        this one must not. The word it used
                                                        to carry is in the hint and in the
                                                        name a screen reader reads, which
                                                        says which section it would open —
                                                        six buttons called «Изменить» in a
                                                        column tell nobody anything. */}
                                                    <TooltipProvider delayDuration={400}>
                                                        <Tooltip>
                                                            <TooltipTrigger asChild>
                                                                <Button
                                                                    type="button"
                                                                    variant="ghost"
                                                                    size="icon"
                                                                    className="text-muted-foreground size-9 max-md:size-11"
                                                                    aria-label={`Изменить раздел ${section.number} ${section.title}`}
                                                                    onClick={() =>
                                                                        setEditing({
                                                                            mode: section.origin === 'template' ? 'standard' : 'theirs',
                                                                            section,
                                                                        })
                                                                    }
                                                                >
                                                                    <Pencil className="size-4" />
                                                                </Button>
                                                            </TooltipTrigger>
                                                            <TooltipContent className="px-2 py-1 text-xs font-normal">Изменить</TooltipContent>
                                                        </Tooltip>
                                                    </TooltipProvider>
                                                </div>
                                            )}
                                        </div>
                                    </div>

                                    <CollapsibleContent className="flex flex-col gap-4 pb-5 md:pl-[3.1rem]">
                                        {/* A section written here says so where it is read, so
                                            that nobody wonders why it differs from the sheet
                                            next door. */}
                                        {section.origin === 'override' && (
                                            <div className="flex flex-col items-start gap-2 rounded-lg border border-[#F5A524]/50 bg-[#FBEFD9] p-3 text-[13px] leading-relaxed text-[#9A4A06] dark:bg-[#F5A524]/10 dark:text-[#F8C471]">
                                                <p>
                                                    {whose === null
                                                        ? 'Раздел написан здесь: это собственный текст, а не стандартный.'
                                                        : `Раздел написан для «${whose}»: это его собственный текст, а не стандартный.`}
                                                </p>
                                                {can_edit && (
                                                    <Button
                                                        type="button"
                                                        variant="outline"
                                                        size="sm"
                                                        className="bg-background max-md:h-11"
                                                        onClick={() => setRestoring({ section })}
                                                    >
                                                        <Undo2 />
                                                        Вернуть стандартный текст
                                                    </Button>
                                                )}
                                            </div>
                                        )}

                                        {/* A section the application fills in is drawn by
                                            whoever knows what it is made of; everything
                                            else is the text somebody wrote. */}
                                        {fixed?.(section) ?? <Body html={section.body} />}
                                    </CollapsibleContent>
                                </Collapsible>
                            </li>
                        );
                    })}
                </ul>
            )}

            {/* A section carried by the keyboard moves without anything being
                read out, so where it has got to is said here. */}
            <p className="sr-only" aria-live="polite">
                {held !== null &&
                    `Раздел ${list.find((section) => section.id === held)?.title ?? ''}: место ${list.findIndex((section) => section.id === held) + 1} из ${list.length}`}
            </p>

            {orderError && <InputError message={orderError} />}

            {editing && <SectionDialog editing={editing} owner={owner} addresses={addresses} whose={whose} onClose={() => setEditing(null)} />}

            <RestoreDialog restoring={restoring} owner={owner} addresses={addresses} whose={whose} onClose={() => setRestoring(null)} />
        </section>
    );
}
