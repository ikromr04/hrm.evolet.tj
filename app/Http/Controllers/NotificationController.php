<?php

namespace App\Http\Controllers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

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
 * carries its own heading, its own sentence, its own sender and its own
 * address, written by the notification class when it was filed, so adding a
 * second kind of note means adding a class and touching nothing here.
 *
 * The list is an ordinary table of this application now, the same machinery as
 * «Сотрудники» and «Инвентаризация»: tabs, a search box, saved filter sets, a
 * sort and a funnel per column, paging. Which is why so much of what follows is
 * about the address bar — every one of those is a key in the query string and
 * every one is handed straight back as a prop, so the page can redraw itself
 * from the address alone. That is what makes a filter set worth saving: the
 * link is the whole of the state.
 *
 * What makes this table unlike the others is that none of its columns is a
 * column. The heading, the sentence and the sender all live inside `data`,
 * which is one JSON string, so each is reached by its path — `data->title`,
 * `data->from->name`. And the trap underneath that is worth writing down
 * because it costs an afternoon to find: Laravel stores that JSON with every
 * Russian letter escaped to \uXXXX, so «Личный» matched against the `data`
 * column as a whole matches nothing at all, while the same word through a JSON
 * path matches, both databases unescaping as they extract. Nothing here ever
 * touches `data` whole.
 */
class NotificationController extends Controller
{
    /**
     * How many notes a page holds, and what it may be set to.
     *
     * The same four every list of this application offers, and the same first
     * one by default: a reader who has set a size on one page expects the next
     * to offer the same choice.
     */
    public const PER_PAGE_OPTIONS = [10, 25, 50, 100];

    /** The two lists there are: everything, and what has not been read yet. */
    private const FILTERS = ['all', 'unread'];

    /**
     * The columns that can be sorted by, each with what the database orders it
     * by.
     *
     * Three of the four are JSON paths rather than columns, and they are
     * written as paths and not as SQL on purpose: `data->from->name` is
     * compiled to `json_unquote(json_extract(...))` by the MySQL grammar the
     * developer runs against and to `json_extract(...)` by the SQLite one the
     * tests run against, each by itself. A hand-written expression would have
     * to pick a database.
     *
     * @var array<string, string>
     */
    private const SORTS = [
        'from' => 'data->from->name',
        'title' => 'data->title',
        'body' => 'data->body',
        'at' => 'created_at',
    ];

    /**
     * The character that makes the next one in a LIKE pattern mean itself.
     *
     * Not the backslash every manual uses. A backslash is an escape character
     * inside a MySQL string literal and an ordinary character inside SQLite's,
     * so the one clause `escape '\'` is a syntax error on one database and
     * correct on the other. An exclamation mark is nothing special to either.
     */
    private const LIKE_ESCAPE = '!';

