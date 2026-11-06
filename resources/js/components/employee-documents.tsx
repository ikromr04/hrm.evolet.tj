import { CameraDialog, useCameraMode } from '@/components/camera-capture';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useClearingForm } from '@/hooks/use-clearing-form';
import { filesWanted } from '@/lib/documents';
import { capitalize, formatDate } from '@/lib/employee';
import { cn } from '@/lib/utils';
import { router } from '@inertiajs/react';
import { Camera, Check, FileText, LoaderCircle, Paperclip, Trash2, Upload, X } from 'lucide-react';
import { useRef, useState } from 'react';

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
 * The groups, in the order the card reads them, what each is called, and the
 * colour of the dot its papers are read down.
 *
 * The four hues are the palette's own — the greens, blues, reds and ambers the
 * badges are built from — and they say the same thing here as there: settled,
 * being looked at, sent back, not here yet. The dot is read before the name of
 * the paper and carries no word of its own, so it never stands alone: the
 * heading above it and the badge on the card both say it in writing.
 */
const GROUPS: { key: DocumentStatus | 'missing'; title: string; dot: string }[] = [
    { key: 'approved', title: 'Принятые', dot: 'bg-[#A8CF45]' },
    { key: 'pending', title: 'На рассмотрении', dot: 'bg-[#3B82F6]' },
    { key: 'rejected', title: 'Отклонённые', dot: 'bg-[#EF4444]' },
    { key: 'missing', title: 'Не приложены', dot: 'bg-[#F5A524]' },
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
    /** The paper being attached to, and the one being sent back, each in its own window. */
    const [attaching, setAttaching] = useState<DocumentRow | null>(null);
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
                <section key={group.key} className="flex flex-col gap-2">
                    <h3 className="text-muted-foreground flex min-w-0 items-center gap-2 text-xs font-normal">{group.title}</h3>

                    <ul className="flex flex-col gap-2">
                        {group.rows.map((row) => (
                            <li key={row.type_id} className="flex min-w-0 items-start gap-2">
                                <span className={cn('mt-[7px] size-1.5 shrink-0 rounded-full', group.dot)} />

                                <div className="min-w-0 flex-1">
                                    <Paper
                                        row={row}
                                        employee={employee}
                                        documents={documents}
                                        onAttach={() => setAttaching(row)}
                                        onReject={() => setRejecting(row)}
                                    />
                                </div>
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
                    <h3 className="text-muted-foreground text-xs font-normal">Из личного листка</h3>

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

            {attaching && <AttachDialog employee={employee} row={attaching} onClose={() => setAttaching(null)} />}

            {rejecting && <RejectDialog employee={employee} row={rejecting} onClose={() => setRejecting(null)} />}
        </div>
    );
}

/** One paper: what it is called, what is attached to it, and what may be done about it. */
function Paper({
    row,
    employee,
    documents,
    onAttach,
    onReject,
}: {
    row: DocumentRow;
    employee: number;
    documents: EmployeeDocuments;
    onAttach: () => void;
    onReject: () => void;
}) {
    const [working, setWorking] = useState(false);

    const act = (url: string, method: 'put' | 'delete') => {
        setWorking(true);
        router[method](url, {}, { preserveScroll: true, onFinish: () => setWorking(false) });
    };

    return (
        <div className="flex flex-col gap-2">
            <div className="flex min-w-0 flex-col gap-0.5">
                <span className="min-w-0 text-sm font-medium break-words">
                    {row.name}
                    {/* Marked where it is wanted of everybody and not here yet:
                        once it is attached, that it was obligatory is no longer
                        anything to act on. */}
                    {row.is_required && row.status === null && <span className="text-destructive"> *</span>}
                </span>
                {row.hint !== null && row.status === null && <span className="text-muted-foreground text-[13px]">{row.hint}</span>}
            </div>

            {row.files.length > 0 && (
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
                                <span className="min-w-0 truncate">{file.name}</span>
                            </a>
                        </li>
                    ))}
                </ul>
            )}

            {/* Who it came from and when, and — where it was sent back — what for.
                A reason is the one thing the person it was sent back to has to
                act on, so it is written out rather than kept behind a button. */}
            {row.status === 'rejected' && row.comment !== null && <p className="text-destructive text-[13px]">{row.comment}</p>}

            {row.status !== null && (
                <p className="text-muted-foreground text-xs">
                    {[
                        row.submitted_by !== null && `Приложил ${row.submitted_by}`,
                        row.submitted_at !== null && formatDate(row.submitted_at),
                        row.status !== 'pending' &&
                            row.reviewed_by !== null &&
                            `${row.status === 'approved' ? 'Принял' : 'Отклонил'} ${row.reviewed_by}`,
                    ]
                        .filter(Boolean)
                        .join(' · ')}
                </p>
            )}

            {(documents.canEdit || (documents.canReview && row.status === 'pending')) && (
                <div className="flex flex-wrap items-center gap-1">
                    {documents.canReview && row.status === 'pending' && (
                        <>
                            <Button
                                variant="ghost"
                                size="sm"
                                className="h-7 px-2 font-normal"
                                disabled={working}
                                onClick={() => act(route('employees.documents.approve', [employee, row.id]), 'put')}
                            >
                                <Check />
                                Принять
                            </Button>
                            <Button variant="ghost" size="sm" className="text-destructive h-7 px-2 font-normal" onClick={onReject}>
                                <X />
                                Отклонить
                            </Button>
                        </>
                    )}

                    {documents.canEdit && (
                        <>
                            <Button variant="ghost" size="sm" className="h-7 px-2 font-normal" onClick={onAttach}>
                                <Paperclip />
                                {row.status === null ? 'Приложить' : 'Заменить'}
                            </Button>

                            {row.status !== null && (
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    className="text-muted-foreground size-7"
                                    aria-label={`Убрать документ: ${row.name}`}
                                    disabled={working}
                                    onClick={() => act(route('employees.documents.destroy', [employee, row.id]), 'delete')}
                                >
                                    {working ? <LoaderCircle className="animate-spin" /> : <Trash2 />}
                                </Button>
                            )}
                        </>
                    )}
                </div>
            )}
        </div>
    );
}

