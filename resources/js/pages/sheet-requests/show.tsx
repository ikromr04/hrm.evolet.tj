import InputError from '@/components/input-error';
import { PageContent } from '@/components/page-content';
import { PageTitle } from '@/components/page-title';
import { Panel } from '@/components/panel';
import { PersonLink } from '@/components/person-link';
import { StatusBadge, type StatusTone } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import { useClearingForm } from '@/hooks/use-clearing-form';
import AppLayout from '@/layouts/app-layout';
// The stamps the server sends are instants, not written days, and this is the
// one formatter in the app that reads an instant and answers «07 ноя. 2026».
// It lives among the equipment helpers because the journal needed it first; it
// knows nothing about equipment, and a second copy of it here would be a date
// format this project maintains in two places.
import { formatMoment } from '@/lib/equipment';
import { plural } from '@/lib/plural';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import { Check, FileText, LoaderCircle, PencilLine, X } from 'lucide-react';
import { useState, type FormEventHandler } from 'react';

type RequestStatus = 'pending' | 'accepted' | 'rejected';

interface SheetRequest {
    id: number;
    status: RequestStatus;
    submitted_at: string;
    /** Null while nobody has answered it. */
    reviewed_at: string | null;
    /** Why it was refused, where it was. */
    comment: string | null;
    employee: { id: number; name: string; number: string };
}

/**
 * One line of «Личный листок» as it is read here: what the card says now and
 * what the person asks it to say.
 *
 * Both sides arrive as text the server has already made readable — dates
 * written out, a list of records one to a line, nothing at all written as a
 * dash — so this page compares and prints and does not interpret. `changed` is
 * the server's answer and not this page's: a paper swapped for another file of
 * the same name reads the same on both sides and is still a change.
 */
interface SheetLine {
    key: string;
    title: string;
    was: string;
    now: string;
    changed: boolean;
}

/** A PDF this request brought with it, named by the line of the sheet it hangs off. */
interface RequestPaper {
    id: string;
    line: string;
    name: string;
    url: string;
}

const statusLabel: Record<RequestStatus, string> = {
    pending: 'Ждёт разбора',
    accepted: 'Принята',
    rejected: 'Отклонена',
};

/**
 * Waiting is told in blue and not in amber, though amber is the colour of
 * «посмотрите»: on this page amber already marks the lines that differ, and a
 * badge in that colour over a table of marks in the same colour would read as
 * one of the marks.
 */
const statusTone: Record<RequestStatus, StatusTone> = {
    pending: 'info',
    accepted: 'success',
    rejected: 'danger',
};

/**
 * What each state means for the card itself — the thing the office actually
 * wants to know, and the one thing the name of the state does not say.
 */
const statusHint: Record<RequestStatus, string> = {
    pending: 'Карточка пока не изменилась: ни одна строка не записана.',
    accepted: 'Изменения записаны в карточку.',
    rejected: 'Изменения в карточку не попали.',
};

/**
 * The tint a differing line is read under, and the colour of the mark on it.
 *
 * Two signals and not one. A sheet is forty lines long and seven of them differ,
 * so the tint is what finds them at a glance — but a tint alone is no answer to
 * printing, to a photocopy, or to whoever cannot tell this hue from the row
 * above it, and the mark in the first column answers all three. Both are the
 * amber this application already warns in; the row takes it weakened, because a
 * badge's background across a whole line of text is a highlighter pen.
 */
const changedRow = 'bg-[#FBEFD9]/70 hover:bg-[#FBEFD9]/70 dark:bg-[#F5A524]/10 dark:hover:bg-[#F5A524]/10';
const changedInk = 'text-[#9A4A06] dark:text-[#F8C471]';

/** RecordTable's cells, since this table is not one; see the note on Lines. */
const headCell = 'text-muted-foreground h-auto px-3 py-2 text-xs font-normal whitespace-normal';
const bodyCell = 'px-3 py-2.5 align-top break-words whitespace-pre-line';

