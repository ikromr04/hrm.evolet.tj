import { CameraDialog, useCameraMode } from '@/components/camera-capture';
import { DocumentItem, documentState, documentStates, type DocumentState } from '@/components/document-item';
import InputError from '@/components/input-error';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useClearingForm } from '@/hooks/use-clearing-form';
import { fileSize, filesWanted } from '@/lib/documents';
import { capitalize, formatDate } from '@/lib/employee';
import { router } from '@inertiajs/react';
import { Camera, FileText, LoaderCircle, Upload, X } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';

/**
 * «Документы» on a card: the papers the company asks everybody for, and what
 * this person has handed in against them.
 *
 * Read in four groups, in the order somebody reads them for: what is settled,
 * what is waiting for the office to look at it, what was sent back, and what
 * nobody has handed in at all. A paper on file and a paper still wanted are the
 * same list — a list of what is attached would leave whoever reads it to work
 * out which of the papers are not among them.
 */

/** One paper asked for, with this person's answer to it where there is one. */
export interface DocumentRow {
    type_id: number;
    name: string;
    /** What exactly is wanted, in the office's own words. */
    hint: string | null;
    min_files: number;
    /** Null where no number of files is too many. */
    max_files: number | null;
    accepts_files: boolean;
    accepts_camera: boolean;
    is_required: boolean;
    /** The answer, where somebody has handed one in; null while nobody has. */
    id: number | null;
    status: DocumentStatus | null;
    /** Why it was sent back. */
    comment: string | null;
    submitted_at: string | null;
    reviewed_at: string | null;
    submitted_by: string | null;
    reviewed_by: string | null;
    files: DocumentFile[];
}

export type DocumentStatus = 'pending' | 'approved' | 'rejected';

export interface DocumentFile {
    id: number;
    name: string;
    size: number;
    mime: string;
    url: string;
}

export interface EmployeeDocuments {
    /** Whether the documents of this card are this viewer's to attach and take off. */
    canEdit: boolean;
    /** And whether they are theirs to pass or send back, which is the other right. */
    canReview: boolean;
    items: DocumentRow[];
}

/** A paper already on the card from elsewhere: the sheet's own diplomas and decrees. */
export interface CardPaper {
    name: string | null;
    url: string;
}

/**
 * The groups, in the order the card reads them, and what each is called.
 *
 * The settled papers come first and unheaded. The headings mark the parts of
 * the list somebody still has to do something about, and a heading over the
 * part nobody has to do anything about would be the first thing read in a
 * block that is read to find out what is still wanted.
 */
const GROUPS: { key: DocumentState; title?: string }[] = [
    { key: 'approved' },
    { key: 'pending', title: 'На рассмотрении' },
    { key: 'rejected', title: 'Отклонённые' },
    { key: 'missing', title: 'Не приложены' },
];

/**
 * How far the papers the company insists on have got: how many of the required
 * types are settled, out of how many there are.
 *
 * Only what has been accepted counts. A scan waiting in the queue is not a
 * paper on file — it may yet come back — and a reading that counted it would
 * show the card complete the moment somebody uploaded anything at all.
 *
 * The papers merely asked for are left out of both sides of the count: a second
 * passport nobody has is not a gap, and counting it would leave people who have
 * handed in everything that is wanted of them short of a hundred for ever.
 *
 * Null where nothing is insisted on: a bar with no required paper behind it
 * would be either an empty promise or a permanent 100%.
 */
export function requiredAccepted(items: DocumentRow[]): { done: number; total: number; percent: number } | null {
    const required = items.filter((row) => row.is_required);

    if (required.length === 0) {
        return null;
    }

    const done = required.filter((row) => row.status === 'approved').length;

    return { done, total: required.length, percent: Math.round((done / required.length) * 100) };
}

