<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «Уведомления» — what the application has said to this one person, and
 * nothing it has said to anybody else.
 *
 * Every query here starts at `$request->user()->notifications()`, and that is
 * the whole of the access rule for this section: a note belongs to the account
 * it was addressed to, there is no right that grants somebody else's notes, and
 * a wrong id is not an error but simply a row this relation does not hold. No
 * check is written anywhere else, because there is nowhere else to write one.
 *
 * The page is told what each note says and nothing about what sent it. A note
 * carries its own heading, its own sentence and its own address, written by the
 * notification class when it was filed, so adding a second kind of note means
 * adding a class and touching nothing here.
 */
class NotificationController extends Controller
{
    /**
     * How many notes the page is given.
     *
     * Not paginated: this is a list somebody skims from the top, and the
     * interesting end of it is always the newest. Fifty is a few screens of
     * reading and still one small query, and anything older than fifty notes is
     * about a request that was dealt with weeks ago. If the section ever grows
     * a second and a third kind that arrive daily, this is the line that turns
     * into a paginator — the shape the page is handed would not change.
     */
    private const SHOWN = 50;

    public function index(Request $request): Response
    {
        // The relation already comes newest first, which is the order this list
        // is read in; the cap is applied to that order, so the fifty kept are
        // the fifty latest.
        $notifications = $request->user()->notifications()->limit(self::SHOWN)->get();

        return Inertia::render('notifications', [
            'items' => $notifications->map(fn (DatabaseNotification $note) => [
                'id' => $note->id,
                'kind' => $note->data['kind'] ?? null,
                'title' => $note->data['title'] ?? '',
                'body' => $note->data['body'] ?? '',
                // A note is normally something to open, but it does not have to
                // be: a kind that only announces something would carry no
                // address, and the row then marks itself read and stays put.
                'url' => $note->data['url'] ?? null,
                'read' => $note->read_at !== null,
                'at' => $note->created_at->toIso8601String(),
            ])->all(),
            // Counted over everything rather than over the fifty above: the
            // figure answers «сколько осталось», and the page is not the whole
            // of the list.
            'unread' => $request->user()->unreadNotifications()->count(),
        ]);
    }

    /**
     * One note read, because the row that carries it was pressed.
     *
     * The row follows its own address straight afterwards, so this answers with
     * `back()` and not with the list: the browser is on its way elsewhere, and
     * what this needs to leave behind is a correct bell on the page it lands
     * on.
     *
     * Already-read notes are left alone rather than touched again, so `read_at`
     * keeps the moment it was first read.
     */
    public function read(Request $request): RedirectResponse
    {
        $id = $request->validate(['id' => ['required', 'string']])['id'];

        $request->user()->notifications()->whereKey($id)->whereNull('read_at')->update(['read_at' => now()]);

        return back();
    }

    /**
     * The whole list read at once, for whoever has come back to a bell with a
     * dozen on it and knows what all of them are about.
     *
     * One statement rather than a row at a time: nothing is being read, only
     * marked, and the moment they were all cleared is the same moment.
     */
    public function readAll(Request $request): RedirectResponse
    {
        $request->user()->notifications()->whereNull('read_at')->update(['read_at' => now()]);

        return back();
    }
}