    public function index(Request $request): Response
    {
        // Nothing read out of the address below is validated, and that is meant
        // for every key of it. These arrive from the address bar as often as
        // from the page — out of a saved filter set, out of a link pasted to a
        // colleague, out of a bookmark kept from before a column was renamed —
        // and a list is the answer to a question somebody got wrong. So a
        // mistyped sort key is answered with the newest notes first, and a page
        // size nobody offers with the first size that is offered, rather than
        // with a 422 that has no page to be shown on.
        $filter = $this->text($request->query('filter'));
        $filter = in_array($filter, self::FILTERS, true) ? $filter : 'all';

        $perPage = (int) $this->text($request->query('per_page'));
        $perPage = in_array($perPage, self::PER_PAGE_OPTIONS, true) ? $perPage : self::PER_PAGE_OPTIONS[0];

        $sort = $this->text($request->query('sort'));
        $sort = array_key_exists($sort, self::SORTS) ? $sort : 'at';

        // Newest first, which is what this list is opened for; a column asked
        // for by name reads down from A, as every table of this application
        // does.
        $direction = $this->text($request->query('direction'));
        $direction = in_array($direction, ['asc', 'desc'], true)
            ? $direction
            : ($sort === 'at' ? 'desc' : 'asc');

        $q = $this->text($request->query('q'));
        $filters = [
            // The two funnels answer with the text they offer rather than with
            // ids; `choices()` says why.
            'from' => $this->picked($request->query('from')),
            'title' => $this->picked($request->query('title')),
            'body' => $this->text($request->query('body')),
            'at_from' => $this->day($request->query('at_from')),
            'at_to' => $this->day($request->query('at_to')),
        ];

        $notes = $this->notes($request)
            ->when($filter === 'unread', fn (Builder $query) => $query->whereNull('read_at'));

        $this->search($notes, $q);
        $this->narrow($notes, $filters);

        // `notifications()` is declared with `->latest()`, so the relation
        // arrives already ordered newest first and an order merely added to it
        // would be a tie-break underneath that one — every column would still
        // sort by date. The order it came with is cleared first: the column the
        // reader pressed is the first thing in the ORDER BY or it is nothing.
        $notes->reorder()->orderBy(self::SORTS[$sort], $direction);

        // And a settled order underneath it, so that nothing moves between
        // pages while somebody is reading them: two hundred notes share a
        // heading, three notes filed in one second share a date, and rows the
        // database considers equal come back in whatever order suited it that
        // time.
        if ($sort !== 'at') {
            $notes->orderByDesc('created_at');
        }

        $page = $notes->orderBy('id')->paginate($perPage)->withQueryString();

        return Inertia::render('notifications', [
            'filter' => $filter,
            // Counted over everything: over every note rather than over the
            // page in front of the reader, and over every note rather than over
            // what the search box and the funnels have left of it. A tab is read
            // before it is opened, and «Непрочитанные 3» that turns out to hold
            // four is a figure nobody trusts again. How much of it is on this
            // page is what the paging under the rows is for.
            'counts' => [
                'all' => $this->notes($request)->count(),
                'unread' => $request->user()->unreadNotifications()->count(),
            ],
            'q' => $q,
            'sort' => ['key' => $sort, 'direction' => $direction],
            'sortable' => array_keys(self::SORTS),
            'filters' => $filters,
            'options' => [
                'from' => $this->choices($request, 'data->from->name'),
                'title' => $this->choices($request, 'data->title'),
            ],
            'perPage' => $perPage,
            'perPageOptions' => self::PER_PAGE_OPTIONS,
            'notes' => $page->through(fn (DatabaseNotification $note) => [
                'id' => $note->id,
                'kind' => $note->data['kind'] ?? null,
                'title' => $note->data['title'] ?? '',
                'body' => $note->data['body'] ?? '',
                // A note is normally something to open, but it does not have to
                // be: a kind that only announces something would carry no
                // address, and the row then marks itself read and stays put.
                'url' => $note->data['url'] ?? null,
                // Who it is from, as the class wrote it. Null on a note filed
                // before notes said who they were from, and the list draws a
                // dash there rather than guessing.
                'from' => $note->data['from'] ?? null,
                'read' => $note->read_at !== null,
                'at' => $note->created_at->toIso8601String(),
            ]),
        ]);
    }

    /**
     * This account's notes, and the one expression in this file that says whose
     * they are.
     *
     * Everything else — the tabs, the search, the funnels, the sort, the values
     * the funnels offer, the figures over the tabs — is built on top of this, so
     * there is one place where the question «whose?» is answered and no second
     * place where it could come out differently.
     *
     * @return Builder<DatabaseNotification>
     */
    private function notes(Request $request): Builder
    {
        return $request->user()->notifications()->getQuery();
    }

    /**
     * The search box: what somebody typed, looked for in the heading, the
     * sentence and the name of whoever the note is from.
     *
     * Those three because they are the three things the table prints — a search
     * box answers what a reader can see — and the sender's name is the one that
     * proves the JSON paths work at all. It is two levels down
     * (`data->from->name`), and it is the field people actually search this
     * list by, a list of notes being read the way a list of letters is: first
     * for who sent it, then for what it says.
     *
     * @param  Builder<DatabaseNotification>  $notes
     */
    private function search(Builder $notes, string $term): void
    {
        if ($term === '') {
            return;
        }

        // Bracketed, so the three fields are three answers to the search rather
        // than three ways past the tab and the funnels standing beside it.
        $notes->where(function (Builder $query) use ($term) {
            $this->holding($query, 'data->title', $term, 'or');
            $this->holding($query, 'data->body', $term, 'or');
            $this->holding($query, 'data->from->name', $term, 'or');
        });
    }