/**
 * What the two columns ask, written out rather than «Было / Стало».
 *
 * Nothing has been and nothing has become yet — that is the whole point of the
 * page. «Было» over the left column would say the card has already changed, and
 * the office would be reading a record of something that has not happened.
 */
const ON_FILE = 'Сейчас в карточке';
const ASKED = 'Просят записать';

/**
 * The mark a differing line carries, in the first column before its title.
 *
 * An unchanged line carries a blank of the same size instead of nothing at all:
 * the titles then start on one vertical line down the whole table, and the few
 * marks stand out of that line rather than nudging seven titles rightwards.
 */
function Mark({ changed }: { changed: boolean }) {
    return changed ? (
        <>
            <PencilLine className={cn('mt-0.5 size-4 shrink-0', changedInk)} aria-hidden="true" />
            <span className="sr-only">Изменено. </span>
        </>
    ) : (
        <span className="size-4 shrink-0" aria-hidden="true" />
    );
}

/**
 * The whole sheet, line by line, in the order the sheet asks its questions.
 *
 * A line nobody touched prints its answer once and says «без изменений» on the
 * right. The alternative — the same sentence twice across two columns, forty
 * times down the page — is what the eye has to wade through to find the seven
 * lines that matter, and two identical columns of grey say less than one column
 * and a word.
 *
 * Said in words and not as a dash: a dash is already taken, by a line of the
 * sheet nobody has ever answered.
 *
 * It is a table built here out of the shadcn primitives rather than
 * `RecordTable`, which is what this project reads short lists of records in.
 * That component draws every row alike and gives the caller no way to tone one,
 * by design — it is for the schools somebody went to, where no row is more
 * interesting than its neighbour. Here the state of the row is the point, and a
 * tone per row cannot be faked from inside the cells: the padding between them
 * would stay untinted and the line would read as broken rather than as marked.
 */
function Lines({ lines }: { lines: SheetLine[] }) {
    return (
        <>
            {/* Columns as parts of the whole, so a long answer wraps inside its
                own column instead of widening the table and pushing the page
                sideways. The question is a quarter of the width and the two
                answers share the rest evenly: they are read against each other,
                and the one that is longer is not the more important. */}
            <div className="overflow-hidden rounded-lg border max-md:hidden">
                <Table className="table-fixed">
                    <colgroup>
                        <col style={{ width: '26%' }} />
                        <col style={{ width: '37%' }} />
                        <col style={{ width: '37%' }} />
                    </colgroup>
                    <TableHeader>
                        <TableRow className="bg-muted/40 hover:bg-muted/40">
                            <TableHead className={headCell}>Строка</TableHead>
                            <TableHead className={headCell}>{ON_FILE}</TableHead>
                            <TableHead className={headCell}>{ASKED}</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {lines.map((line) => {
                            const same = !line.changed && line.now === line.was;

                            return (
                                <TableRow key={line.key} className={cn(line.changed ? changedRow : 'hover:bg-transparent')}>
                                    <TableCell className={cn(bodyCell, 'font-medium')}>
                                        <span className="flex items-start gap-2">
                                            <Mark changed={line.changed} />
                                            <span className="min-w-0">{line.title}</span>
                                        </span>
                                    </TableCell>
                                    <TableCell className={cn(bodyCell, 'text-muted-foreground')}>{line.was || '—'}</TableCell>
                                    <TableCell className={cn(bodyCell, line.changed ? 'font-medium' : 'text-muted-foreground')}>
                                        {same ? 'без изменений' : line.now || '—'}
                                    </TableCell>
                                </TableRow>
                            );
                        })}
                    </TableBody>
                </Table>
            </div>

            {/* A phone reads the same lines as a list: three columns at 390px
                would be three narrow gutters of broken words, and the sheet is
                read line by line anyway. */}
            <ul className="flex flex-col gap-2 md:hidden">
                {lines.map((line) => {
                    const same = !line.changed && line.now === line.was;

                    return (
                        <li key={line.key} className={cn('rounded-lg border p-3', line.changed ? changedRow : 'bg-muted/40')}>
                            <p className="flex items-start gap-2 text-sm font-medium">
                                <Mark changed={line.changed} />
                                <span className="min-w-0 break-words">{line.title}</span>
                            </p>

                            {same ? (
                                <p className="text-muted-foreground mt-1.5 pl-6 text-sm break-words whitespace-pre-line">{line.was || '—'}</p>
                            ) : (
                                <dl className="mt-2 flex flex-col gap-2 pl-6">
                                    <div className="flex min-w-0 flex-col gap-0.5">
                                        <dt className="text-muted-foreground text-xs">{ON_FILE}</dt>
                                        <dd className="text-muted-foreground text-sm break-words whitespace-pre-line">{line.was || '—'}</dd>
                                    </div>
                                    <div className="flex min-w-0 flex-col gap-0.5">
                                        <dt className="text-muted-foreground text-xs">{ASKED}</dt>
                                        <dd className="text-sm font-medium break-words whitespace-pre-line">{line.now || '—'}</dd>
                                    </div>
                                </dl>
                            )}
                        </li>
                    );
                })}
            </ul>
        </>
    );
}

