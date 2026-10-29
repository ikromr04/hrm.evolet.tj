<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Support\GuessLimit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PasswordResetLinkController extends Controller
{
    /**
     * Show the password reset link request page.
     */
    public function create(Request $request): Response
    {
        return Inertia::render('auth/forgot-password', [
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Send the letter, and say plainly whether there was anybody to send it to.
     *
     * An address nobody works under is answered with «такого аккаунта нет»
     * rather than with the same reassurance a real one gets: the company asked
     * for the page to say which it was, so that somebody mistyping their own
     * address learns it here instead of waiting for a letter that is not
     * coming. The cost is that the form confirms whether an address belongs to
     * somebody here — it is an internal portal, and that was the trade made.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        // Unlike a password, a letter is counted whether it went anywhere or not
        // — and whether the broker sent it or refused it as too soon. What is
        // being limited here is not somebody's guessing but the letters
        // themselves: three for one address in ten minutes, so that nobody's
        // inbox can be filled from this form and the host's mail is not spent on
        // it. That this form also says plainly whether an address belongs to
        // anybody here is a trade the company made knowingly, explained above,
        // and a limit counted by address does nothing about it.
        // And counted a second time without the address, because the limit per
        // address cannot see a list being walked: seventy-seven colleagues are
        // seventy-seven first letters, each of them the first for its address.
        // Both are asked before either is spent, so a form refused by one does
        // not quietly spend the other's allowance.
        $limits = [GuessLimit::on('password.letter', $request), GuessLimit::on('password.letters', $request)];

        foreach ($limits as $limit) {
            $limit->ensure();
        }

        foreach ($limits as $limit) {
            $limit->hit();
        }

        $status = Password::sendResetLink($request->only('email'));

        if ($status === Password::RESET_LINK_SENT) {
            return back()->with('status', __($status));
        }

        // «Не удалось найти пользователя…» or «Подождите перед повторной
        // попыткой», each said under the field it is about.
        throw ValidationException::withMessages(['email' => __($status)]);
    }
}
