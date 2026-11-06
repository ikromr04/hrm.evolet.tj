<?php

namespace App\Http\Controllers\Directories;

use App\Http\Controllers\Controller;
use App\Models\DocumentType;
use App\Models\EmployeeDocumentFile;
use App\Support\Directories;
use App\Support\DocumentStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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
                ->orderBy('name')
                ->get(['id', 'name', 'hint', 'min_files', 'max_files', 'accepts_files', 'accepts_camera', 'is_required']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        DocumentType::create($this->validated($request));

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
     * @return array{name: string, hint: ?string, min_files: int, max_files: ?int, accepts_files: bool, accepts_camera: bool, is_required: bool}
     */
    private function validated(Request $request, ?DocumentType $type = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('document_types', 'name')->ignore($type)],
            'hint' => ['nullable', 'string', 'max:255'],
            // How few will do, and how many are too many. Twenty is not a
            // rule anybody asked for; it is the point past which a list of
            // files stops being a document and starts being an archive, and it
            // bounds the numbers somebody may type rather than the answer: left
            // empty, the second of them means there is no such number at all.
            'min_files' => ['required', 'integer', 'min:1', 'max:20'],
            'max_files' => ['nullable', 'integer', 'min:1', 'max:20', 'gte:min_files'],
            // A paper that can arrive neither way cannot arrive at all, so one
            // of the two is asked for. Said as a rule on the first of them,
            // which is where the complaint then reads, and written against both
            // the figure a form sends and the word a JSON body does.
            'accepts_files' => ['required', 'boolean', 'accepted_if:accepts_camera,0,false'],
            'accepts_camera' => ['required', 'boolean'],
            'is_required' => ['required', 'boolean'],
        ], [
            'accepts_files.accepted_if' => 'Выберите хотя бы один способ: файл или фотография.',
            'max_files.gte' => 'Второе число не может быть меньше первого.',
        ], [
            'name' => 'название',
            'hint' => 'подсказка',
            'min_files' => 'количество файлов',
            'max_files' => 'количество файлов',
            'accepts_files' => 'способ',
            'accepts_camera' => 'способ',
            'is_required' => 'обязательность',
        ]);
    }
}
