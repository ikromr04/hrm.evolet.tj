<?php

namespace App\Support;

use Illuminate\Auth\Events\Lockout;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * A limit on how often one thing may be tried, said in the form that tried it.
 *
 * The sign-in page has counted attempts since it was written. Four forms beside
 * it take a guess at something too and counted nothing at all: the address a
 * reset letter is asked for, the token in a reset link, and the password of
 * whoever is already signed in — twice, once to confirm it and once to change
 * it. Unlimited means a thousand tries a minute, which is the difference
 * between a password that has to be known and one that has to be waited for.
 *
 * The `throttle` middleware would stop that and would say so by answering 429 —
 * the framework's own screen, and inside an Inertia visit not a screen at all
 * but a modal with a refusal in it, over a form that still looks ready to be
 * sent again. So a limit here is a validation error like any other: under the
 * field the form shows, in a sentence saying how long to wait, on the page the
 * person is already looking at.
 */
final class GuessLimit
{
    /**
     * What each act allows: how many attempts, within how many minutes, the
     * field the refusal is shown under, and the sentence it is said in.
     *
     * Signing in is what people get wrong honestly and often, so it keeps the
     * five a minute it always had. The two forms that ask a signed-in person for
     * their own password are sign-ins in everything but name and get the same. A
     * reset token is sixty random characters and nobody is going to guess one,
     * but the form carrying it carries an address too, and five in ten minutes
     * costs a person following their own link nothing.
     *
     * A letter is the rarest of these and the only one that costs the company
     * something — it goes through the host's mail — so three in ten minutes for
     * one address from one place, which is tighter than the one a minute
     * Laravel's own broker allows (`passwords.users.throttle`) and is still more
     * letters than anybody who has forgotten their password needs.
     *
     * And one cap over all of them together, because the one above cannot see
     * what it is not shown: three letters per address is two hundred and thirty
     * letters once somebody walks a list of seventy-seven colleagues, and the
     * limit counted by address finds nothing wrong with a single one of them.
     * So the letters are also counted without the address — twenty an hour,
     * which a company this size never approaches honestly and a list cannot be
     * walked within.
     *
     * @var array<string, array{int, int, string, string, self::BY_*}>
     */
    private const ACTS = [
        'login' => [5, 1, 'email', 'auth.throttle', self::BY_WHO],
        'password.letter' => [3, 10, 'email', 'auth.throttle_letter', self::BY_WHO],
        'password.letters' => [20, 60, 'email', 'auth.throttle_letters', self::BY_PLACE],
        'password.reset' => [5, 10, 'email', 'auth.throttle_attempts', self::BY_WHO],
        'password.confirm' => [5, 1, 'password', 'auth.throttle_attempts', self::BY_WHO],
        'password.change' => [5, 1, 'current_password', 'auth.throttle_attempts', self::BY_WHO],
    ];

    /** Counted per person — the account signed in, or the address typed. */
    private const BY_WHO = 'who';

    /** Counted per place the request came from, whoever is asking. */
    private const BY_PLACE = 'place';

    private function __construct(
        private readonly Request $request,
        private readonly string $key,
        private readonly int $attempts,
        private readonly int $seconds,
        private readonly string $field,
        private readonly string $message,
    ) {}

