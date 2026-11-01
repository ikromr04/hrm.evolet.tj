<?php

namespace App\Http\Middleware;

use App\Support\Access;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * The site, for the two roles it is not finished for yet.
 *
 * A head of department and a specialist have a card here, and a company has
 * every reason to show them theirs — but what they should see of it, and of
 * anybody else's, has not been decided. Until it is, they are not shown a
 * half-built version and left to guess which half is which: every address
 * answers with one page that says the work is under way.
 *
 * Signing in still works, and so does signing out, which is the whole of what
 * they can do. The page carries the person's own name, because somebody who
 * has just typed a password is owed the confirmation that it was accepted.
 */
class EnsureSiteIsOpen
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! Access::closed($user)) {
            return $next($request);
        }

        // Leaving is the one way out of here, so it stays open. Everything else —
        // the sign-in pages included, since the person is already signed in —
        // arrives at the same screen.
        if ($request->routeIs('logout')) {
            return $next($request);
        }

        // An ordinary page and not a refusal: there is nothing wrong with the
        // address or with the person, and an error status would have the client
        // show this inside a modal over a page they never got to see.
        return Inertia::render('closed', [
            'name' => trim($user->surname.' '.$user->name),
            'role' => $user->roles->first()?->title,
        ])->toResponse($request);
    }
}