/**
 * The papers the request brought, each named by the line of the sheet it
 * belongs to and opened in a window of its own.
 *
 * They are not on the card yet, and they are not read from it: until the office
 * accepts the sheet these files sit beside the request, and a refusal deletes
 * them. So a refused request lists what it carried by name and offers no way
 * through — the file behind that link is gone, and a link that answers with an
 * error page reads as a system that has lost the document rather than as one
 * that threw it away on purpose.
 */
function Papers({ papers, gone }: { papers: RequestPaper[]; gone: boolean }) {
    return (
        <>
            {gone && <p className="text-muted-foreground mb-3 text-[13px]">Файлы удалены вместе с отказом — остались только их названия.</p>}

            <ul className="flex flex-col gap-2">
                {papers.map((paper) => (
                    <li key={paper.id} className="flex min-w-0 flex-col gap-0.5">
                        <span className="text-muted-foreground text-xs">{paper.line}</span>

                        {gone ? (
                            <span className="text-muted-foreground flex min-w-0 items-start gap-1.5 text-sm">
                                <FileText className="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />
                                <span className="min-w-0 break-words">{paper.name}</span>
                            </span>
                        ) : (
                            <a
                                href={paper.url}
                                target="_blank"
                                rel="noopener"
                                className="hover:text-brand-strong flex min-w-0 items-start gap-1.5 text-sm underline-offset-4 hover:underline dark:hover:text-[#C5E27A]"
                            >
                                {/* The mark the sheet clips every one of its papers
                                    with, so a document is known for one wherever the
                                    application shows it. */}
                                <FileText className="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />
                                <span className="min-w-0 break-words">{paper.name}</span>
                            </a>
                        )}
                    </li>
                ))}
            </ul>
        </>
    );
}

/**
 * «Личный листок», retyped by the person it is about and sent to the personnel
 * office — the page the office answers it on.
 *
 * The whole sheet is here, not the differences. A head of department who has
 * changed where they live has also, two lines above it, left a diploma and a
 * date of birth standing: whoever writes that into the card is answering for
 * all of it, and a page showing seven lines out of forty would have them sign
 * for a document they never read.
 *
 * And the answer is one answer. There is no box beside a line and no «принять
 * эту строку»: the office accepts the sheet or refuses it, because the sheet is
 * what the person wrote and signed, and a card assembled out of half of
 * somebody's listok is a card nobody ever filled in. The buttons stand once, at
 * the end, under the last line.
 */