    /**
     * The limit on one act by one person.
     *
     * By the address typed rather than by the account behind it — a form that
     * answers «такого аккаунта нет» has no account to count by — and by where
     * the request came from as well, so that one guesser cannot lock a colleague
     * out of their own sign-in page by getting their e-mail wrong on purpose.
     * Somebody already signed in is counted by their id instead: they are who
     * the limit is about, and the form never asks them for an address.
     *
     * One act is counted by where it came from and nothing else: the letters,
     * all of them together. It has to be generous, and the reason is worth
     * knowing. The request reaches PHP through the provider's proxy, and only
     * the scheme that proxy forwards is believed (trustProxies in
     * bootstrap/app.php, which explains why the forwarded address is not), so
     * $request->ip() may be one and the same address for everybody — in which
     * case this is not a limit per place at all but a limit on the whole form.
     * Twenty letters an hour is chosen to be safe under that reading rather
     * than under the flattering one: a company of this size asks for a handful
     * of reset letters in a week, so nobody honest meets it, while nobody can
     * send a list of addresses a letter each either. Should the host turn out to
     * forward the real client address, and be trusted to, this can be tightened
     * to what one place ought to need.
     *
     * The address is lower-cased and transliterated for the reason the sign-in
     * page has always done it: one address is one address however it was typed,
     * and what goes into a cache key had better be plain ASCII. The name of the
     * act is part of the key too, so that asking for a letter does not spend the
     * attempts that belong to signing in.
     */
    public static function on(string $act, Request $request): self
    {
        [$attempts, $minutes, $field, $message, $by] = self::ACTS[$act];

        $identity = $request->user()?->getAuthIdentifier() ?? $request->string('email');
        $who = $by === self::BY_PLACE
            ? ''
            : Str::transliterate(Str::lower((string) $identity)).'|';

        return new self(
            $request,
            $act.'|'.$who.$request->ip(),
            $attempts,
            $minutes * 60,
            $field,
            $message,
        );
    }

    /**
     * Refuse this attempt when there have already been too many.
     *
     * @throws ValidationException
     */
    public function ensure(): void
    {
        if (! RateLimiter::tooManyAttempts($this->key, $this->attempts)) {
            return;
        }

        // Laravel's own event for "this has been tried too often". Nothing
        // listens to it yet, and when something does — a line in a log, a word to
        // whoever watches the server — it should hear about a password being
        // guessed at in the settings as readily as at the front door.
        event(new Lockout($this->request));

        // At least a second of it. In the last second of the wait, availableIn()
        // answers nought, and «попробуйте ещё раз через 0 секунд» is an answer
        // to nothing.
        $seconds = max(1, RateLimiter::availableIn($this->key));

        throw ValidationException::withMessages([
            $this->field => __($this->message, [
                // The sign-in page has said «через :seconds секунд» since the day
                // it was written and goes on saying it; the sentences added with
                // this class take the wait the way a person would say it. Each
                // message uses the placeholder it names and ignores the rest.
                'seconds' => $seconds,
                'minutes' => (int) ceil($seconds / 60),
                'wait' => self::wait($seconds),
            ]),
        ]);
    }

    /**
     * One attempt spent. Usually one that came to nothing — a wrong password, a
     * wrong token — and never a form merely filled in badly, since a new
     * password that was too short is nobody's guess at anything. Where what is
     * being limited is the act itself rather than the guessing, as with a letter
     * going out, the caller spends one either way.
     */
    public function hit(): void
    {
        RateLimiter::hit($this->key, $this->seconds);
    }

    /**
     * It worked, so nothing is held against them any more.
     */
    public function clear(): void
    {
        RateLimiter::clear($this->key);
    }

    /**
     * How long is left, said in words rather than in arithmetic: ten minutes is
     * «через 10 минут», not «через 600 секунд», which is a sum to be done before
     * it is an answer.
     */
    private static function wait(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds.' '.self::ending($seconds, 'секунду', 'секунды', 'секунд');
        }

        $minutes = (int) ceil($seconds / 60);

        return $minutes.' '.self::ending($minutes, 'минуту', 'минуты', 'минут');
    }

    /**
     * The ending a Russian number takes after «через»: 1 минуту, 2 минуты,
     * 5 минут, and round again from 21 — except the teens, which all take the
     * last form however they end.
     */
    private static function ending(int $count, string $one, string $few, string $many): string
    {
        return match (true) {
            $count % 100 >= 11 && $count % 100 <= 14 => $many,
            $count % 10 === 1 => $one,
            $count % 10 >= 2 && $count % 10 <= 4 => $few,
            default => $many,
        };
    }
}