export function EmployeeDocumentsList({ employee, documents, papers }: { employee: number; documents: EmployeeDocuments; papers: CardPaper[] }) {
    /** The paper being read and answered, and the one being sent back. */
    const [opened, setOpened] = useState<DocumentRow | null>(null);
    const [rejecting, setRejecting] = useState<DocumentRow | null>(null);

    const grouped = GROUPS.map((group) => ({
        ...group,
        rows: documents.items.filter((row) => (row.status ?? 'missing') === group.key),
    })).filter((group) => group.rows.length > 0);

    if (documents.items.length === 0 && papers.length === 0) {
        return <p className="text-muted-foreground text-sm max-md:py-3.5 max-md:text-[15px]">Документы не приложены</p>;
    }

    return (
        <div className="flex flex-col gap-5">
            {documents.items.length === 0 && (
                <p className="text-muted-foreground text-sm">Список документов пуст: его ведут в «Справочниках», в разделе «Документы».</p>
            )}

            {grouped.map((group) => (
                <section key={group.key} className="flex flex-col gap-3">
                    {group.title !== undefined && (
                        <div className="flex items-center justify-between gap-3">
                            <h3 className="text-muted-foreground flex min-w-0 items-center gap-1.5 text-xs font-semibold">
                                {group.title}
                                {/* How many are still wanted, said in the one
                                    line that asks for them: the other groups
                                    are read for what is in them rather than for
                                    how many, and a figure there would be a
                                    score nobody is keeping. */}
                                {group.key === 'missing' && <span className="tabular-nums">{group.rows.length}</span>}
                            </h3>

                            {group.key === 'missing' && (
                                <Button variant="link" className="text-brand-strong h-auto shrink-0 p-0 text-xs font-normal dark:text-[#C5E27A]">
                                    Запросить
                                </Button>
                            )}
                        </div>
                    )}

                    <ul className="flex flex-col gap-2">
                        {group.rows.map((row) => (
                            <li key={row.type_id}>
                                <DocumentItem row={row} documents={documents} onOpen={() => setOpened(row)} />
                            </li>
                        ))}
                    </ul>
                </section>
            ))}

            {/* The papers the sheet itself carries: a diploma clipped to a place
                of study, the decree behind an award. They are not asked for in
                this list and are not passed or sent back — they live by the
                sheet's own rules — but they are documents on this card, and a
                block called «Документы» that left them out would be sending
                somebody back to the sheet to look for them. */}
            {papers.length > 0 && (
                <section className="flex flex-col gap-2">
                    <h3 className="text-muted-foreground text-xs font-semibold">Из личного листка</h3>

                    <ul className="flex flex-col gap-2">
                        {papers.map((paper) => (
                            <li key={paper.url}>
                                <a
                                    href={paper.url}
                                    target="_blank"
                                    rel="noopener"
                                    className="bg-muted/40 hover:bg-muted flex items-center gap-2.5 rounded-lg border px-3 py-2.5 transition-colors"
                                >
                                    <FileText className="text-muted-foreground size-4 shrink-0" />
                                    <span className="min-w-0 flex-1 truncate text-sm">{paper.name ?? 'Документ'}</span>
                                </a>
                            </li>
                        ))}
                    </ul>
                </section>
            )}

            {opened && (
                <DocumentDialog
                    employee={employee}
                    row={opened}
                    documents={documents}
                    // The reason is asked for in a window of its own, and this
                    // one steps aside for it: what it was holding — the files —
                    // is not what is being answered.
                    onReject={() => {
                        setRejecting(opened);
                        setOpened(null);
                    }}
                    onClose={() => setOpened(null)}
                />
            )}

            {rejecting && <RejectDialog employee={employee} row={rejecting} onClose={() => setRejecting(null)} />}
        </div>
    );
}

/**
 * The files one document is made of, on the terms its own type sets: how many,
 * and whether they may be chosen from a disk, photographed, or either.
 *
 * Images are shown as themselves and anything else as its name — a PDF has no
 * thumbnail to draw, and a grey square with «PDF» in it says less than the name
 * of the file does.
 */
