<?php

namespace App\Http\Controllers\Directories;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Support\Directories;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The places the company works from; a person works at one of them or at none.
 */
class BranchController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('directories/branches', [
            // Counts match the staff list: working staff only. Reading a list and
            // keeping it are two rights, so the page says which one it is looking at.
            'canEdit' => Directories::canEdit($request->user(), 'branches'),
            'items' => Branch::query()
                ->withCount(['users' => fn ($q) => $q->where('status', 'active')])
                ->orderBy('name')
                ->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Branch::create($this->validated($request));

        return back();
    }

    public function update(Request $request, Branch $branch): RedirectResponse
    {
        $branch->update($this->validated($request, $branch));

        return back();
    }

    public function destroy(Branch $branch): RedirectResponse
    {
        // The cards that named it stay; that line of them goes empty.
        $branch->delete();

        return back();
    }

    /**
     * @return array{name: string}
     */
    private function validated(Request $request, ?Branch $branch = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('branches', 'name')->ignore($branch)],
        ], attributes: ['name' => 'название']);
    }
}
