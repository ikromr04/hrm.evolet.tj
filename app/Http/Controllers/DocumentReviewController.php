<?php

namespace App\Http\Controllers;

use App\Models\EmployeeDocument;
use App\Models\EmployeeDocumentFile;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Everything handed in and not yet looked at, across every card.
 *
 * A document waiting to be passed is work for the office rather than news about
 * one person, and work for the office has to be in one place: a reviewer who
 * had to open cards one at a time to find what is waiting would find it by
 * accident or not at all.
 *
 * Oldest first, since that is the order a queue is worked through, and only
 * people who still work here: what somebody handed in on their way out is not
 * something the office is waiting on.
 */
class DocumentReviewController extends Controller
{
    public function index(Request $request): Response
    {
        $waiting = EmployeeDocument::query()
            ->where('status', EmployeeDocument::PENDING)
            ->whereHas('employee', fn ($query) => $query->where('status', 'active'))
            ->with(['employee:id,surname,name,patronymic', 'type:id,name,hint', 'files', 'submitter:id,surname,name'])
            ->orderBy('submitted_at')
            ->get();

        return Inertia::render('documents', [
            'items' => $waiting->map(fn (EmployeeDocument $document) => [
                'id' => $document->id,
                'employee' => [
                    'id' => $document->employee->id,
                    'name' => trim("{$document->employee->surname} {$document->employee->name}"),
                ],
                'name' => $document->type?->name ?? 'Документ',
                'hint' => $document->type?->hint,
                'submitted_at' => $document->submitted_at?->toDateString(),
                'submitted_by' => $this->shortName($document->submitter),
                'files' => $document->files->map(fn (EmployeeDocumentFile $file) => [
                    'id' => $file->id,
                    'name' => $file->name,
                    'url' => route('employees.documents.file', [$document->user_id, $document->id, $file->id]),
                ])->all(),
            ])->all(),
        ]);
    }

    /** «Рахимов И.» — who handed it in. */
    private function shortName(?User $person): ?string
    {
        if ($person === null) {
            return null;
        }

        $initial = mb_substr($person->name ?? '', 0, 1);

        return trim($person->surname.($initial === '' ? '' : " {$initial}."));
    }
}
