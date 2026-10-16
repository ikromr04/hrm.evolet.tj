import InputError from '@/components/input-error';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { BODY, MEASURE, type Addresses, type DocumentSection, type Editing, type Paper } from '@/lib/document';
import { cn } from '@/lib/utils';
import { router } from '@inertiajs/react';
import { LoaderCircle, Pencil, Undo2 } from 'lucide-react';
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

    const [editing, setEditing] = useState<Editing | null>(null);
    const [restoring, setRestoring] = useState<Restoring>(null);

    return (
        <section className="flex flex-col gap-4 px-4 py-4 md:px-6 md:py-5">
            <header className="flex flex-wrap items-start gap-x-3 gap-y-2">
                <DocumentTitle title={title} owner={owner} addresses={addresses} canEdit={can_edit} />
            </header>

            {sections.length === 0 ? (
                // Every owner is seeded with its sections, so an empty document
                // is not one waiting to be written — it is one that failed to
                // arrive, and the page says which document it was.
                <p className="text-muted-foreground max-w-[60ch] py-2 text-sm">{empty}</p>
            ) : (
                <ul className="flex flex-col">
                    {sections.map((section) => (
                        // A document, read down: every section open, one after
                        // the other, the way it comes off a printer. The rule
                        // above each heading and the room under each text are
                        // what divide them now that nothing folds shut.
                        <li key={section.id} className="border-t pt-4 pb-6 first:border-t-0 first:pt-0 last:pb-0">
                            <div className="flex items-start gap-2">
                                {/* A heading, not a button: there is nothing left
                                    for it to open. The number keeps a column of
                                    its own so the titles line up down the page. */}
                                <h3 className="flex min-w-0 flex-1 flex-wrap items-baseline gap-x-2 gap-y-1 py-1 text-sm font-semibold">
                                    <span className="text-muted-foreground shrink-0 tabular-nums">{section.number}</span>
                                    <span className="break-words">{section.title}</span>
                                    <Badge origin={section.origin} />
                                </h3>

                                {/* A section the application writes itself has
                                    nothing to type into it, so it carries no
                                    pencil — the way to change what it says is to
                                    change what it is drawn from. */}
                                {can_edit && !section.computed && (
                                    <TooltipProvider delayDuration={400}>
                                        <Tooltip>
                                            <TooltipTrigger asChild>
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="icon"
                                                    className="text-muted-foreground size-9 shrink-0 max-md:size-11"
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
                                )}
                            </div>

                            {/* The text under its own heading rather than under
                                the number, which is the column the headings are
                                read down. */}
                            <div className="mt-2 flex flex-col gap-4 md:pl-[1.75rem]">
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
                            </div>
                        </li>
                    ))}
                </ul>
            )}

            {editing && <SectionDialog editing={editing} owner={owner} addresses={addresses} whose={whose} onClose={() => setEditing(null)} />}

            <RestoreDialog restoring={restoring} owner={owner} addresses={addresses} whose={whose} onClose={() => setRestoring(null)} />
        </section>
    );
}