export default function SheetRequestShow({
    request,
    lines,
    papers,
    canReview,
}: {
    request: SheetRequest;
    lines: SheetLine[];
    papers: RequestPaper[];
    /** Whether this viewer is one of the people who answer these at all. */
    canReview: boolean;
}) {
    const [refusing, setRefusing] = useState(false);

    // Nothing to send with it: the whole request is the decision. It is still a
    // form rather than a bare router.post, for `processing` — the two buttons
    // have to go quiet while the card is being written.
    const accept = useForm({});
    const reject = useClearingForm({ comment: '' });

    const pending = request.status === 'pending';
    const answerable = canReview && pending;
    const busy = accept.processing || reject.processing;

    const changed = lines.filter((line) => line.changed).length;

    const refuse: FormEventHandler = (event) => {
        event.preventDefault();

        reject.post(route('sheet_requests.reject', request.id), {
            preserveScroll: true,
            onSuccess: () => setRefusing(false),
        });
    };

    // The office arrives here from the bell, so that is the step above this
    // page: it sits under nothing else and has no list of its own.
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Уведомления', href: '/notifications' },
        { title: 'Личный листок', href: `/sheet-requests/${request.id}` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Личный листок · ${request.employee.name}`} />

            <PageContent>
                <PageTitle subtitle="Отдел кадров отвечает за листок целиком: принять или отклонить можно только все строки разом.">
                    Личный листок на проверку
                </PageTitle>

                <Panel
                    title="Заявка"
                    info={
                        <>
                            <StatusBadge tone={statusTone[request.status]}>{statusLabel[request.status]}</StatusBadge>
                            <span className="text-muted-foreground text-[13px]">{statusHint[request.status]}</span>
                        </>
                    }
                >
                    {/* A label over its answer, in as many columns as fit — the
                        way every other block of a card writes its facts. */}
                    <dl className="grid gap-x-6 gap-y-4 sm:grid-cols-2 lg:grid-cols-4">
                        <div className="flex min-w-0 flex-col gap-1">
                            <dt className="text-muted-foreground text-xs">Сотрудник</dt>
                            <dd className="min-w-0 text-sm font-medium break-words">
                                {/* Straight to the card the sheet would be
                                    written into: the office reads the two beside
                                    each other, and the name is the way across. */}
                                <PersonLink id={request.employee.id} className="text-brand-strong hover:underline dark:text-[#C5E27A]">
                                    {request.employee.name}
                                </PersonLink>
                            </dd>
                        </div>

                        <div className="flex min-w-0 flex-col gap-1">
                            <dt className="text-muted-foreground text-xs">Табельный номер</dt>
                            <dd className="min-w-0 text-sm font-medium break-words tabular-nums">
                                {request.employee.number || <span className="text-muted-foreground font-normal">—</span>}
                            </dd>
                        </div>

                        <div className="flex min-w-0 flex-col gap-1">
                            <dt className="text-muted-foreground text-xs">Отправлено</dt>
                            <dd className="min-w-0 text-sm font-medium tabular-nums">{formatMoment(request.submitted_at)}</dd>
                        </div>

                        {request.reviewed_at && (
                            <div className="flex min-w-0 flex-col gap-1">
                                <dt className="text-muted-foreground text-xs">Разобрано</dt>
                                <dd className="min-w-0 text-sm font-medium tabular-nums">{formatMoment(request.reviewed_at)}</dd>
                            </div>
                        )}
                    </dl>
                </Panel>

                <Panel
                    title="Листок"
                    count={lines.length}
                    info={
                        changed > 0 ? (
                            <span className="text-muted-foreground text-[13px]">
                                <PencilLine className={cn('-mt-0.5 mr-1.5 inline size-3.5', changedInk)} aria-hidden="true" />
                                {changed} {plural(changed, ['изменённая строка', 'изменённые строки', 'изменённых строк'])} из {lines.length}
                            </span>
                        ) : (
                            // It can happen: a sheet sent again after a refusal,
                            // corrected everywhere except in the one line the
                            // office asked about. Better said than left for the
                            // reader to work out by reading forty rows.
                            <span className="text-muted-foreground text-[13px]">Ни одна строка карточки не меняется</span>
                        )
                    }
                >
                    <Lines lines={lines} />
                </Panel>

                {papers.length > 0 && (
                    <Panel title="Документы заявки" count={papers.length}>
                        <Papers papers={papers} gone={request.status === 'rejected'} />
                    </Panel>
                )}

                <Panel title="Решение">
                    {answerable && (
                        <>
                            <p className="text-muted-foreground max-w-prose text-sm">
                                Принятый листок ляжет в карточку целиком, вместе со вложенными документами. Отклонённый не изменит в карточке ни
                                строки, а его файлы будут удалены — сотрудник прочитает причину и отправит листок заново.
                            </p>

                            <div className="mt-4 flex flex-wrap gap-3">
                                <Button
                                    onClick={() => accept.post(route('sheet_requests.accept', request.id), { preserveScroll: true })}
                                    disabled={busy}
                                >
                                    {accept.processing ? <LoaderCircle className="animate-spin" /> : <Check />}
                                    Принять
                                </Button>

                                <Button variant="destructive" onClick={() => setRefusing(true)} disabled={busy}>
                                    <X />
                                    Отклонить
                                </Button>
                            </div>
                        </>
                    )}

                    {pending && !canReview && <p className="text-muted-foreground text-sm">Листок ждёт разбора отделом кадров.</p>}

                    {!pending && (
                        <div className="flex flex-col gap-3">
                            <p className="text-sm">
                                {request.status === 'accepted'
                                    ? `Листок принят ${formatMoment(request.reviewed_at)} — изменения записаны в карточку.`
                                    : `Листок отклонён ${formatMoment(request.reviewed_at)} — карточка осталась прежней.`}
                            </p>

                            {/* Carried over as it was written, line breaks and
                                all: it is the one sentence of this whole page
                                that a person wrote by hand, and the sentence the
                                sheet's author is reading on their own card. */}
                            {request.comment && (
                                <blockquote className="text-muted-foreground border-l-2 pl-3 text-sm break-words whitespace-pre-line">
                                    {request.comment}
                                </blockquote>
                            )}
                        </div>
                    )}
                </Panel>
            </PageContent>

            {/* Kept out of the block above so that the dialog is not torn down
                and rebuilt as the buttons under it come and go. */}
            <Dialog open={refusing} onOpenChange={(open) => !open && setRefusing(false)}>
                <DialogContent className="sm:max-w-md">
                    {/* noValidate: the server's rules are the real ones, and the
                        comment is demanded there. */}
                    <form onSubmit={refuse} noValidate className="flex flex-col gap-5">
                        <DialogHeader>
                            <DialogTitle>Отклонить листок</DialogTitle>
                            <DialogDescription>
                                Причину прочитает {request.employee.name}: она встанет у него на карточке, над листком. Напишите, что именно
                                поправить, — он отправит листок заново.
                            </DialogDescription>
                        </DialogHeader>

                        <div className="grid content-start gap-2">
                            <Label htmlFor="reject-comment" required>
                                Причина отказа
                            </Label>
                            <Textarea
                                id="reject-comment"
                                required
                                // As long as the server will keep, so that a long
                                // explanation is stopped while it is being typed
                                // rather than refused once it has been sent.
                                maxLength={1000}
                                value={reject.data.comment}
                                onChange={(event) => reject.setData('comment', event.target.value)}
                                placeholder="Дата рождения ребёнка не совпадает со свидетельством — проверьте и отправьте листок заново."
                                aria-invalid={!!reject.errors.comment}
                            />
                            <InputError message={reject.errors.comment} />
                        </div>

                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setRefusing(false)}>
                                Отмена
                            </Button>
                            <Button type="submit" variant="destructive" disabled={reject.processing}>
                                {reject.processing && <LoaderCircle className="animate-spin" />}
                                Отклонить
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
