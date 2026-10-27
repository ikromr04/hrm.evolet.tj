<?php

namespace App\Http\Controllers;

use App\Models\TableView;
use App\Support\Access;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * How a table opens for everybody who has not arranged it themselves.
 *
 * Arranging a table is nobody's business but their own, and every viewer keeps
 * what they arranged in their own browser. What a table looks like before that
 * — the columns it shows the first time it is opened, their order and their
 * width — is the company's business, and the one account outside the list of
 * rights is the one that decides it.
 */
class TableViewController extends Controller
{
    public function __invoke(Request $request, string $key): RedirectResponse
    {
        // Not a right: there is no line on the access page for this, and there
        // is not meant to be one. The single system administrator sets how the
        // company reads its tables, and nobody else does.
        abort_unless($request->user()->hasRole(Access::SOLE_ROLE), 403);

        $data = $request->validate([
            // The same thing a kept view holds: what is hidden, what is held to
            // an edge, in what order and how wide.
            'view' => ['required', 'array'],
            'view.hidden' => ['present', 'array'],
            'view.hidden.*' => ['string', 'max:64'],
            'view.pinned' => ['present', 'array'],
            'view.pinned.left' => ['present', 'array'],
            'view.pinned.left.*' => ['string', 'max:64'],
            'view.pinned.right' => ['present', 'array'],
            'view.pinned.right.*' => ['string', 'max:64'],
            'view.order' => ['nullable', 'array'],
            'view.order.*' => ['string', 'max:64'],
            'view.widths' => ['nullable', 'array'],
            'view.widths.*' => ['integer', 'min:96', 'max:1600'],
            // How the list is sorted and narrowed, as the address asks for it,
            // or — for a list that narrows itself inside the page — as the page
            // writes it down.
            'query' => ['nullable', 'string', 'max:2000'],
            'state' => ['nullable', 'array'],
        ], attributes: ['view' => 'вид таблицы']);

        TableView::updateOrCreate(
            ['key' => $key],
            [
                'view' => [
                    'view' => $data['view'],
                    'query' => $data['query'] ?? '',
                    'state' => $data['state'] ?? null,
                ],
                'updated_by' => $request->user()->id,
            ],
        );

        return back();
    }

    /**
     * Back to the arrangement the page itself draws: the row goes, and the
     * table opens the way it was written to open. Nobody's own views are
     * touched — those are theirs and were never this to give or take.
     */
    public function destroy(Request $request, string $key): RedirectResponse
    {
        abort_unless($request->user()->hasRole(Access::SOLE_ROLE), 403);

        TableView::query()->where('key', $key)->delete();

        return back();
    }
}