function DocumentFilesInput({ row, files, onChange, error }: { row: DocumentRow; files: File[]; onChange: (files: File[]) => void; error?: string }) {
    const picker = useRef<HTMLInputElement>(null);
    const camera = useRef<HTMLInputElement>(null);
    const mode = useCameraMode();
    const [shooting, setShooting] = useState(false);

    /** Never more than the paper takes: the extra ones are refused by the server anyway. */
    const room = row.max_files === null ? Infinity : Math.max(0, row.max_files - files.length);
    const add = (chosen: FileList | File[] | null) =>
        chosen && onChange(row.max_files === null ? [...files, ...Array.from(chosen)] : [...files, ...Array.from(chosen)].slice(0, row.max_files));

    // A paper the office will only take photographed takes images alone, which
    // is what the picker then offers: a PDF chosen here would be refused on the
    // way in, and a box that offers one is a box that lies.
    const accept = row.accepts_files ? 'application/pdf,image/jpeg,image/png,image/webp' : 'image/jpeg,image/png,image/webp';

    return (
        <div className="grid content-start gap-2">
            <Label>Файлы</Label>

            {files.length > 0 && (
                <ul className="flex flex-col gap-1">
                    {files.map((file, index) => (
                        <ChosenFile
                            key={index}
                            file={file}
                            index={index}
                            onRename={(name) =>
                                onChange(
                                    files.map((item, other) =>
                                        other === index ? new File([item], name, { type: item.type, lastModified: item.lastModified }) : item,
                                    ),
                                )
                            }
                            onRemove={() => onChange(files.filter((_, other) => other !== index))}
                        />
                    ))}
                </ul>
            )}

            <div className="flex flex-wrap gap-2">
                {row.accepts_files && (
                    <Button type="button" variant="outline" size="sm" disabled={room === 0} onClick={() => picker.current?.click()}>
                        <Upload />
                        {row.max_files === 1 ? 'Выбрать файл' : 'Выбрать файлы'}
                    </Button>
                )}

                {/* Offered where the paper takes a photograph and there is a
                    camera to take it with. On a machine without one the button
                    is not drawn at all, rather than drawn and refused. */}
                {row.accepts_camera && mode !== 'none' && (
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        disabled={room === 0}
                        onClick={() => (mode === 'native' ? camera.current?.click() : setShooting(true))}
                    >
                        <Camera />
                        Сфотографировать
                    </Button>
                )}
            </div>

            {shooting && <CameraDialog onShot={(photo) => add([photo])} onClose={() => setShooting(false)} />}

            <input
                ref={picker}
                type="file"
                accept={accept}
                multiple={row.max_files !== 1}
                hidden
                onChange={(event) => {
                    add(event.target.files);
                    event.target.value = '';
                }}
            />

            {mode === 'native' && (
                <input
                    ref={camera}
                    type="file"
                    accept="image/jpeg,image/png,image/webp"
                    capture="environment"
                    hidden
                    onChange={(event) => {
                        add(event.target.files);
                        event.target.value = '';
                    }}
                />
            )}

            <InputError message={error} />
            {/* The same colour however many have been chosen. It used to turn
                red once there was no room for another, but the line says what
                the paper takes rather than what is wrong with what is here —
                and having chosen exactly as many as are asked for is the one
                state it was shouting about. That there is no room for a fourth
                is already said by the button that stops offering one. */}
            <p className="text-muted-foreground text-[13px]">{`${capitalize(filesWanted(row.min_files, row.max_files))}, каждый до 10 МБ.`}</p>
        </div>
    );
}

/** A file's name split where its kind begins: «Паспорт» and «.png». */
const splitName = (name: string): [string, string] => {
    const dot = name.lastIndexOf('.');

    return dot > 0 ? [name.slice(0, dot), name.slice(dot)] : [name, ''];
};

/**
 * One file about to be sent, under the name it will be filed under.
 *
 * The name is a box rather than a line of text, because what comes off a phone
 * or a scanner is called «Screenshot 2026-11-05 120243.png», and that is the
 * name the card then carries for ever. Renaming it here costs a click; renaming
 * it afterwards means taking the document off and attaching it again.
 *
 * Only the name is anybody's to write. What follows the last dot is left where
 * it is and shown beside the box: it is how the office knows a scan from a
 * photograph, and a PDF renamed «.jpg» would be refused on the way in for a
 * reason nobody could see.
 */