    /**
     * The funnels under the headings, each narrowing by what its own column
     * prints.
     *
     * @param  Builder<DatabaseNotification>  $notes
     * @param  array<string, mixed>  $filters
     */
    private function narrow(Builder $notes, array $filters): void
    {
        $notes
            // Several senders or several headings at once: narrowing the list to
            // what the office said and what a colleague asked, together, is one
            // question even though a note has one of each.
            ->when($filters['from'], fn (Builder $query, array $names) => $query->whereIn('data->from->name', $names))
            ->when($filters['title'], fn (Builder $query, array $titles) => $query->whereIn('data->title', $titles))
            // The sentence is asked about by the words in it rather than picked
            // off a list, because it is not one of a handful of values: a
            // refusal carries the reason somebody typed into it, so no two notes
            // of that kind say the same thing.
            ->when($filters['body'] !== '', fn (Builder $query) => $this->holding($query, 'data->body', $filters['body']))
            // When it arrived is the one column that really is a column, so it
            // needs no JSON at all. Read by whole days: «с 5 ноября» means the
            // notes of the fifth and not the ones filed after midnight on it.
            ->when($filters['at_from'], fn (Builder $query, string $day) => $query->whereDate('created_at', '>=', $day))
            ->when($filters['at_to'], fn (Builder $query, string $day) => $query->whereDate('created_at', '<=', $day));
    }

    /**
     * «This JSON path holds these characters somewhere in it.»
     *
     * Raw SQL rather than `where('data->title', 'like', ...)` for one reason,
     * and it is the escape clause. A note about a raise says «50%», and LIKE
     * reads a per cent sign as «anything at all»: left alone, a search for
     * «50%» hands back every note that mentions 50. So the pattern escapes it,
     * and then has to say which character did the escaping — MySQL assumes the
     * backslash and SQLite assumes nothing, so neither default will do and the
     * clause is spelled out. Only the operator is written by hand: the column
     * half is `wrap()`ed, so the JSON path is still compiled by whichever
     * grammar is answering.
     *
     * @param  Builder<DatabaseNotification>  $notes
     * @param  string  $boolean  «or» inside the bracket the search builds,
     *                           «and» for a funnel that narrows on its own
     */
    private function holding(Builder $notes, string $path, string $term, string $boolean = 'and'): void
    {
        $notes->whereRaw(
            $notes->getGrammar()->wrap($path)." like ? escape '".self::LIKE_ESCAPE."'",
            ['%'.$this->literally($term).'%'],
            $boolean,
        );
    }

    /**
     * What somebody typed, read as the characters they typed rather than as a
     * pattern.
     */
    private function literally(string $term): string
    {
        $mark = self::LIKE_ESCAPE;

        // The escape character goes first, so that the marks the two passes
        // after it add are not themselves escaped.
        return str_replace([$mark, '%', '_'], [$mark.$mark, $mark.'%', $mark.'_'], $term);
    }

    /**
     * What this account's own notes actually hold on one of their fields, each
     * value once, for the funnel over that column.
     *
     * Built from the notes and not from the notification classes, so a funnel
     * offers what is there and nothing else: «Личный листок отклонён» listed to
     * somebody whose sheet has never been sent back is three clicks ending in
     * an empty table.
     *
     * One query, which is one scan of this account's rows — a JSON path cannot
     * be indexed here, the column being a single string that holds all of them,
     * so DISTINCT over it reads every note the account has. At a dozen notes
     * that is nothing. At thousands it is a scan on every page view, and the
     * funnel it fills would be hundreds of lines long, which is not a funnel any
     * more. Both are answered the same way on the day notes are sent for
     * something other than the personal sheet: the headings become a short fixed
     * list the notification classes publish, and the senders become a join on
     * `users` narrowed to the people who have actually written. Today the
     * headings are three and the senders are the handful of people in the
     * office, so neither is worth the indirection yet.
     *
     * @return list<array{value: string, label: string}>
     */
    private function choices(Request $request, string $path): array
    {
        $notes = $this->notes($request);

        // The order the relation came with has to go, and here it is not a
        // nicety: MySQL refuses to order a DISTINCT by anything that is not in
        // its SELECT, and what `notifications()` ordered by is `created_at`,
        // which this query does not select.
        $values = $notes->reorder()
            ->whereNotNull($path)
            ->distinct()
            ->selectRaw($notes->getGrammar()->wrap($path).' as value')
            ->orderBy($path)
            ->pluck('value');

        // The value a funnel sends back is the text itself and not an id, even
        // for the sender, where an id looks like the obvious choice and is the
        // wrong one: inside the JSON an id is a number, and SQLite hands back
        // the integer 5 where MySQL hands back the string «5», so a filter
        // comparing either against the «5» out of a query string would match on
        // one database and nothing at all on the other. A name is text on both.
        // The price is that two colleagues sharing a surname and a name are one
        // line of the funnel — the same price the rest of this application pays
        // for naming people the way people name them.
        return $values
            ->filter(fn (mixed $value) => is_string($value) && $value !== '')
            ->map(fn (string $value) => ['value' => $value, 'label' => $value])
            ->values()
            ->all();
    }

