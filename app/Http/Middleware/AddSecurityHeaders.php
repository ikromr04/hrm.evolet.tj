<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * What the browser is told to refuse.
 *
 * Every rule here is a second lock: the first one is the code itself, which
 * cleans what it stores and escapes what it draws. Locks of this kind are worth
 * having precisely because the first one is written by hand and hands slip —
 * a page that accidentally draws a name as markup is a different thing on a
 * site whose browser has been told to run no script it was not handed.
 *
 * The policy of scripts is the one that costs something to get right, so it is
 * reported rather than enforced until somebody has watched a working browser
 * say nothing against it: a page served by a policy it quietly breaks is worse
 * than one served without it. Flip CSP_ENFORCE on once the console is clean.
 */
class AddSecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        // One number per response, handed to the two places that write a script
        // tag into the page — Ziggy's route list and Vite's own tags — so that
        // «only scripts this page handed out» can be said at all.
        $nonce = Vite::useCspNonce();

        $response = $next($request);

        // The file is what it says it is: a photograph uploaded as a photograph
        // is not to be read as something executable because its bytes look it.
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        // Nobody frames this: a form that fires somebody or deletes a card must
        // not be pressed through a page somebody else drew around it.
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'same-origin');
        // None of these are asked for anywhere in the app, so none are allowed.
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()');

        // Only where it can be honoured: over https, and on a server that is
        // the real one. Said on a developer's machine it would pin the browser
        // to https for a host that has no certificate.
        if ($request->secure() && app()->isProduction()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        // The dev server loads modules and a socket from another port, which no
        // policy written for the real site allows; locally there is nothing to
        // defend anyway.
        if (! app()->isProduction()) {
            return $response;
        }

        $policy = implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}'",
            // Attribute styles: a progress bar is a width written on the element.
            "style-src 'self' 'unsafe-inline'",
            // data: for the icons drawn inline, blob: for a photograph being
            // previewed before it is sent.
            "img-src 'self' data: blob:",
            "font-src 'self' data:",
            "connect-src 'self'",
            "form-action 'self'",
            "base-uri 'self'",
            "object-src 'none'",
            "frame-ancestors 'none'",
        ]);

        $response->headers->set(
            config('app.csp_enforce') ? 'Content-Security-Policy' : 'Content-Security-Policy-Report-Only',
            $policy,
        );

        return $response;
    }
}