function ChosenFile({ file, index, onRename, onRemove }: { file: File; index: number; onRename: (name: string) => void; onRemove: () => void }) {
    const [stem, kind] = splitName(file.name);
    const [name, setName] = useState(stem);

    // Made once per file and let go of with the row. A preview made while
    // drawing would be a new one on every keystroke, and none of them ever
    // released.
    const preview = useMemo(() => (file.type.startsWith('image/') ? URL.createObjectURL(file) : null), [file]);

    useEffect(() => () => void (preview !== null && URL.revokeObjectURL(preview)), [preview]);

    // On the way out of the box rather than on every letter: the file is
    // replaced to be renamed, and replacing it at every keystroke would take
    // the cursor out of the box it is being typed in.
    const commit = () => {
        // The dot at the end of «Резюме Рахимов И.» is the initial's, and the
        // one in front of «pdf» is put back below: left as it was typed, the
        // file would be filed under «Резюме Рахимов И..pdf».
        const wanted = name.trim().replace(/\.+$/, '').trim();

        // A file has to be called something, so an empty box is not an answer —
        // it goes back to the name the file came with.
        if (wanted === '') {
            setName(stem);

            return;
        }

        // What the box says is what the card will say: the dot taken off above
        // is taken off here too, rather than left in a box that reads
        // «Резюме Рахимов И.» beside a «.pdf» written after it.
        setName(wanted);

        if (wanted !== stem) {
            onRename(wanted + kind);
        }
    };

    return (
        <li className="flex min-w-0 items-center gap-2 rounded-lg border px-2.5 py-2">
            {preview !== null ? (
                <img src={preview} alt="" className="size-9 shrink-0 rounded border object-cover" />
            ) : (
                <FileText className="text-muted-foreground size-4 shrink-0" />
            )}

            <div className="flex min-w-0 flex-1 items-center">
                <Input
                    value={name}
                    maxLength={120}
                    aria-label={`Имя файла ${index + 1}`}
                    className="focus-visible:border-ring hover:border-input h-7 min-w-0 border-transparent bg-transparent px-1 text-sm shadow-none focus-visible:ring-0 focus-visible:ring-offset-0 md:text-sm"
                    onChange={(event) => setName(event.target.value)}
                    onBlur={commit}
                    onKeyDown={(event) => {
                        if (event.key === 'Enter') {
                            event.preventDefault();
                            event.currentTarget.blur();
                        }

                        if (event.key === 'Escape') {
                            setName(stem);
                            event.currentTarget.blur();
                        }
                    }}
                />
                <span className="text-muted-foreground shrink-0 text-sm">{kind}</span>
            </div>

            {/* Read against the ten megabytes a file may weigh, which the line
                under the list says: a scan near that figure is one to look at
                again before it is sent. */}
            <span className="text-muted-foreground shrink-0 text-[13px] tabular-nums">{fileSize(file.size)}</span>

            <Button type="button" variant="ghost" size="icon" className="size-7 shrink-0" aria-label={`Убрать файл ${index + 1}`} onClick={onRemove}>
                <X />
            </Button>
        </li>
    );
}

/**
 * One paper, read and answered in one window.
 *
 * Whichever of the four it is in, the same window opens: what is wanted, where
 * it stands and who decided that, why it came back where it did, and the files
 * it is made of. The ones already on file are fetched back to be worked on, so
 * this window renames one, takes one off and adds another — and the card
 * changes only when it is saved.
 *
 * What the button at the end says depends on who is pressing it, and so does
 * what it does. Personnel are the people who pass papers, so a paper they save
 * is a paper that has been looked at by the one person who would be looking: it
 * goes straight among the accepted. Anybody else is handing one in, and what
 * they hand in waits to be looked at.
 */
