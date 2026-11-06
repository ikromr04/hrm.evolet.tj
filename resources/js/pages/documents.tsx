import InputError from '@/components/input-error';
import { PageContent } from '@/components/page-content';
import { PageTitle } from '@/components/page-title';
import { Panel } from '@/components/panel';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useClearingForm } from '@/hooks/use-clearing-form';
import AppLayout from '@/layouts/app-layout';
import { formatDate } from '@/lib/employee';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { Check, FileText, LoaderCircle, X } from 'lucide-react';
import { useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Документы', href: '/documents' }];

/** One document waiting to be looked at, as the queue reads it. */
interface Waiting {
    id: number;
    employee: { id: number; name: string };
    name: string;
    hint: string | null;
    submitted_at: string | null;
    submitted_by: string | null;
    files: { id: number; name: string; url: string }[];
}

/**
 * «Документы» for whoever passes them: everything handed in across every card
 * and not yet looked at, oldest first.
 *
 * The card a document belongs to is one press away, because a paper is
 * sometimes judged by what else is on the card — but the two answers the queue
 * exists for are here, so that working through it is not twenty visits to
 * twenty cards.
 */
export default function Documents({ items }: { items: Waiting[] }) {
    const [rejecting, setRejecting] = useState<Waiting | null>(null);
    const [working, setWorking] = useState<number | null>(null);

    const approve = (row: Waiting) => {
        setWorking(row.id);
        router.put(route('employees.documents.approve', [row.employee.id, row.id]), {}, { preserveScroll: true, onFinish: () => setWorking(null) });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Документы" />

            <PageContent>
                <PageTitle>Документы</PageTitle>

                <Panel title="На рассмотрении" count={items.length}>
                    {items.length === 0 ? (
                        // Not an empty list but a sentence: an empty table reads
                        // as one that failed to load, and this is the usual
                        // state of a queue that is being kept up with.
                        <p className="text-muted-foreground text-sm">Нет документов, ожидающих рассмотрения.</p>
                    ) : (
                        <ul className="flex flex-col gap-3">
                            {items.map((row) => (
                                <li key={row.id} className="flex flex-col gap-3 rounded-lg border p-4">
                                    <div className="flex flex-wrap items-start justify-between gap-3">
                                        <div className="flex min-w-0 flex-col gap-1">
                                            <Link
                                                href={`/employees/${row.employee.id}?tab=sheet`}
                                                className="text-brand-strong min-w-0 text-sm font-medium break-words hover:underline dark:text-[#C5E27A]"
                                            >
                                                {row.employee.name}
                                            </Link>
                                            <span className="min-w-0 text-sm break-words">{row.name}</span>
                                            {row.hint !== null && <span className="text-muted-foreground text-[13px]">{row.hint}</span>}
                                        </div>

                                        <div className="flex shrink-0 items-center gap-1">
                                            <Button variant="outline" size="sm" disabled={working === row.id} onClick={() => approve(row)}>
                                                {working === row.id ? <LoaderCircle className="animate-spin" /> : <Check />}
                                                Принять
                                            </Button>
                                            <Button variant="ghost" size="sm" className="text-destructive" onClick={() => setRejecting(row)}>
                                                <X />
                                                Отклонить
                                            </Button>
                                        </div>
                                    </div>

                                    <ul className="flex flex-wrap gap-2">
                                        {row.files.map((file) => (
                                            <li key={file.id}>
                                                <a
                                                    href={file.url}
                                                    target="_blank"
                                                    rel="noopener"
                                                    className="bg-muted/40 hover:bg-muted flex items-center gap-2 rounded-lg border px-2.5 py-1.5 text-[13px] transition-colors"
                                                >
                                                    <FileText className="text-muted-foreground size-3.5 shrink-0" />
                                                    <span className="min-w-0 truncate">{file.name}</span>
                                                </a>
                                            </li>
                                        ))}
                                    </ul>

                                    <p className="text-muted-foreground text-xs">
                                        {[row.submitted_by !== null && `Приложил ${row.submitted_by}`, formatDate(row.submitted_at)]
                                            .filter(Boolean)
                                            .join(' · ')}
                                    </p>
                                </li>
                            ))}
                        </ul>
                    )}
                </Panel>
            </PageContent>

            {rejecting && <RejectDialog row={rejecting} onClose={() => setRejecting(null)} />}
        </AppLayout>
    );
}

/** Sending one back, which takes a reason and will not go without one. */
function RejectDialog({ row, onClose }: { row: Waiting; onClose: () => void }) {
    const form = useClearingForm<{ comment: string }>({ comment: '' });

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Отклонить документ</DialogTitle>
                    <DialogDescription>
                        {row.name} · {row.employee.name}
                    </DialogDescription>
                </DialogHeader>

                <div className="grid content-start gap-2">
                    <Label htmlFor="review-comment" required>
                        Причина
                    </Label>
                    <Textarea
                        id="review-comment"
                        autoFocus
                        rows={3}
                        value={form.data.comment}
                        onChange={(event) => form.setData('comment', event.target.value)}
                    />
                    <InputError message={form.errors.comment} />
                    <p className="text-muted-foreground text-[13px]">Её прочтёт тот, кто прикладывал документ.</p>
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
                        onClick={() =>
                            form.put(route('employees.documents.reject', [row.employee.id, row.id]), {
                                preserveScroll: true,
                                onSuccess: onClose,
                            })
                        }
                    >
                        {form.processing && <LoaderCircle className="animate-spin" />}
                        Отклонить
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
