<?php

namespace App\Http\Controllers\Directories;

use App\Http\Controllers\Controller;
use App\Models\Citizenship;
use App\Support\Directories;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Countries an employee may be a citizen of; one may hold several.
 */
class CitizenshipController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('directories/citizenships', [
            // Counts match the employee list the number links to: working staff only.
            // Reading a list and keeping it are two rights, so the page says
            // which one it is looking at.
            'canEdit' => Directories::canEdit($request->user(), 'citizenships'),
            'items' => Citizenship::query()->withCount(['users' => fn ($q) => $q->where('status', 'active')])->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Citizenship::create($this->validated($request));

        return back();
    }

    public function update(Request $request, Citizenship $citizenship): RedirectResponse
    {
        $citizenship->update($this->validated($request, $citizenship));

        return back();
    }

    public function destroy(Citizenship $citizenship): RedirectResponse
    {
        // Employees keep their other citizenships; only this one goes.
        $citizenship->delete();

        return back();
    }

    /**
     * @return array{name: string}
     */
    private function validated(Request $request, ?Citizenship $citizenship = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('citizenships', 'name')->ignore($citizenship)],
        ], attributes: ['name' => 'название']);
    }
}