function DocumentDialog({
    employee,
    row,
    documents,
    onReject,
    onClose,
}: {
    employee: number;
    row: DocumentRow;
    documents: EmployeeDocuments;
    /** Send it back with a reason, which is asked for in a window of its own. */
    onReject: () => void;
    onClose: () => void;
}) {
    const form = useClearingForm<{ files: File[] }>({ files: [] });
    /** Whether the files on file are in hand and may be worked on. */
    const [ready, setReady] = useState(false);
    const [unreachable, setUnreachable] = useState(false);
    /** Whether the window that asks before deleting is open. */
    const [removing, setRemoving] = useState(false);
    const [working, setWorking] = useState(false);
    /** The files as they came, to tell a window that was only looked at from one that was worked in. */
    const asFound = useRef<File[]>([]);

    const state = documentState(row);
    const { setData } = form;

    // Fetched back rather than named to the server: a document is saved as the
    // whole set of its files, so a file that is to stay has to be sent again
    // with the ones that are new. A paper nobody has handed in has none, and
    // the window opens straight into an empty box.
    useEffect(() => {
        if (!documents.canEdit) {
            return;
        }

        let reading = true;

        Promise.all(
            row.files.map(async (file) => {
                const answer = await fetch(file.url, { credentials: 'same-origin' });

                if (!answer.ok) {
                    throw new Error(String(answer.status));
                }

                return new File([await answer.blob()], file.name, { type: file.mime });
            }),
        ).then(
            (kept) => {
                if (reading) {
                    asFound.current = kept;
                    setData('files', kept);
                    setReady(true);
                }
            },
            () => reading && setUnreachable(true),
        );

        return () => {
            reading = false;
        };
    }, [documents.canEdit, row.files, setData]);

    /** Whether the files are exactly the ones that were opened — none added, renamed or taken off. */
    const untouched = form.data.files.length === asFound.current.length && form.data.files.every((file, index) => file === asFound.current[index]);

    /**
     * Whether pressing the button would do anything.
     *
     * Saving sends the files up again, and a paper sent up again is a paper
     * handed in again: for anybody who does not pass papers that means an
     * accepted document falling back into the queue because its window was
     * opened and closed by the button. The one window where touching nothing
     * still means something is a paper waiting in front of somebody who passes
     * them — that press is the passing.
     */
    const idle = untouched && !(documents.canReview && state === 'pending');

    const save = () => {
        // A paper that is waiting, opened by somebody who passes papers and
        // closed without a file being touched, is a paper they have looked at
        // and are accepting. Sending the files back up to say so would upload
        // ten megabytes to write one word in a row.
        if (untouched && documents.canReview && state === 'pending') {
            setWorking(true);
            router.put(
                route('employees.documents.approve', [employee, row.id]),
                {},
                { preserveScroll: true, onSuccess: onClose, onFinish: () => setWorking(false) },
            );

            return;
        }

        form.post(route('employees.documents.store', [employee, row.type_id]), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: onClose,
        });
    };

    const remove = () => {
        setWorking(true);
        router.delete(route('employees.documents.destroy', [employee, row.id]), {
            preserveScroll: true,
            onSuccess: onClose,
            onFinish: () => setWorking(false),
        });
    };

    /** Where it stands, who handed it in and who decided — one line of it. */
    const said = [
        documentStates[state].title,
        row.submitted_by !== null && `приложил ${row.submitted_by}`,
        row.submitted_at !== null && formatDate(row.submitted_at),
        state === 'approved' && row.reviewed_by !== null && `принял ${row.reviewed_by}`,
        state === 'rejected' && row.reviewed_by !== null && `отклонил ${row.reviewed_by}`,
    ]
        .filter(Boolean)
        .join(' · ');

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-h-[90svh] overflow-y-auto sm:max-w-md">
                <DialogHeader>
                    <DialogTitle className="break-words">{row.name}</DialogTitle>
                    <DialogDescription>{row.hint ?? `Приложите ${filesWanted(row.min_files, row.max_files)}.`}</DialogDescription>
                </DialogHeader>

                {/* Read before the files and before anything is done about
                    them: a paper comes back for a reason, and the reason is
                    what the window was opened for. */}
                {state === 'rejected' && row.comment !== null && (
                    <Alert variant="destructive">
                        <AlertTitle>Причина отклонения</AlertTitle>
                        <AlertDescription className="break-words">{row.comment}</AlertDescription>
                    </Alert>
                )}

                {state !== 'missing' && <p className="text-muted-foreground text-[13px]">{said}</p>}

                {documents.canEdit ? (
                    <>
                        {ready && (
                            <DocumentFilesInput
                                row={row}
                                files={form.data.files}
                                onChange={(files) => form.setData('files', files)}
                                error={
                                    (form.errors as Record<string, string | undefined>).files ??
                                    (form.errors as Record<string, string | undefined>)['files.0']
                                }
                            />
                        )}

                        {/* The files are on the disk and the card names them,
                            so this is a lost connection rather than a lost
                            document — said as what it is, and the window stays
                            open with nothing in it that would save over them. */}
                        {unreachable && <p className="text-destructive text-sm">Не удалось открыть приложенные файлы. Обновите страницу.</p>}

                        {!ready && !unreachable && (
                            <p className="text-muted-foreground flex items-center gap-2 text-sm">
                                <LoaderCircle className="size-4 animate-spin" />
                                Открываем приложенные файлы…
                            </p>
                        )}
                    </>
                ) : (
                    <ul className="flex flex-col gap-1">
                        {row.files.map((file) => (
                            <li key={file.id}>
                                <a
                                    href={file.url}
                                    target="_blank"
                                    rel="noopener"
                                    className="text-muted-foreground hover:text-brand-strong flex min-w-0 items-center gap-1.5 text-[13px] underline-offset-4 hover:underline"
                                >
                                    <FileText className="size-3.5 shrink-0" />
                                    <span className="min-w-0 flex-1 truncate">{file.name}</span>
                                    <span className="shrink-0 tabular-nums">{fileSize(file.size)}</span>
                                </a>
                            </li>
                        ))}
                    </ul>
                )}

                {/* «Удалить» at the far end of the row: it throws the paper
                    away, and a destructive button beside the one everybody
                    presses is a destructive button pressed by mistake. */}
                <DialogFooter className="sm:justify-between">
                    {row.id !== null && documents.canEdit ? (
                        <Button type="button" variant="destructive" className="max-md:h-11" disabled={working} onClick={() => setRemoving(true)}>
                            Удалить
                        </Button>
                    ) : (
                        <span className="max-sm:hidden" />
                    )}

                    <div className="flex flex-col-reverse gap-2 sm:flex-row sm:items-center">
                        {documents.canReview && state === 'pending' && (
                            <Button type="button" variant="outline" className="max-md:h-11" disabled={working || form.processing} onClick={onReject}>
                                Отклонить
                            </Button>
                        )}

                        {documents.canEdit && (
                            <Button
                                type="button"
                                className="max-md:h-11"
                                disabled={!ready || idle || form.processing || working || form.data.files.length < row.min_files}
                                onClick={save}
                            >
                                {(form.processing || working) && <LoaderCircle className="animate-spin" />}
                                {documents.canReview ? 'Сохранить' : 'Отправить'}
                            </Button>
                        )}
                    </div>
                </DialogFooter>

                <Dialog open={removing} onOpenChange={(open) => !open && setRemoving(false)}>
                    <DialogContent className="sm:max-w-md">
                        <DialogHeader>
                            <DialogTitle className="break-words">Удалить документ «{row.name}»?</DialogTitle>
                            <DialogDescription asChild>
                                <div className="flex flex-col gap-1.5">
                                    {row.files.length > 0 && <p>Приложенные файлы ({row.files.length}) удалятся вместе с записью.</p>}
                                    <p>Документ вернётся в список не приложенных — его придётся приложить и проверить заново.</p>
                                </div>
                            </DialogDescription>
                        </DialogHeader>
                        <DialogFooter>
                            <Button type="button" variant="outline" className="max-md:h-11" onClick={() => setRemoving(false)}>
                                Отмена
                            </Button>
                            <Button type="button" variant="destructive" className="max-md:h-11" disabled={working} onClick={remove}>
                                {working && <LoaderCircle className="animate-spin" />}
                                Удалить
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
            </DialogContent>
        </Dialog>
    );
}

