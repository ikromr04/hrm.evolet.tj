<?php

use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Spatie\Permission\Middleware\RoleMiddleware;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Deciding on access belongs to the system administrator, and that is a
        // role rather than a right: an administrator holds every right there is
        // and still may not hand them out. So those routes name the role.
        $middleware->alias(['role' => RoleMiddleware::class]);

        // On shared hosting HTTPS usually ends at a proxy in front of PHP, and
        // what reaches us is plain HTTP with a header saying how it was asked
        // for. Without believing that header the application takes itself for an
        // http:// site: the signed link that confirms a new e-mail address is
        // then checked against the wrong address and refused.
        //
        // Only the scheme is believed, and from anybody, since which proxy stands
        // in front is the host's business. The forwarded address is not: taken
        // from anybody, it would let a visitor name their own IP and walk past
        // the limit on sign-in attempts.
        $middleware->trustProxies(at: '*', headers: Request::HEADER_X_FORWARDED_PROTO);

        $middleware->web(append: [
            EnsureUserIsActive::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // A wrong address and a refusal are answered by pages of the application
        // rather than by the framework's own screens: signed in, they arrive inside
        // the usual shell, so whoever followed a stale link still has the sidebar
        // and the search to hand.
        $exceptions->respond(function (Response $response, Throwable $exception, Request $request) {
            $status = $response->getStatusCode();

            // A failure of our own, and the "back in a few minutes" of an update,
            // get a page too — but only where nobody is debugging. With APP_DEBUG
            // on, the framework's screen carries the trace, which is the whole
            // point of it; in production it would be a bare "500 | Server Error",
            // and inside an Inertia visit that lands in a modal over the page.
            $pages = config('app.debug') ? [403, 404] : [403, 404, 500, 503];

            // The session ran out while a form sat open. Nothing is wrong with the
            // page, only with the token it carried — so send them back to it with
            // a fresh one and a sentence, rather than the framework's bare screen.
            //
            // A sentence alone is not enough for a form sent through Inertia. The
            // page it lands on is an ordinary page with no errors, so Inertia calls
            // onSuccess — and every dialog here closes on onSuccess, taking what
            // was typed with it. So the answer carries an error as well, under a
            // key no field can be called: Inertia's share() hands the errors bag
            // to the page as it stands (see Middleware::resolveValidationErrors),
            // which is enough for onError instead, and the dialog stays open with
            // the data in it, ready for a second Save against the fresh token.
            //
            // 303 rather than the 302 of back(): the answer to an exception never
            // reaches Inertia's middleware, which is what otherwise turns a 302 on
            // a PUT into one, and a browser repeats a PUT to the address a 302
            // names instead of asking for the page.
            if ($status === 419 && ! $request->expectsJson()) {
                $notice = 'Сессия истекла, пока страница была открыта. Повторите действие ещё раз.';

                // Nothing was typed into a GET, and no dialog is waiting on its
                // answer; a reading request goes back the way it always has.
                if ($request->isMethodSafe()) {
                    return back()->with('notice', $notice);
                }

                return back()
                    ->with('notice', $notice)
                    ->withErrors(['__session' => $notice])
                    ->setStatusCode(303);
            }

            if (! in_array($status, $pages, true) || $request->expectsJson()) {
                return $response;
            }

            try {
                $page = Inertia::render("errors/{$status}")->toResponse($request)->setStatusCode($status);
            } catch (Throwable) {
                // Drawing the page takes the same things a page always takes — who
                // is signed in, for one. If what broke is the database, that breaks
                // as well, and an error raised while reporting an error would bury
                // the first. The plain answer is still an answer.
                return $response;
            }

            // `artisan down --retry=60` and `--refresh=15` speak through headers,
            // to browsers and to search engines alike; the page keeps them.
            foreach (['Retry-After', 'Refresh'] as $header) {
                if ($response->headers->has($header)) {
                    $page->headers->set($header, $response->headers->get($header));
                }
            }

            return $page;
        });
    })->create();
