<?php

namespace App\Http\Controllers;

use App\Models\DocumentType;
use App\Models\EmployeeDocument;
use App\Models\EmployeeDocumentFile;
use App\Models\User;
use App\Support\DocumentStore;
use App\Support\EmployeeFields;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * What a person has handed in against the papers the company asks everybody for.
 *
 * Two hands do two different things here. Somebody hands a document in, and
 * somebody in the office says whether it will do — and a document handed in by
 * the office itself has already been looked at by the one person who would be
 * doing the looking, so it needs no passing. That is the whole of what the
 * status means, and the only place it is decided is the store below.
 *
 * Reading a document and attaching one are the two rights the card is read by
 * everywhere else: «documents» is a block of a card like the passport or the
 * family, and it is visibleTo/editableBy that answer for it. Passing one is a
 * right of its own, because it is not a correction to the card — it is a
 * judgement about what is on it.
 */
class EmployeeDocumentController extends Controller
{
    /**
     * How many files one document may be made of.
     *
     * A document is as many pages as it is, and nobody was counting them —
     * until the number is the point: twenty files of ten megabytes is two
     * hundred megabytes under one line of a checklist, and nothing above this
     * rule would have stopped a thousand of them.
     *
     * Twenty rather than some rounder number because that is what PHP itself
     * takes in one request (`max_file_uploads`). Beyond it the extra files are
     * dropped on the way in without a word, and a refusal that says so is
     * better than a save that quietly keeps the first twenty.
     */
    private const MOST_FILES = 20;

    /**
     * What one person hands in against one paper, attached or attached again.
     *
     * One row per person per paper: a second attempt replaces the first rather
     * than standing beside it. Nobody reads the history of a passport copy —
     * what is wanted is the copy that stands now and whether it will do.
     *
     * A paper kept on paper is handed in across a desk, so the same call makes
     * its row with no files at all: the row is then the tick that says the
     * office is holding the original, and files sent later are clipped to it.
     */
    public function store(Request $request, User $employee, DocumentType $documentType): RedirectResponse
    {
        $this->mayAttach($request, $employee);

        // Saying «оригинал у нас» is a judgement about a paper in somebody's
        // safe, not a scan anybody can send, so it belongs to the same hands
        // that pass documents — on their own card as much as on anybody's.
        if ($documentType->on_paper) {
            $this->mayPass($request);
        }

        $request->validate([
            // One document, as many files as it takes: a passport is two pages
            // and a diploma is a sheet with an appendix. A paper kept on paper
            // asks for none at all — the tick is the answer.
            'files' => [$documentType->on_paper ? 'nullable' : 'required', 'array', 'max:'.self::MOST_FILES],
            // PDF and nothing else. The office prints, signs and scans, and a
            // page photographed on a telephone arrives as a document that
            // cannot be printed back out at the size it was.
            'files.*' => ['bail', 'file', 'mimes:pdf', 'max:10240'],
        ], [
            'files.required' => 'Приложите хотя бы один файл.',
            'files.max' => 'Можно приложить не больше '.self::MOST_FILES.' файлов.',
            'files.*.mimes' => 'Документ прикладывают файлом PDF.',
            'files.*.max' => 'Файл должен быть не тяжелее 10 МБ.',
        ], [
            'files' => 'файлы',
            'files.*' => 'файл',
        ]);

        // Attached by somebody who passes documents, so it is passed: asking
        // them to look at what they have just attached themselves would be
        // asking them to check their own work with a second button.
        $passes = $this->passes($request);
        $now = now();

        $replaced = DB::transaction(function () use ($request, $employee, $documentType, $passes, $now) {
            $document = EmployeeDocument::updateOrCreate(
                ['user_id' => $employee->id, 'document_type_id' => $documentType->id],
                [
                    'status' => $passes ? EmployeeDocument::APPROVED : EmployeeDocument::PENDING,
                    // Whatever it was turned down for is answered by this
                    // attempt, so the reason goes with the files it was about.
                    'comment' => null,
                    'submitted_by' => $request->user()->id,
                    'submitted_at' => $now,
                    'reviewed_by' => $passes ? $request->user()->id : null,
                    'reviewed_at' => $passes ? $now : null,
                ],
            );

            // A save that carries no files at all leaves the ones on file
            // alone: that is a paper being ticked, not one being emptied. The
            // window that takes files away sends what is left, and an empty
            // list arrives as an empty array rather than as nothing.
            if (! $request->has('files')) {
                return [];
            }

            // What was there before, out of the way of what is replacing it.
            // Deleted as rows rather than one at a time, so the paths are
            // collected here and forgotten once the save has gone through.
            $replaced = $document->files()->pluck('path')->all();
            $document->files()->delete();

            foreach ($request->file('files') ?? [] as $file) {
                $document->files()->create([
                    'path' => DocumentStore::keep($file, $employee->id),
                    'name' => $file->getClientOriginalName(),
                    'size' => $file->getSize(),
                    'mime' => $file->getMimeType() ?? 'application/octet-stream',
                ]);
            }

            return $replaced;
        });

        // After the commit and not inside it: a file deleted inside a
        // transaction is gone even where the rows roll back, and a card would
        // then name a scan that is no longer on the disk.
        foreach ($replaced as $path) {
            DocumentStore::forget($path);
        }

        return back();
    }

