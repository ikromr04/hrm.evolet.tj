<?php

namespace App\Http\Controllers\Directories;

use App\Http\Controllers\Controller;
use App\Models\DocumentType;
use App\Models\EmployeeDocumentFile;
use App\Support\Directories;
use App\Support\DocumentStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The papers the company asks everybody for: what is wanted, how it may be
 * handed in, and whether it is wanted from everybody.
 *
 * Kept by the same people who keep the rest of the reference lists, and read by
 * every card: «Документы» on a card is this list with one person's answers
 * against it.
 */
class DocumentTypeController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('directories/document-types', [
            // Reading a list and keeping it are two rights, so the page says
            // which one it is looking at.
            'canEdit' => Directories::canEdit($request->user(), 'document_types'),
            // Counted as the other lists count: working staff only, since a
            // paper handed in by somebody who has left says nothing about what
            // the office is still waiting for.
            'items' => DocumentType::query()
                ->withCount(['documents' => fn ($query) => $query->whereHas('employee', fn ($employee) => $employee->where('status', 'active'))])
                ->inOrder()
                ->get(['id', 'name', 'position', 'hint', 'on_paper', 'is_required']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        // At the end of the list, a hundred past the last of them: a new paper
        // is one the office has just thought of, and where it belongs in the
        // order is a question for whoever dragged the others into theirs.
        DocumentType::create([
            ...$this->validated($request),
            'position' => ((int) DocumentType::max('position')) + 100,
        ]);

        return back();
    }

    /**
     * The order the list is read in, as it has just been dragged into.
     *
     * The whole list arrives, not the one row that moved: what is being saved
     * is an order, and an order is the arrangement of all of it. Spaced a
     * hundred apart, so a paper added later has somewhere to go between any
     * two of them.
     */
    public function order(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'distinct'],
        ], attributes: ['ids' => 'порядок документов', 'ids.*' => 'документ']);

        $ids = array_map('intval', $data['ids']);
        $known = DocumentType::query()->pluck('id');

        // Every paper once and no paper that is not on the list: a partial
        // arrangement would leave the rest where they were and read as an
        // order nobody asked for.
        if ($known->sort()->values()->all() !== collect($ids)->sort()->values()->all()) {
            throw ValidationException::withMessages(['ids' => 'Нужно перечислить все документы, каждый по одному разу.']);
        }

        DB::transaction(function () use ($ids) {
            foreach ($ids as $place => $id) {
                DocumentType::whereKey($id)->update(['position' => ($place + 1) * 100]);
            }
        });

        return back();
    }

    public function update(Request $request, DocumentType $documentType): RedirectResponse
    {
        $documentType->update($this->validated($request, $documentType));

        return back();
    }

    /**
     * A paper the company no longer asks for, with everything handed in against
     * it.
     *
     * The rows go with it by the database's own cascade, which is quick and
     * silent — too silent to be left to clean up after itself, since a cascade
     * raises no model event and the files would stay on the disk with nothing
     * left pointing at them. So the paths are read first and forgotten after
     * the rows are gone.
     */
    public function destroy(DocumentType $documentType): RedirectResponse
    {
        $paths = EmployeeDocumentFile::query()
            ->whereIn('employee_document_id', $documentType->documents()->select('id'))
            ->pluck('path');

        $documentType->delete();

        foreach ($paths as $path) {
            DocumentStore::forget($path);
        }

        return back();
    }

    /**
     * @return array{name: string, hint: ?string, on_paper: bool, is_required: bool}
     */
    private function validated(Request $request, ?DocumentType $type = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('document_types', 'name')->ignore($type)],
            'hint' => ['nullable', 'string', 'max:255'],
            // Whether the original is handed over rather than sent, in which
            // case the card holds a tick instead of a file.
            'on_paper' => ['required', 'boolean'],
            'is_required' => ['required', 'boolean'],
        ], attributes: [
            'name' => 'название',
            'hint' => 'подсказка',
            'on_paper' => 'хранение',
            'is_required' => 'обязательность',
        ]);
    }
}
