<?php

namespace App\Http\Middleware;

use App\Support\Access;
use Illuminate\Foundation\Inspiring;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        [$message, $author] = str(Inspiring::quotes()->random())->explode('-');

        return array_merge(parent::share($request), [
            ...parent::share($request),
            'name' => config('app.name'),
            'quote' => ['message' => trim($message), 'author' => trim($author)],
            'auth' => [
                'user' => $request->user(),
                // Every right in the catalogue with a yes or a no, so a page can
                // hide what it must without asking a second question.
                'can' => $request->user()?->accessMap() ?? [],
                // The one account outside the list of rights. Pages ask this only
                // where something is not a right at all — the company dashboard,
                // which nobody is granted line by line.
                'sysadmin' => (bool) $request->user()?->hasRole(Access::SOLE_ROLE),
            ],
            // The number beside the bell, and nothing more: the list itself is
            // fetched when the bell is pressed. Counted on every visit, so the
            // badge is as fresh as the page under it.
            // What a form hands back to itself: the colleague the "new
            // employee" wizard has just created, or the unit of equipment the
            // add form filed while staying open for the next one.
            'flash' => [
                'employee' => $request->session()->get('employee'),
                'equipment' => $request->session()->get('equipment'),
                // A sentence for whoever lands back on a page after something went
                // sideways on the way — an expired session, for one.
                'notice' => $request->session()->get('notice'),
            ],
        ]);
    }
}
