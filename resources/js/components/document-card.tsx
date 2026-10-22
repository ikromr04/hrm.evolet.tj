import { EditPencil } from '@/components/edit-pencil';
import InputError from '@/components/input-error';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';
import { BODY, MEASURE, type Addresses, type DocumentSection, type Editing, type Paper } from '@/lib/document';
import { cn } from '@/lib/utils';
import { router } from '@inertiajs/react';
import { LoaderCircle, Undo2 } from 'lucide-react';
import { Suspense, lazy, useState, type ReactNode } from 'react';

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
 *
 * A document that is one text says none of this. It has no row of headings to
 * carry a badge, and the company asked for that tab to hold the document and
 * the pencil and nothing else — so whether the words are this owner's own is
 * read in the words themselves.
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
 *
 * `wide` lets the text fill the card instead of stopping at the measure of a
 * printed page. «Профиль должности» keeps the measure: its sections are a few
 * lines each, and a line of ninety words is one nobody finishes. «Положение о
 * работе» is read across the card — the company looked at it full width and
 * asked for the width, and nothing else shares the row with it there.
 *
 * `corner` keeps the top right of the first block clear. A document that is one
 * text opens with its own heading and carries the pencil in the corner of the
 * card over it, and «ПОЛОЖЕНИЕ О РАБОТЕ ДЕПАРТАМЕНТ АНАЛИТИКИ И СТАТИСТИКИ»
 * would otherwise run under it — so that heading, and only it, is given the
 * width of the button to wrap short of. Asked for only where the button is
 * drawn, so a reader who cannot edit reads the heading across the whole line.
 */
function Body({
    html,
    empty,
    wide = false,
    corner = false,
    className,
}: {
    html: string | null;
    empty: string;
    wide?: boolean;
    corner?: boolean;
    className?: string;
}) {
    if (html === null || html.trim() === '') return <p className="text-muted-foreground text-sm">{empty}</p>;

    return (
        <div
            className={cn(BODY, !wide && MEASURE, corner && '[&>:first-child]:pr-12 md:[&>:first-child]:pr-10', className)}
            dangerouslySetInnerHTML={{ __html: html }}
        />
    );
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
    whole,
    onClose,
}: {
    editing: Editing;
    owner: number;
    addresses: Addresses;
    whose: string | null;
    whole: Whole;
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
                    {/* A document of one text is named after itself: there is no
                        section to say which one is being written, and «Раздел
                        01» of a document with one would be a number nobody
                        asked about. */}
                    <DialogTitle>{whole === null ? `Раздел ${section.number} ${section.title}` : whole.title}</DialogTitle>
                    {/* Said only where something happens that nobody could
                        guess: rewriting a standard section leaves this document
                        with a text of its own in that place. */}
                    {editing.mode === 'standard' && (
                        <DialogDescription>
                            Стандартный текст станет собственным текстом {whose === null ? 'этого документа' : `«${whose}»`}.
                        </DialogDescription>
                    )}
                </DialogHeader>

                <Suspense fallback={<FormWaiting whole={whole !== null} />}>
                    <SectionForm editing={editing} owner={owner} addresses={addresses} whole={whole !== null} onClose={onClose} />
                </Suspense>
            </DialogContent>
        </Dialog>
    );
}

/**
 * The shape of the form while it is on its way: the fields and the two buttons,
 * at the size they will be, so that the dialog is the size it will stay and
 * nothing under the cursor moves when the real thing arrives.
 *
 * Which fields, because a document that is one text is written with no heading
 * of its own to type — and a box drawn here that the form then does not have is
 * exactly the jump this is for avoiding.
 */
