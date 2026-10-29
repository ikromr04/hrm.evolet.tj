<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Support\GuessLimit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ConfirmablePasswordController extends Controller
{
    /**
     * Show the confirm password page.
     */
    public function show(): Response
    {
        return Inertia::render('auth/confirm-password');
    }

    /**
     * Confirm the user's password.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        // Asked for before anything is counted, so that an empty field — which
        // this form has always taken as a wrong password, null and all, straight
        // into password_verify() — costs nobody one of their attempts.
        $request->validate([
            'password' => ['required', 'string'],
        ]);

        // This form asks for a password and takes as many answers as it is
        // given, which is a sign-in page by another name — so it is counted like
        // one. By account rather than by address: whoever is answering is
        // already signed in, and it is their password being tried.
        $limit = GuessLimit::on('password.confirm', $request);

        $limit->ensure();

        if (! Auth::guard('web')->validate([
            'email' => $request->user()->email,
            'password' => $request->password,
        ])) {
            $limit->hit();

            throw ValidationException::withMessages([
                'password' => __('auth.password'),
            ]);
        }

        $limit->clear();

        $request->session()->put('auth.password_confirmed_at', time());

        return redirect()->intended(route('dashboard', absolute: false));
    }
}
