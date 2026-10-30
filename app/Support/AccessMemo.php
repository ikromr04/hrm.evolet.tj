<?php

namespace App\Support;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use WeakMap;

/**
 * Answers about rights that hold still for the length of one request.
 *
 * Asking whether somebody may read one line of a card costs a Spatie lookup —
 * measured at about a quarter of a millisecond on the development machine — and
 * the pages ask in loops. The company chart asked "may I see this face?" twice
 * for every person in it, and each of those answers walked the whole catalogue
 * of fields rather than the one line it wanted, so drawing seventy-seven people
 * spent 3,514 permission checks and 1.3 seconds of PHP to produce thirty
 * kilobytes of JSON. The staff list, the fleet and the equipment journal all did
 * the same thing a row at a time.
 *
 * None of those answers could have differed from one another. What a viewer may
 * read on a card turns on the viewer, and on whose card it is — their own or
 * somebody else's — and on nothing else; the identity of the colleague beyond
 * that is irrelevant. So the same question asked about fifty colleagues has two
 * possible answers, not fifty, and this files them.
 *
 * A note is filed against two things at once, and both are what make it safe to
 * file at all: the request that asked, and the very object asked about. It dies
 * when the request does, so a right handed out between two requests is read
 * afresh by the second one — the suite grants a right between two requests of
 * one test and expects exactly that, and so would a person using the
 * application. And it is not reused for a second instance of the same
 * colleague, so a row re-read from the database answers out of the database
 * rather than out of a note taken before it was. A static array keyed on the id
 * alone would have been faster still and wrong on both counts.
 *
 * What remains is narrow and deliberate: inside one request, one object, the
 * answer is taken as settled. No route writes a right and then reads one back —
 * they all redirect — and the few that write call forget() regardless.
 */
final class AccessMemo
{
    /**
     * The answers, filed under the request that asked for them.
     *
     * A weak map rather than an array keyed by something about the request: the
     * entry is reachable only through the request object itself, so it is
     * collected with it and can never be handed to a later request that happens
     * to look similar.
     *
     * @var WeakMap<Request, array<string, array{subject: User, value: mixed}>>|null
     */
    private static ?WeakMap $answers = null;

    /**
     * What this question answers for this person, worked out once per request.
     *
     * The subject is the person the question is about — whoever is asking, not
     * whoever they are asking about — because that is what the answer turns on.
     * Anything else it turns on belongs in $question: the scope of a card's
     * fields is part of the question, not part of the person.
     */
    public static function of(User $subject, string $question, Closure $answer): mixed
    {
        $request = self::request();

        // No request to hang the answer on — a console command, a queued job —
        // so nothing is filed. Those do not ask in loops, and a long-lived
        // process is exactly where a stale answer would do harm.
        if ($request === null) {
            return $answer();
        }

        // Filed against the object and not merely against the person. Two
        // instances of one colleague are entitled to disagree about their
        // rights — one of them read before a grant and the other after it —
        // and Eloquent already treats them as two, since the overrides and the
        // roles behind an answer are cached on the instance that read them. A
        // key naming only the id would have made this one helper the exception,
        // answering out of a note taken before the grant while can() next door
        // answered after it.
        $key = $question.'#'.spl_object_id($subject);

        self::$answers ??= new WeakMap;
        $notes = self::$answers[$request] ?? [];

        if (isset($notes[$key])) {
            return $notes[$key]['value'];
        }

        // The subject is kept beside its answer so that the object cannot be
        // collected while the note lives: PHP hands a collected object's id
        // straight to the next one, and a reused id would hand over somebody
        // else's rights.
        $notes[$key] = ['subject' => $subject, 'value' => $answer()];
        self::$answers[$request] = $notes;

        return $notes[$key]['value'];
    }

    /**
     * Throw this request's answers away.
     *
     * For the one kind of request that could make them wrong: one that hands out
     * or takes back a right and then carries on reading. Every such route
     * redirects today and reads nothing after the write, so this is insurance
     * rather than a repair — but rights are the wrong thing to be clever about,
     * and calling it costs nothing.
     */
    public static function forget(): void
    {
        $request = self::request();

        if ($request !== null) {
            self::$answers?->offsetUnset($request);
        }
    }

    private static function request(): ?Request
    {
        $app = app();

        if (! $app->bound('request')) {
            return null;
        }

        $request = $app->make('request');

        return $request instanceof Request ? $request : null;
    }
}
