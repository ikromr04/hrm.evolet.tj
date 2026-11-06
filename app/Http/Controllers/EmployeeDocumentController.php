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
     * What one person hands in against one paper, attached or attached again.
     *
     * One row per person per paper: a second attempt replaces the first rather
     * than standing beside it. Nobody reads the history of a passport copy —
     * what is wanted is the copy that stands now and whether it will do.
     */
    public function store(Request $request, User $employee, DocumentType $documentType): RedirectResponse
    {
        $this->mayAttach($request, $employee);

        $request->validate([
            // As many as the paper asks for and no more than it takes — and
            // where it names no number that is too many, there is no «no more».
            'files' => array_filter([
                'required',
                'array',
                'min:'.$documentType->min_files,
                $documentType->max_files === null ? null : 'max:'.$documentType->max_files,
            ]),
            // A photograph is an image and never a PDF, so a paper the office
            // will only take photographed takes images alone. Where files are
            // allowed at all, a scan is the usual thing and arrives as either.
            'files.*' => ['bail', 'file', 'mimes:'.$this->kinds($documentType), 'max:10240'],
        ], [
            'files.min' => "Нужно приложить не меньше {$documentType->min_files} файлов.",
            'files.max' => "Можно приложить не больше {$documentType->max_files} файлов.",
            'files.*.mimes' => $documentType->accepts_files
                ? 'Файл должен быть PDF или изображением.'
                : 'Этот документ прикладывают фотографией: нужен файл изображения.',
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

            // What was there before, out of the way of what is replacing it.
            // Deleted as rows rather than one at a time, so the paths are
            // collected here and forgotten once the save has gone through.
            $replaced = $document->files()->pluck('path')->all();
            $document->files()->delete();

            foreach ($request->file('files') as $file) {
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

    /** What a paper will take, as a list of extensions for the rules. */
    private function kinds(DocumentType $type): string
    {
        return $type->accepts_files ? 'pdf,jpg,jpeg,png,webp' : 'jpg,jpeg,png,webp';
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
