<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Support\GuessLimit;
use App\Support\Sessions;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PasswordController extends Controller
{
    /**
     * Show the user's password settings page.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/password', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Update the user's password.
     *
     * @throws ValidationException
     */
    public function update(Request $request): RedirectResponse
    {
        // `current_password` is a password being tried, whatever the form is
        // called, and this is the form somebody who found an unlocked computer
        // would sit down in front of. Counted by account, as the confirmation
        // page is.
        $limit = GuessLimit::on('password.change', $request);

        $limit->ensure();

        try {
            $validated = $request->validate([
                'current_password' => ['required', 'current_password'],
                'password' => ['required', Password::defaults(), 'confirmed'],
            ]);
        } catch (ValidationException $e) {
            // Only a password that was typed and was wrong is an attempt at
            // guessing one. A new password that was too short, or typed
            // differently the second time, is a form to be filled in again — and
            // so is a current password left empty, which is the easy mistake to
            // make here, since the page clears that field after every refusal.
            // Hence the failed rule rather than the field: `required` and
            // `current_password` both put their complaint under the same name.
            if (isset($e->validator->failed()['current_password']['CurrentPassword'])) {
                $limit->hit();
            }

            throw $e;
        }

        $limit->clear();

        DB::transaction(function () use ($request, $validated) {
            $user = $request->user();

            $user->update([
                'password' => Hash::make($validated['password']),
            ]);

            // The reason anybody changes a password in a hurry: somebody else
            // knows the old one. Every other browser signed in as them goes out
            // with the same write — this one stays, since the person is in the
            // middle of using it.
            Sessions::endOthers($user, $request->session()->getId());
        });

        return back();
    }
}