    /**
     * One of the funnels as it arrives from the address: `from[]=…&from[]=…`.
     *
     * A single value rather than a list — `?from=Сафаров Шухрат`, which is what
     * a link somebody wrote by hand looks like — is read as a list of one rather
     * than thrown away, and empty entries are dropped: a funnel cleared in the
     * browser leaves `from[]=` behind in the address, and matching the notes
     * whose sender is the empty string would answer that with nothing.
     *
     * @return list<string>
     */
    private function picked(mixed $value): array
    {
        $values = is_array($value) ? $value : [$value];

        return array_values(array_filter(
            array_map(fn (mixed $one) => $this->text($one), $values),
            fn (string $one) => $one !== '',
        ));
    }

    /**
     * One end of the date filter, as a day, or nothing.
     *
     * Read rather than validated, like everything else out of the address: a
     * date somebody mistyped — or a half-typed one, which is what a date box
     * sends while it is being filled in — drops out of the query instead of
     * refusing the page.
     */
    private function day(mixed $value): ?string
    {
        $value = $this->text($value);

        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * One word out of the address, or an empty string where there is no word:
     * `?sort[]=title` hands an array to something expecting a key, and the
     * answer to that is the default rather than a 500.
     */
    private function text(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    /**
     * Notes marked read, or marked unread again.
     *
     * Both directions through one door, because they are one answer to one
     * question — whether this has been dealt with — and a page that can set it
     * can unset it. Somebody who opens a note on the way past and means to come
     * back to it has no other way of saying so.
     *
     * A row already in the state being asked for is left alone rather than
     * written again, so `read_at` keeps the moment a note was first read.
     */
    public function read(Request $request): RedirectResponse
    {
        $asked = $request->validate([
            'ids' => ['required', 'array', 'max:'.self::MOST],
            'ids.*' => ['string'],
            'read' => ['required', 'boolean'],
        ]);

        $this->theirs($request, $asked['ids'])
            ->when($asked['read'], fn (Builder $notes) => $notes->whereNull('read_at'))
            ->when(! $asked['read'], fn (Builder $notes) => $notes->whereNotNull('read_at'))
            ->update(['read_at' => $asked['read'] ? now() : null]);

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

    /**
     * Notes thrown away.
     *
     * Nothing of consequence goes with them: a note is a pointer at something
     * that happened, and the thing itself — the request, the card it was about
     * — is a row in a table of its own. Which is why this asks nothing before
     * doing it, unlike the places where the thing itself is being deleted.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $asked = $request->validate([
            'ids' => ['required', 'array', 'max:'.self::MOST],
            'ids.*' => ['string'],
        ]);

        $this->theirs($request, $asked['ids'])->delete();

        return back();
    }

    /**
     * How many notes one action may name.
     *
     * The page can only tick what it is showing, so the largest honest number
     * is the largest page there is. It is a ceiling against a request somebody
     * has written by hand, not a limit anybody meets.
     */
    private const MOST = 100;

    /**
     * Those of the named notes that are this account's.
     *
     * The ids come from the browser and are taken as a claim rather than as a
     * fact: the relation is the filter, so an id belonging to somebody else
     * matches nothing at all. There is no refusal to write and none to read —
     * the row simply is not there.
     *
     * @param  list<string>  $ids
     * @return Builder<DatabaseNotification>
     */
    private function theirs(Request $request, array $ids): Builder
    {
        return $this->notes($request)->whereKey($ids);
    }
}