    /** Taken off the card altogether: the paper goes back to being one nobody has handed in. */
    public function destroy(Request $request, User $employee, EmployeeDocument $document): RedirectResponse
    {
        $this->belongsTo($employee, $document);
        $this->mayAttach($request, $employee);

        // Unticking one kept on paper is the office saying it no longer holds
        // the original, which is the same sentence as the tick and belongs to
        // the same hands.
        if ($document->type->on_paper) {
            $this->mayPass($request);
        }

        $paths = $document->files()->pluck('path')->all();

        $document->delete();

        foreach ($paths as $path) {
            DocumentStore::forget($path);
        }

        return back();
    }

    /** Looked at and accepted. */
    public function approve(Request $request, User $employee, EmployeeDocument $document): RedirectResponse
    {
        $this->belongsTo($employee, $document);
        $this->mayPass($request);

        $document->update([
            'status' => EmployeeDocument::APPROVED,
            // The reason it was once turned down is not a note about the
            // document that was accepted in the end.
            'comment' => null,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        return back();
    }

    /**
     * Sent back, with the reason.
     *
     * The reason is asked for and not offered: a document turned down without
     * one is a document somebody hands in again unchanged, and the office then
     * turns it down a second time for a reason it still has not given.
     */
    public function reject(Request $request, User $employee, EmployeeDocument $document): RedirectResponse
    {
        $this->belongsTo($employee, $document);
        $this->mayPass($request);

        $data = $request->validate([
            'comment' => ['required', 'string', 'max:500'],
        ], attributes: ['comment' => 'причина']);

        $document->update([
            'status' => EmployeeDocument::REJECTED,
            'comment' => $data['comment'],
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        return back();
    }

    /**
     * One file of one document, handed to somebody who may read this card's
     * documents at all.
     *
     * The route carries no gate of its own, as the diplomas' does not: the
     * gates beside it are about attaching, and reading is the other question —
     * the same one the block of the card is drawn under.
     */
    public function file(Request $request, User $employee, EmployeeDocument $document, EmployeeDocumentFile $file): BinaryFileResponse
    {
        $this->belongsTo($employee, $document);

        // The row is the whole index of what may be read: nothing here is
        // assembled out of what somebody typed, so there is no path to climb
        // out of, and a file since replaced stops being answered the moment its
        // row is gone.
        abort_unless($file->employee_document_id === $document->id, 404);
        abort_unless(in_array('documents', EmployeeFields::visibleTo($request->user(), $employee), true), 403);
        abort_unless(DocumentStore::disk()->exists($file->path), 404);

        return DocumentStore::answer($request, $file->path, $file->name);
    }

    /** Whether this viewer is one of the people who pass documents. */
    private function passes(Request $request): bool
    {
        return $request->user()->can('employees.documents.review');
    }

    /** Attaching is changing the card, under the block the documents are read in. */
    private function mayAttach(Request $request, User $employee): void
    {
        abort_unless(in_array('documents', EmployeeFields::editableBy($request->user(), $employee), true), 403);
    }

    private function mayPass(Request $request): void
    {
        abort_unless($this->passes($request), 403);
    }

    /**
     * The document id travels beside an employee id, so the two must agree:
     * otherwise one person's address would reach another person's papers.
     */
    private function belongsTo(User $employee, EmployeeDocument $document): void
    {
        abort_unless($document->user_id === $employee->id, 404);
    }
}
