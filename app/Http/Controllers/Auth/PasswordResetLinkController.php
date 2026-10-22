<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
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

        $status = Password::sendResetLink($request->only('email'));

        if ($status === Password::RESET_LINK_SENT) {
            return back()->with('status', __($status));
        }

        // «Не удалось найти пользователя…» or «Подождите перед повторной
        // попыткой», each said under the field it is about.
        throw ValidationException::withMessages(['email' => __($status)]);
    }
}