/** Sending a document back, which takes a reason and will not go without one. */
function RejectDialog({ employee, row, onClose }: { employee: number; row: DocumentRow; onClose: () => void }) {
    const form = useClearingForm<{ comment: string }>({ comment: '' });

    const submit = () => {
        form.put(route('employees.documents.reject', [employee, row.id]), { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Отклонить документ</DialogTitle>
                    <DialogDescription>{row.name}</DialogDescription>
                </DialogHeader>

                <div className="grid content-start gap-2">
                    <Label htmlFor="document-comment" required>
                        Причина
                    </Label>
                    <Textarea
                        id="document-comment"
                        autoFocus
                        rows={3}
                        value={form.data.comment}
                        onChange={(event) => form.setData('comment', event.target.value)}
                    />
                    <InputError message={form.errors.comment} />
                    <p className="text-muted-foreground text-[13px]">
                        Её прочтёт тот, кто прикладывал документ, — напишите, что именно переснять или донести.
                    </p>
                </div>

                <DialogFooter>
                    <Button type="button" variant="outline" className="max-md:h-11" onClick={onClose}>
                        Отмена
                    </Button>
                    <Button
                        type="button"
                        variant="destructive"
                        className="max-md:h-11"
                        disabled={form.processing || form.data.comment.trim() === ''}
                        onClick={submit}
                    >
                        {form.processing && <LoaderCircle className="animate-spin" />}
                        Отклонить
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
