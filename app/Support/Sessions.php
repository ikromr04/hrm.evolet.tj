<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Signing an account out of the browsers it is signed in on.
 *
 * A password is changed for one of two reasons: somebody wants a better one, or
 * somebody else knows the old one. The second is the one worth building for, and
 * a change used to do nothing about it — every browser already signed in stayed
 * signed in, and between the session lifetime and the remember cookie that is
 * weeks of it. The person changed their password, was told it was changed, and
 * whoever had got in went on reading their colleagues' cards.
 *
 * Two different things hold a browser's place, so both have to go. The session
 * is a row in the `sessions` table, which is the whole reason the sessions are
 * kept in the database and not in files nobody can pick an account out of. The
 * remember cookie is not a session at all: it carries a token from the `users`
 * table, so a browser holding one signs itself in again the moment its session
 * is gone, and only a new token in that column makes every copy of the cookie
 * worthless.
 *
 * That new token takes the current browser's own remember cookie with it. It is
 * worth saying out loud that this is meant: the browser that just changed the
 * password keeps the session it is sitting in, so nobody is thrown out of the
 * tab they are typing in, but they will be asked to sign in again once that
 * session runs out. The box is there to be ticked a second time, and a cookie
 * that lives for weeks is exactly what an intruder would be holding.
 */
final class Sessions
{
    /**
     * Every session this account has, including the one asking — for a password
     * that was reset rather than changed, where nobody is signed in here to keep
     * and the whole point is that somebody, somewhere, is.
     */
    public static function endAll(User $user): void
    {
        self::end($user, null);
    }

    /**
     * Every session but the one the request came in on.
     */
    public static function endOthers(User $user, string $keep): void
    {
        self::end($user, $keep);
    }

    private static function end(User $user, ?string $keep): void
    {
        // Written through the model so that the instance in hand — usually the
        // signed-in user themselves — carries the new token afterwards rather
        // than the one that has just stopped working.
        $user->forceFill(['remember_token' => Str::random(60)])->save();

        // The application runs on the database driver (config/session.php), which
        // is what makes one account's sessions findable at all. Under any other
        // driver there is no table to delete from: the file driver scatters them
        // under names that say nothing about whose they are, and the array driver
        // the test suite uses keeps nothing between requests to begin with. The
        // remember token above is cycled either way, so what is left behind is a
        // session that expires on its own.
        if (config('session.driver') !== 'database') {
            return;
        }

        // The sessions live on the application's own connection, which is the
        // one the callers wrap in a transaction. Should SESSION_CONNECTION ever
        // point them at another database, this delete would no longer be part of
        // that transaction — the rows would go even if the password write were
        // rolled back, which errs towards signing people out rather than towards
        // leaving them in, but is worth knowing before anybody sets it.
        DB::connection(config('session.connection'))
            ->table(config('session.table', 'sessions'))
            ->where('user_id', $user->getKey())
            ->when($keep !== null, fn ($query) => $query->whereNot('id', $keep))
            ->delete();
    }
}