function FormWaiting({ whole }: { whole: boolean }) {
    return (
        <div className="flex flex-col gap-5" aria-hidden="true">
            {!whole && (
                <div className="grid gap-2">
                    <Skeleton className="h-4 w-20" />
                    <Skeleton className="h-9 w-full max-md:h-11" />
                </div>
            )}
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
 * That this document is one text rather than a list of sections, and the words
 * it needs said about itself where the card cannot name it for itself — and
 * nothing at all for a document of sections.
 *
 * «Положение о работе» is written as one file, so nothing of ours is drawn
 * around its body: no heading, no number, no badge, no note about whose wording
 * it is. The tab holds the document and one pencil in the corner of the card.
 * «Профиль должности» is written the same way for the same reason, so it asks
 * for this too; a document of sections, which neither of them is any more,
 * leaves it alone.
 */
type Whole = {
    /** What to call the text where the dialog that writes it is headed. */
    title: string;
    /** What the pencil is called for somebody who cannot see which text it belongs to. */
    edit: string;
} | null;

/**
 * Putting the standard wording back in a section this owner rewrote.
 *
 * It is a delete where the server is concerned — the text of its own is what
 * goes — but nothing is lost from the document: the section stays where it is
 * and reads the shared words again. So the confirmation says that, and not a
 * word about deleting anything.
 *
 * A document of sections only. «Положение о работе» is one text and offers no
 * way back to the standard one — the tab is the document and the pencil — so
 * nothing there opens this.
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
 * Drawn for a document whose heading is kept apart from its text, which is
 * «Профиль должности». The default the server hands over names its owner in the
 * nominative — «ПРОФИЛЬ ДОЛЖНОСТИ АНАЛИТИК» — because nothing in this
 * application guesses a Russian case. That is precisely why the line can be
 * typed over: the ending is a person's to fix, once, and it stays fixed.
 *
 * A document that is one file has no such line. It names itself in its own
 * first line, which is written in the editor with the rest of it.
 *
 * The title is drawn exactly as it arrives. The capitals are the owner's own,
 * not a stylesheet's, so that what somebody typed and what they read back are
 * the same string.
 */
function DocumentTitle({ title, owner, address, canEdit }: { title: string; owner: number; address: string; canEdit: boolean }) {
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
            route(address, owner),
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
                {canEdit && <EditPencil label="Изменить заголовок документа" tip="Изменить заголовок" onClick={open} />}
            </div>
        );
    }

    return (
        // The whole row while it is being typed, so that a refusal has somewhere
        // to be read without pushing anything beside it out of line.
        <form
            noValidate
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

/**
 * The chrome a page draws around a document that is one text, where the design
 * asks for more than the text and a pencil.
 *
 * Three places, because the design has three: a band over the document, a
 * column of contents at the left of it, and a block beside its first line — the
 * one «УТВЕРЖДАЮ» stands in. The card knows where each goes and nothing of what
 * is in it; the page knows what is in it and nothing of where it goes.
 *
 * The band is handed the pencil rather than drawing one of its own: writing the
 * document is the card's to do, and a second button would be a second way to do
 * it. None of this reaches a phone — there the document is a screen of its own
 * and the pencil stays in the corner of the card — so a page that draws a frame
 * draws it for a desktop and leaves the phone as it was.
 */
export interface DocumentFrame {
    /** Drawn over the document, and handed the pencil to put where the design has it. */
    header?: (pencil: ReactNode | null) => ReactNode;
    /** A column at the left of the text on a wide screen. */
    beside?: ReactNode;
    /** A block at the right of the document's own first line. */
    corner?: ReactNode;
}

/**
 * A document of fixed sections as its owner reads it: a heading it may correct
 * where the heading is kept apart from the text, and the blocks the document is
 * made of, each opening onto what it says.
 *
 * Everything written here is written for this one owner and reaches nobody else.
 * A standard section edited for the first time becomes a text of its own in that
 * place. A document of sections says so where that section is read, with the
 * way back to the standard text beside it; a document that is one text says
 * nothing of the kind, because the company asked for that tab to hold the
 * document and the pencil and nothing else — so there is no way back to the
 * shared wording from the page once it is rewritten, which is what was asked
 * for.
 *
 * Whoever may not edit sees the document and nothing else: no buttons, no
 * handles, nothing to press.
 *
 * Two pages draw this: a department's «Положение о работе» and a position's
 * «Профиль должности». They differ in their addresses, in what to call their
 * owner, in what an empty document means, in whether some section is one the
 * application fills in by itself, and in whether the document is a list of
 * sections at all or one text that names itself — so those five are asked for,
 * and the rest is the same document twice.
 */
export function DocumentCard({
    owner,
    addresses,
    paper,
    whose = null,
    whole = null,
    empty,
    fixed,
    frame,
}: {
    /** The department or the position the document belongs to. */
    owner: number;
    addresses: Addresses;
    paper: Paper;
    /** What to call the owner in the sentences that name it, where there is a name worth saying. */
    whose?: string | null;
    /** That this document is one text, and the words it needs said about itself — see {@link Whole}. */
    whole?: Whole;
    /** What a document with no sections at all means here. */
    empty: ReactNode;
    /** A section the application writes itself, drawn by the page that knows how. */
    fixed?: (section: DocumentSection) => ReactNode | null;
    /** What the page draws around a document of one text — see {@link DocumentFrame}. */
    frame?: DocumentFrame;
}) {
    const { can_edit, title, sections } = paper;

    const [editing, setEditing] = useState<Editing | null>(null);
    const [restoring, setRestoring] = useState<Restoring>(null);

    // A document that is one text is one section, and the pencil of the band
    // above writes it. Built here, beside the dialog it opens, so that the page
    // drawing the band says only where the button stands.
    const written = whole === null ? null : (sections.find((section) => !section.computed) ?? null);
    const pencil =
        whole !== null && can_edit && written !== null ? (
            <EditPencil
                label={whole.edit}
                onClick={() => setEditing({ mode: written.origin === 'template' ? 'standard' : 'theirs', section: written })}
            />
        ) : null;

    return (
        // Relative so that the pencil of a one-text document hangs in the corner
        // of this card and not of the page: it is placed at the padding, so it
        // sits exactly where the content's own top right corner is, border or no
        // border.
        <section className="relative flex flex-col gap-4 px-4 py-4 md:px-6 md:py-5">
            {/* A document whose heading is kept apart from its text is headed
                here; one that is a single file is not headed at all, because
                its own first line is the heading and the tab holds the document
                and nothing else. */}
            {title !== undefined && addresses.title !== undefined && (
                <header className="flex flex-wrap items-start gap-x-3 gap-y-2">
                    <DocumentTitle title={title} owner={owner} address={addresses.title} canEdit={can_edit} />
                </header>
            )}

            {frame?.header?.(pencil)}

            {sections.length === 0 ? (
                // Every owner is seeded with its sections, so an empty document
                // is not one waiting to be written — it is one that failed to
                // arrive, and the page says which document it was.
                <p className="text-muted-foreground max-w-[60ch] py-2 text-sm">{empty}</p>
            ) : (
                <div className={cn(frame?.beside !== undefined && 'lg:grid lg:grid-cols-[13rem_minmax(0,1fr)] lg:items-start lg:gap-6')}>
                    {frame?.beside !== undefined && <div className="max-lg:hidden">{frame.beside}</div>}

                    <div className="relative min-w-0">
                        {/* Beside the document's first line, which is its own
                            heading: the text keeps the width of the card for the
                            rest of the page, so the block hangs over its corner
                            rather than taking a column away from it. */}
                        {frame?.corner !== undefined && <div className="absolute top-0 right-0 w-[24rem] max-lg:hidden">{frame.corner}</div>}

                        <ul className="flex flex-col">
                            {sections.map((section) => (
                                // A document, read down: every section open, one after
                                // the other, the way it comes off a printer. The rule
                                // above each heading and the room under each text are
                                // what divide them now that nothing folds shut.
                                <li key={section.id} className="border-t pt-4 pb-6 first:border-t-0 first:pt-0 last:pb-0">
                                    {/* A document that is one text has nothing of ours
                                above it: its own first heading is the first line
                                of it, and the pencil that writes it stands in the
                                corner of the card, out of the way of the words. */}
                                    {whole === null && (
                                        <div className="flex items-start gap-2">
                                            {/* A heading, not a button: there is nothing
                                        left for it to open. The number keeps a
                                        column of its own so the titles line up
                                        down the page. */}
                                            <h3 className="flex min-w-0 flex-1 flex-wrap items-baseline gap-x-2 gap-y-1 py-1 text-sm font-semibold">
                                                <span className="text-muted-foreground shrink-0 tabular-nums">{section.number}</span>
                                                <span className="break-words">{section.title}</span>
                                                <Badge origin={section.origin} />
                                            </h3>

                                            {/* A section the application writes itself has
                                        nothing to type into it, so it carries no
                                        pencil — the way to change what it says is
                                        to change what it is drawn from. */}
                                            {can_edit && !section.computed && (
                                                <EditPencil
                                                    label={`Изменить раздел ${section.number} ${section.title}`}
                                                    onClick={() =>
                                                        setEditing({ mode: section.origin === 'template' ? 'standard' : 'theirs', section })
                                                    }
                                                />
                                            )}
                                        </div>
                                    )}

                                    {/* The text under its own heading rather than under
                                the number, which is the column the headings are
                                read down — and under nothing at all where the
                                document is one text. */}
                                    <div className={cn('flex flex-col gap-4', whole === null && 'mt-2 md:pl-[1.75rem]')}>
                                        {/* A section written here says so where it is
                                    read, so that nobody wonders why it differs
                                    from the sheet next door — and the way back to
                                    the shared wording is beside it.

                                    A document that is one text says neither: the
                                    tab is the document and the pencil, and the
                                    company asked for nothing else on it. */}
                                        {whole === null && section.origin === 'override' && (
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
                                        {fixed?.(section) ?? (
                                            <Body
                                                html={section.body}
                                                empty={whole === null ? 'Раздел пока пуст.' : 'Текст пока не написан.'}
                                                wide={whole !== null}
                                                corner={whole !== null && can_edit && frame?.header === undefined}
                                                className={cn(
                                                    // A framed document is read as a paper:
                                                    // every part of it opens under a rule of
                                                    // its own, and the first line — the name
                                                    // of the paper — keeps clear of whatever
                                                    // the page hangs in its corner.
                                                    frame !== undefined && '[&_h2:not(:first-child)]:border-b [&_h2:not(:first-child)]:pb-2',
                                                    frame?.corner !== undefined &&
                                                        'lg:[&>:first-child]:min-h-[7.5rem] lg:[&>:first-child]:pr-[25.5rem]',
                                                )}
                                            />
                                        )}
                                    </div>

                                    {/* Last of the card in the order a keyboard walks it,
                                and first in the corner of it: whoever tabs
                                through the page reads the document and then finds
                                the way to write it, rather than meeting a lone
                                «Изменить» before the text it belongs to. */}
                                    {whole !== null && can_edit && !section.computed && (
                                        <EditPencil
                                            label={whole.edit}
                                            className={cn(
                                                'absolute top-4 right-4',
                                                frame?.header === undefined ? 'md:top-5 md:right-6' : 'md:hidden',
                                            )}
                                            onClick={() => setEditing({ mode: section.origin === 'template' ? 'standard' : 'theirs', section })}
                                        />
                                    )}
                                </li>
                            ))}
                        </ul>
                    </div>
                </div>
            )}

            {editing && (
                <SectionDialog editing={editing} owner={owner} addresses={addresses} whose={whose} whole={whole} onClose={() => setEditing(null)} />
            )}

            <RestoreDialog restoring={restoring} owner={owner} addresses={addresses} whose={whose} onClose={() => setRestoring(null)} />
        </section>
    );
}