/**
 * Attaching one paper: the files it is made of, chosen or photographed, and
 * nothing happens until «Сохранить» — so a window closed by mistake leaves the
 * card exactly as it was.
 */
function AttachDialog({ employee, row, onClose }: { employee: number; row: DocumentRow; onClose: () => void }) {
    const form = useClearingForm<{ files: File[] }>({ files: [] });

    const submit = () => {
        form.post(route('employees.documents.store', [employee, row.type_id]), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: onClose,
        });
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-h-[90svh] overflow-y-auto sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>{row.name}</DialogTitle>
                    <DialogDescription>{row.hint ?? `Приложите ${filesWanted(row.min_files, row.max_files)}.`}</DialogDescription>
                </DialogHeader>

                <DocumentFilesInput
                    row={row}
                    files={form.data.files}
                    onChange={(files) => form.setData('files', files)}
                    error={
                        (form.errors as Record<string, string | undefined>).files ?? (form.errors as Record<string, string | undefined>)['files.0']
                    }
                />

                <DialogFooter className="gap-2">
                    <Button type="button" variant="outline" className="max-md:h-11" onClick={onClose}>
                        Отмена
                    </Button>
                    {/* As few as the paper asks for: a window that let one
                        file be sent where two are wanted would be answered by a
                        refusal the person could have been spared. */}
                    <Button
                        type="button"
                        className="max-md:h-11"
                        disabled={form.processing || form.data.files.length < row.min_files}
                        onClick={submit}
                    >
                        {form.processing && <LoaderCircle className="animate-spin" />}
                        Сохранить
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
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
                        <li key={index} className="flex min-w-0 items-center gap-2 rounded-lg border px-2.5 py-2">
                            {file.type.startsWith('image/') ? (
                                <img src={URL.createObjectURL(file)} alt="" className="size-9 shrink-0 rounded border object-cover" />
                            ) : (
                                <FileText className="text-muted-foreground size-4 shrink-0" />
                            )}
                            <span className="min-w-0 flex-1 truncate text-sm" title={file.name}>
                                {file.name}
                            </span>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                className="size-7 shrink-0"
                                aria-label={`Убрать файл ${index + 1}`}
                                onClick={() => onChange(files.filter((_, other) => other !== index))}
                            >
                                <X />
                            </Button>
                        </li>
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
            <p className={cn('text-muted-foreground text-[13px]', room === 0 && 'text-destructive')}>
                {`${capitalize(filesWanted(row.min_files, row.max_files))}, каждый до 10 МБ.`}
            </p>
        </div>
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

                <DialogFooter className="gap-2">
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
