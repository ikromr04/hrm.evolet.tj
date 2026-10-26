<?php

namespace App\Http\Controllers\Directories;

use App\Http\Controllers\Controller;
use App\Models\EmploymentType;
use App\Support\Directories;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The kinds of work people are taken on for; a person is taken on under one of
 * them or under none.
 */
class EmploymentTypeController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('directories/employment-types', [
            // Counts match the staff list: working staff only. Reading a list and
            // keeping it are two rights, so the page says which one it is looking at.
            'canEdit' => Directories::canEdit($request->user(), 'employment_types'),
            'items' => EmploymentType::query()
                ->withCount(['users' => fn ($q) => $q->where('status', 'active')])
                ->orderBy('name')
                ->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        EmploymentType::create($this->validated($request));

        return back();
    }

    public function update(Request $request, EmploymentType $employmentType): RedirectResponse
    {
        $employmentType->update($this->validated($request, $employmentType));

        return back();
    }

    public function destroy(EmploymentType $employmentType): RedirectResponse
    {
        // The cards taken on under it stay; that line of them goes empty.
        $employmentType->delete();

        return back();
    }

    /**
     * @return array{name: string}
     */
    private function validated(Request $request, ?EmploymentType $type = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('employment_types', 'name')->ignore($type)],
        ], attributes: ['name' => 'название']);
    }
}
