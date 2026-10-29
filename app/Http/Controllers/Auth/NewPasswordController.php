<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\GuessLimit;
use App\Support\Sessions;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class NewPasswordController extends Controller
{
    /**
     * Show the password reset page.
     */
    public function create(Request $request): Response
    {
        return Inertia::render('auth/reset-password', [
            'email' => $request->email,
            'token' => $request->route('token'),
        ]);
    }

    /**
     * Handle an incoming new password request.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        // What the limit below needs in order to count anything, checked first
        // and cheaply.
        $request->validate([
            'token' => 'required',
            'email' => 'required|email',
        ]);

        // A token is sixty random characters and will not be guessed; the limit
        // is here because "will not be" is a thing to be sure of rather than to
        // assume, and because the form takes an address as well. Five in ten
        // minutes is nothing to somebody who followed their own link.
        $limit = GuessLimit::on('password.reset', $request);

        $limit->ensure();

        // And only now the new password, because in production the policy
        // includes ->uncompromised() — a request to api.pwnedpasswords.com. Were
        // that checked first, a thousand submissions with a junk token would be
        // refused a thousand times over and would still have made a thousand
        // calls out of the server on the way to being refused.
        $request->validate([
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        // Here we will attempt to reset the user's password. If it is successful we
        // will update the password on an actual user model and persist it to the
        // database. Otherwise we will parse the error and return the response.
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user) use ($request) {
                DB::transaction(function () use ($user, $request) {
                    $user->forceFill([
                        'password' => Hash::make($request->password),
                    ])->save();

                    // A reset is the very case this is for. Somebody who cannot
                    // get in the front door asks for a letter — and whoever
                    // could get in, with the old password or with a remember
                    // cookie kept from it, goes out in the same write. There is
                    // nothing to keep signed in on this browser: the page is
                    // behind the `guest` middleware, so the person doing this is
                    // not signed in anywhere yet.
                    //
                    // The remember token used to be cycled on the line above.
                    // Sessions does that now, so that a reset and a change in
                    // the settings end in the same state rather than in two
                    // states that merely look alike.
                    Sessions::endAll($user);
                });

                event(new PasswordReset($user));
            }
        );

        // If the password was successfully reset, we will redirect the user back to
        // the application's home authenticated view. If there is an error we can
        // redirect them back to where they came from with their error message.
        if ($status == Password::PasswordReset) {
            $limit->clear();

            return to_route('login')->with('status', __($status));
        }

        // A wrong token or an address the link was not sent to: the one thing
        // worth counting here, as against a new password that was simply too
        // short, which the rules above refused before any of this.
        $limit->hit();

        throw ValidationException::withMessages([
            'email' => [__($status)],
        ]);
    }
}
