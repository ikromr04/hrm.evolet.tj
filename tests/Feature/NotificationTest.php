<?php

namespace Tests\Feature;

use App\Models\SheetChangeRequest;
use App\Models\User;
use App\Notifications\SheetChangeAccepted;
use App\Notifications\SheetChangeRejected;
use App\Notifications\SheetChangeRequested;
use Carbon\CarbonInterface;
use Database\Seeders\PositionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * «Уведомления» — what the application has said to one person, and nothing it
 * has said to anybody else.
 *
 * The section was a bell with nothing behind it until the personal sheet began
 * being handed in for the office to accept. It now carries the one kind of
 * thing in this application that happens to somebody while they are not
 * looking: a colleague asks for their card to be changed, and personnel answer
 * days later. Both ends need telling, and neither can be told by a page they
 * are not on.
 *
 * What is worth pinning here is almost entirely the access rule, and the
 * interesting thing about it is that there is no rule — no right grants
 * somebody else's notes, and nothing checks for one. Every query in the
 * controller starts at `$request->user()->notifications()`, so a note belongs
 * to the account it was addressed to and a stranger's id is not a refusal but
 * simply a row that relation does not hold. That is a load-bearing absence: the
 * day somebody writes `DatabaseNotification::find($id)` here instead, every
 * account in the building can read and clear every other account's notes, and
 * nothing anywhere would say so. These tests say so.
 *
 * The count beside the bell is pinned with them, because it is the whole of
 * what makes the section work: it is shared onto every page of the application,
 * and a number that does not come down when a note is read is a number people
 * stop looking at.
 *
 * The rest of what is pinned here is the table the section has become — the
 * tabs, the search box, a sort and a funnel per column, the paging — and it is
 * pinned harder than the same machinery is pinned on «Сотрудники», for one
 * reason: not one of these columns is a column. The heading, the sentence and
 * the sender all live inside `data`, which is a single JSON string, and Laravel
 * writes that string with every Russian letter escaped to \uXXXX. So a filter
 * written against the `data` column instead of against a path into it is not a
 * filter that is slightly wrong: it matches nothing at all, on every row,
 * silently, and a table that quietly answers every question with an empty list
 * is exactly what nobody reports. The sender is the one that proves it, being
 * two levels down — so «нашлось по фамилии отправителя» is a test about JSON
 * and not about searching.
 */
class NotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RoleSeeder::class, PositionSeeder::class]);
    }

    /** Somebody who signs in and has a bell like everybody else. */
    private function viewer(): User
    {
        return User::factory()->create()->assignRole('specialist');
    }

    /**
     * One note on an account about that account's own sheet.
     *
     * The plain one, used by everything that only needs a note to exist.
     *
     * @param  int  $minutesAgo  how long ago it arrived, so that a list read
     *                           newest first has an order to be read in: the
     *                           column keeps whole seconds, and three notes
     *                           filed in one of them are in no order at all
     */
    private function noted(User $to, int $minutesAgo = 0): DatabaseNotification
    {
        return $this->asked($to, $to, now()->subMinutes($minutesAgo));
    }

    /**
     * A colleague's request, as the office is told about it.
     *
     * The note whose sender is somebody other than its reader, which is what
     * the «От кого» column is for: the office opens this list to see whose
     * sheet is waiting.
     */
    private function asked(User $to, User $by, ?CarbonInterface $at = null): DatabaseNotification
    {
        return $this->filing($to, SheetChangeRequested::class, [
            'user_id' => $by->id,
            'status' => SheetChangeRequest::PENDING,
        ], $at);
    }

    /** The office's yes, as the person who asked is told: the sender is the officer. */
    private function accepted(User $to, User $by, ?CarbonInterface $at = null): DatabaseNotification
    {
        return $this->filing($to, SheetChangeAccepted::class, [
            'user_id' => $to->id,
            'status' => SheetChangeRequest::ACCEPTED,
            'reviewed_by' => $by->id,
            'reviewed_at' => now(),
        ], $at);
    }

    /**
     * And its no.
     *
     * @param  string  $because  the reason it was sent back, which this kind of
     *                           note writes into its own sentence. It is the one
     *                           field of this table whose words differ from row
     *                           to row even within a kind, which is why the
     *                           sentence is filtered by the words in it rather
     *                           than picked off a list of three.
     */
    private function rejected(User $to, User $by, string $because, ?CarbonInterface $at = null): DatabaseNotification
    {
        return $this->filing($to, SheetChangeRejected::class, [
            'user_id' => $to->id,
            'status' => SheetChangeRequest::REJECTED,
            'comment' => $because,
            'reviewed_by' => $by->id,
            'reviewed_at' => now(),
        ], $at);
    }

    /**
     * A note filed by the class that files it for real.
     *
     * Through the notification itself rather than by writing a row, because the
     * shape of «data» is that class's business and a fixture inventing its own
     * would be pinning the page against a note no notification ever sends —
     * including, and this is the point of these tests, against JSON escaped
     * some other way than Laravel escapes it.
     *
     * @param  class-string<Notification>  $kind
     * @param  array<string, mixed>  $request  the request the note is about,
     *                                         whose author or reviewer becomes
     *                                         the sender the note writes down
     */
    private function filing(User $to, string $kind, array $request, ?CarbonInterface $at = null): DatabaseNotification
    {
        $filed = SheetChangeRequest::query()->create([...$request, 'payload' => [], 'papers' => []]);

        return $this->travelTo($at ?? now(), function () use ($to, $kind, $filed) {
            $to->notify(new $kind($filed));

            // Picked out by the request it is about and not by being the latest
            // row: these are filed out of order on purpose, and «the newest
            // one» would hand back somebody else's note half the time.
            return $to->notifications()->get()
                ->sole(fn (DatabaseNotification $note) => $note->data['request_id'] === $filed->id);
        });
    }

    /**
     * Four notes on one account, no two of them alike in any column.
     *
     * Contrived, and deliberately so: nobody is told about a colleague's
     * request, their own refusal twice and their own acceptance in the same
     * hour. What the table has to be held against is four rows whose senders,
     * headings, sentences and dates all run in four different orders — because
     * the mistake this catches is a column that quietly sorts by something
     * other than itself, and on a fixture where two of those orders coincide it
     * would pass. The dates are chosen so that none of the three columns, in
     * either direction, comes out in the order the page opens in: a sort that
     * forgot to clear the «newest first» the relation arrives with would hand
     * back the date order under every heading, and that is the one way this can
     * go wrong without looking wrong.
     *
     * @return array{viewer: User, asked: DatabaseNotification, accepted: DatabaseNotification, paper: DatabaseNotification, address: DatabaseNotification}
     */
    private function fourNotes(): array
    {
        $viewer = $this->viewer();

        $author = User::factory()->create(['surname' => 'Рахимов', 'name' => 'Икром']);
        $office = User::factory()->create(['surname' => 'Сафаров', 'name' => 'Шухрат'])->assignRole('hr');
        $firstOfficer = User::factory()->create(['surname' => 'Ашуров', 'name' => 'Бахром'])->assignRole('hr');
        $secondOfficer = User::factory()->create(['surname' => 'Юсупов', 'name' => 'Далер'])->assignRole('hr');

        return [
            'viewer' => $viewer,
            'asked' => $this->asked($viewer, $author, now()->subMinutes(20)),
            'accepted' => $this->accepted($viewer, $office, now()->subMinutes(30)),
            'paper' => $this->rejected($viewer, $firstOfficer, 'нет скана паспорта', now()->subMinutes(40)),
            'address' => $this->rejected($viewer, $secondOfficer, 'адрес не совпадает с пропиской', now()->subMinutes(10)),
        ];
    }

    /**
     * One prop off the page, for a question put to it in the address.
     *
     * Read out whole rather than asserted key by key, because what half the
     * tests below are about is the order of a list: «notes.data.2.id» three
     * times over says what the page holds, and when it fails it does not say
     * what the page holds instead.
     */
    private function prop(User $viewer, string $key, string $query = ''): mixed
    {
        $read = null;

        $this->actingAs($viewer)
            ->get('/notifications'.($query === '' ? '' : '?'.$query))
            ->assertOk()
            // A whole closure and not an arrow, because the prop is carried out
            // of the assertion by reference and an arrow function copies what it
            // closes over: written short, this reads every prop as null.
            ->assertInertia(function (AssertableInertia $page) use ($key, &$read) {
                $page->where($key, function (mixed $value) use (&$read) {
                    $read = $value;

                    return true;
                })->etc();
            });

        return $read;
    }

    /**
     * The ids the list comes back in, in the order it comes back in.
     *
     * @return list<string>
     */
    private function listed(User $viewer, string $query = ''): array
    {
        /** @var Collection<int, array<string, mixed>> $rows */
        $rows = $this->prop($viewer, 'notes.data', $query);

        return $rows->pluck('id')->all();
    }

    /**
     * The page shows a viewer their own notes, newest first, and nobody else's.
     *
     * The second half is the one that matters and the one nothing else in the
     * application would catch. A note names a colleague and leads to their
     * personal sheet; a page that listed everybody's would be handing one
     * account a list of what every other account has been asked to do.
     */
    public function test_a_viewer_is_shown_their_own_notes_and_nobody_elses()
    {
        $viewer = $this->viewer();
        $older = $this->noted($viewer, minutesAgo: 30);
        $newer = $this->noted($viewer);

        $other = $this->viewer();
        $theirs = $this->noted($other);

        $this->actingAs($viewer)
            ->get('/notifications')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('notifications')
                ->where('notes.data.0.id', $newer->id)
                ->where('notes.data.1.id', $older->id)
                ->count('notes.data', 2)
                ->where('counts.unread', 2)
                // Each note says what it says: the heading, the sentence and
                // the address are the notification's own words, written when it
                // was filed, so a second kind of note needs no second page.
                ->where('notes.data.0.kind', 'sheet_request')
                ->where('notes.data.0.title', 'Личный листок на проверку')
                ->where('notes.data.0.body', $newer->data['body'])
                ->where('notes.data.0.url', $newer->data['url'])
                ->where('notes.data.0.read', false)
            );

        // Their note is not on it, by id and not merely by count.
        $this->actingAs($viewer)
            ->get('/notifications')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('notes.data', fn ($items) => collect($items)->pluck('id')->doesntContain($theirs->id))
                ->etc()
            );

        // And an account nothing has happened to is shown an empty list rather
        // than everybody else's.
        $this->actingAs($this->viewer())
            ->get('/notifications')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->count('notes.data', 0)->where('counts.unread', 0));
    }

    /**
     * A note pressed is marked read, and keeps the moment it was first read.
     *
     * The row follows its own address straight afterwards, so the marking has
     * to be done on the way out — and done once: a note touched again on every
     * later visit would lose the one thing «read_at» is for, which is saying
     * when somebody actually saw it.
     */
    public function test_a_note_pressed_is_marked_read_and_keeps_the_moment_it_was_read()
    {
        $viewer = $this->viewer();
        $note = $this->noted($viewer);
        $untouched = $this->noted($viewer, minutesAgo: 10);

        $this->actingAs($viewer)
            ->post('/notifications/read', ['ids' => [$note->id], 'read' => true])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $note->refresh();
        $this->assertNotNull($note->read_at);
        $this->assertNull($untouched->refresh()->read_at);
        $this->assertSame(1, $viewer->unreadNotifications()->count());

        // Pressed again a day later: the moment it was read stands.
        $was = $note->read_at;

        $this->travel(1)->day();

        $this->actingAs($viewer)->post('/notifications/read', ['ids' => [$note->id], 'read' => true])->assertRedirect();

        $this->assertTrue($was->equalTo($note->refresh()->read_at));
    }

    /**
     * And marking one read cannot reach another account's row.
     *
     * The id arrives from the browser, so this is the one place where one
     * account can name another's note. It is answered by the relation the update
     * runs through rather than by a check — the row is not in it, so nothing is
     * updated, and a wrong id is not an error but simply nothing.
     */
    public function test_marking_a_note_read_cannot_reach_another_account()
    {
        $viewer = $this->viewer();
        $mine = $this->noted($viewer);

        $other = $this->viewer();
        $theirs = $this->noted($other);

        $this->actingAs($viewer)
            ->post('/notifications/read', ['ids' => [$theirs->id], 'read' => true])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertNull($theirs->refresh()->read_at);
        $this->assertSame(1, $other->unreadNotifications()->count());

        // And the viewer's own note was not read by the attempt either: this
        // marks one named row or none.
        $this->assertNull($mine->refresh()->read_at);
    }

    /**
     * «Отметить все прочитанными» clears this account's notes and only this
     * account's.
     *
     * One statement rather than a row at a time, which is the reason to pin the
     * boundary of it twice over: a single `update` with the wrong `where` on it
     * clears the whole table in one go, and the only thing left to notice would
     * be a dozen people whose bells went quiet.
     */
    public function test_marking_everything_read_clears_this_account_alone()
    {
        $viewer = $this->viewer();
        $this->noted($viewer, minutesAgo: 20);
        $this->noted($viewer, minutesAgo: 10);
        $read = $this->noted($viewer, minutesAgo: 30);
        $read->markAsRead();
        $was = $read->refresh()->read_at;

        $other = $this->viewer();
        $this->noted($other, minutesAgo: 5);
        $this->noted($other);

        $this->travel(1)->day();

        $this->actingAs($viewer)
            ->post('/notifications/read-all')
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertSame(0, $viewer->unreadNotifications()->count());
        $this->assertSame(3, $viewer->notifications()->count());
        // The one already read keeps the moment it was read: «все» means the
        // unread ones, and the rest are left as they stand.
        $this->assertTrue($was->equalTo($read->refresh()->read_at));

        // The other account's bell is untouched.
        $this->assertSame(2, $other->unreadNotifications()->count());
    }

    /**
     * The number beside the bell is shared onto every page, and follows what
     * was read.
     *
     * It is the only part of this section most people ever look at: the list is
     * opened because the bell carried a number. So it is counted on every visit
     * rather than handed out once — a figure that goes stale is a figure that
     * either nags about notes already read or hides ones that arrived — and it
     * is counted over everything rather than over the fifty notes the page
     * shows.
     */
    public function test_the_bell_carries_the_unread_count_on_every_page()
    {
        $viewer = $this->viewer();
        $this->noted($viewer, minutesAgo: 10);
        $this->noted($viewer);

        $this->actingAs($viewer)
            ->get('/profile')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('auth.notifications', 2));

        $this->actingAs($viewer)->post('/notifications/read-all')->assertRedirect();

        $this->actingAs($viewer)
            ->get('/profile')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('auth.notifications', 0));

        // And one account's bell says nothing about another's.
        $other = $this->viewer();
        $this->noted($other);

        $this->actingAs($viewer)
            ->get('/notifications')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('counts.unread', 0)->etc());

        $this->actingAs($other)
            ->get('/notifications')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('counts.unread', 1)->etc());
    }

    /**
     * The tabs count the whole list, and open only their own part of it.
     *
     * The figures are counted over every note and not over the fifty the page
     * is handed, because a tab is read before it is opened: «Непрочитанные 3»
     * that turns out to hold four is a figure nobody trusts again.
     */
    public function test_the_filter_narrows_the_list_and_the_counts_stay_whole()
    {
        $viewer = $this->viewer();
        $read = $this->noted($viewer, 2);
        $this->noted($viewer, 1);
        $this->noted($viewer);

        $read->markAsRead();

        $this->actingAs($viewer)
            ->get('/notifications')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('filter', 'all')
                ->where('counts.all', 3)
                ->where('counts.unread', 2)
                ->count('notes.data', 3)
                ->etc());

        // «Непрочитанные» leaves out the one that was read — and the counts do
        // not move with it: they say how much there is, not how much is shown.
        $this->actingAs($viewer)
            ->get('/notifications?filter=unread')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('filter', 'unread')
                ->where('counts.all', 3)
                ->count('notes.data', 2)
                ->where('notes.data', fn ($items) => collect($items)->pluck('id')->doesntContain($read->id))
                ->etc());

        // And a word nobody offered falls back to the whole list rather than to
        // nothing: an address typed by hand is not a reason to show an empty page.
        $this->actingAs($viewer)
            ->get('/notifications?filter=вчерашние')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('filter', 'all')->count('notes.data', 3)->etc());
    }

    /**
     * A note can be put back to unread.
     *
     * Somebody who opens a note on the way past and means to come back to it
     * has no other way of saying so, and the bell is the only thing that
     * remembers for them.
     */
    public function test_a_note_can_be_marked_unread_again()
    {
        $viewer = $this->viewer();
        $note = $this->noted($viewer);
        $note->markAsRead();

        $this->actingAs($viewer)
            ->post('/notifications/read', ['ids' => [$note->id], 'read' => false])
            ->assertRedirect();

        $this->assertNull($note->refresh()->read_at);
        $this->assertSame(1, $viewer->unreadNotifications()->count());
    }

    /**
     * Notes thrown away, and only this account's.
     *
     * Nothing of consequence goes with them — a note is a pointer at a request
     * that is a row of its own — but the list is somebody's, and a page that
     * deleted by id alone would let one account clear another's.
     */
    public function test_notes_are_thrown_away_one_account_at_a_time()
    {
        $viewer = $this->viewer();
        $other = $this->viewer();
        $mine = $this->noted($viewer, 1);
        $kept = $this->noted($viewer);
        $theirs = $this->noted($other);

        $this->actingAs($viewer)
            ->delete('/notifications', ['ids' => [$mine->id, $theirs->id]])
            ->assertRedirect();

        $this->assertNull(DatabaseNotification::query()->find($mine->id));
        $this->assertNotNull(DatabaseNotification::query()->find($kept->id));
        // The stranger's id matched nothing at all, and their note is still there.
        $this->assertNotNull(DatabaseNotification::query()->find($theirs->id));
        $this->assertSame(1, $viewer->notifications()->count());
        $this->assertSame(1, $other->notifications()->count());
    }

    /**
     * A note says who it is from.
     *
     * The list is read the way a list of letters is read, and the sender cannot
     * be worked out afterwards: the request keeps its author and whoever
     * answered it, and which of the two sent the note depends on the note. So
     * the class writes it down, and this pins that it does.
     */
    public function test_a_note_carries_the_person_it_is_from()
    {
        $office = User::factory()->create(['surname' => 'Сафаров', 'name' => 'Шухрат'])->assignRole('hr');
        $author = User::factory()->create(['surname' => 'Рахимов', 'name' => 'Икром', 'patronymic' => 'Дилшодович']);

        $filed = SheetChangeRequest::query()->create([
            'user_id' => $author->id,
            'status' => SheetChangeRequest::PENDING,
            'payload' => [],
            'papers' => [],
        ]);

        $office->notify(new SheetChangeRequested($filed));

        $note = $office->notifications()->sole();

        $this->assertSame($author->id, $note->data['from']['id']);
        $this->assertSame('Рахимов Икром', $note->data['from']['name']);
    }

    /**
     * The page is handed back the question it was asked, key for key.
     *
     * Which is the whole reason the table reads the address rather than keeping
     * its state in the browser: a filter set is saved as a link and a link is
     * only worth saving if the page can be rebuilt from it. So every one of
     * these travels out again as a prop — the tab, the search, the sort, each
     * funnel, the page size — and the page redraws its own boxes from them. A
     * prop dropped here is a saved filter set that opens showing the right rows
     * above empty boxes, which is worse than not saving it.
     */
    public function test_the_page_is_handed_back_the_question_it_was_asked()
    {
        ['viewer' => $viewer] = $this->fourNotes();

        $this->actingAs($viewer)
            ->get('/notifications?filter=unread&q=листок&sort=title&direction=desc&per_page=25'
                .'&from[]=Ашуров Бахром&title[]=Личный листок отклонён&body=паспорта'
                .'&at_from=2026-11-01&at_to=2026-11-30')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('notifications')
                ->where('filter', 'unread')
                ->where('q', 'листок')
                ->where('sort', ['key' => 'title', 'direction' => 'desc'])
                ->where('sortable', ['from', 'title', 'body', 'at'])
                ->where('filters.from', ['Ашуров Бахром'])
                ->where('filters.title', ['Личный листок отклонён'])
                ->where('filters.body', 'паспорта')
                ->where('filters.at_from', '2026-11-01')
                ->where('filters.at_to', '2026-11-30')
                ->where('perPage', 25)
                ->where('perPageOptions', [10, 25, 50, 100])
                ->has('options.from')
                ->has('options.title')
                ->has('notes.data')
                ->etc());
    }

    /**
     * Every column sorts, and sorts both ways.
     *
     * Three of the four are read out of the JSON the note is stored as, and the
     * fourth is a column of the table, so this is four orderings written four
     * different ways and only one of them is the ordinary kind. The orders
     * asserted are all different from each other and all different from the
     * order the page opens in, which is what makes the assertion worth making:
     * the relation these notes are read through is declared newest first, so a
     * sort that merely adds its column to that one would hand back the date
     * order under every heading and look like it worked.
     */
    public function test_every_column_sorts_both_ways()
    {
        ['viewer' => $viewer, 'asked' => $asked, 'accepted' => $accepted, 'paper' => $paper, 'address' => $address] = $this->fourNotes();

        // Ашуров, Рахимов, Сафаров, Юсупов — and the name is two levels into
        // the JSON, so this is the one that proves the path.
        $this->assertSame(
            [$paper->id, $asked->id, $accepted->id, $address->id],
            $this->listed($viewer, 'sort=from&direction=asc'),
        );
        $this->assertSame(
            [$address->id, $accepted->id, $asked->id, $paper->id],
            $this->listed($viewer, 'sort=from&direction=desc'),
        );

        // «…на проверку», «…отклонён», «…отклонён», «…принят». The two
        // refusals share a heading, and the newer of them comes first either
        // way: the settled order under the one asked for does not turn round
        // with it, or a note would change pages on the way back from a sort.
        $this->assertSame(
            [$asked->id, $address->id, $paper->id, $accepted->id],
            $this->listed($viewer, 'sort=title&direction=asc'),
        );
        $this->assertSame(
            [$accepted->id, $address->id, $paper->id, $asked->id],
            $this->listed($viewer, 'sort=title&direction=desc'),
        );

        // «Отдел кадров отклонил… адрес», «…нет скана», «Отдел кадров принял…»,
        // «Сотрудник просит…»: the two refusals are told apart by the reason
        // typed into them, which is further into the sentence than any heading.
        $this->assertSame(
            [$address->id, $paper->id, $accepted->id, $asked->id],
            $this->listed($viewer, 'sort=body&direction=asc'),
        );
        $this->assertSame(
            [$asked->id, $accepted->id, $paper->id, $address->id],
            $this->listed($viewer, 'sort=body&direction=desc'),
        );

        // And the date, which is the one real column and the one the page opens
        // in: newest first without being asked, oldest first when it is.
        $this->assertSame(
            [$address->id, $asked->id, $accepted->id, $paper->id],
            $this->listed($viewer),
        );
        $this->assertSame(
            [$address->id, $asked->id, $accepted->id, $paper->id],
            $this->listed($viewer, 'sort=at'),
        );
        $this->assertSame(
            [$paper->id, $accepted->id, $asked->id, $address->id],
            $this->listed($viewer, 'sort=at&direction=asc'),
        );
    }

    /**
     * Every funnel narrows, by what its own column prints.
     *
     * The two that offer a list of values answer by the value itself and not by
     * an id — the funnel over «От кого» sends back «Ашуров Бахром» — which is
     * not how the rest of this application narrows by a person, and is the
     * right answer here: inside the JSON an id is a number, and the two
     * databases hand a number back as two different types, so a filter built on
     * one would work on the developer's machine and match nothing on the
     * tests'. A name is text to both.
     */
    public function test_every_funnel_narrows_the_list()
    {
        ['viewer' => $viewer, 'asked' => $asked, 'accepted' => $accepted, 'paper' => $paper, 'address' => $address] = $this->fourNotes();

        $this->assertSame([$paper->id], $this->listed($viewer, 'from[]=Ашуров Бахром'));

        // Two senders at once: what one officer said and what another said,
        // together, is one question even though a note has one sender.
        $this->assertSame(
            [$address->id, $paper->id],
            $this->listed($viewer, 'from[]=Ашуров Бахром&from[]=Юсупов Далер'),
        );

        $this->assertSame([$accepted->id], $this->listed($viewer, 'title[]=Личный листок принят'));
        $this->assertSame(
            [$address->id, $paper->id],
            $this->listed($viewer, 'title[]=Личный листок отклонён'),
        );

        // The sentence by the words in it, which is the only way to tell two
        // refusals apart: they carry the same heading and the same sender's
        // kind of name, and differ only in the reason.
        $this->assertSame([$paper->id], $this->listed($viewer, 'body=скана паспорта'));
        $this->assertSame([$asked->id], $this->listed($viewer, 'body=Сотрудник просит'));

        // And two of them together narrow together rather than in turn: the
        // heading of a refusal and the sender of the other refusal is a pair
        // nothing answers.
        $this->assertSame(
            [],
            $this->listed($viewer, 'title[]=Личный листок принят&from[]=Ашуров Бахром'),
        );

        // A funnel cleared in the browser leaves its key behind in the address
        // with nothing in it, which asks for the whole list and not for the
        // notes whose sender is the empty string.
        $this->assertCount(4, $this->listed($viewer, 'from[]=&title[]=&body='));

        // A value nobody has sent anything under narrows to nothing, which is
        // the honest answer: the funnel offers what the notes hold, so this
        // only happens to a link somebody kept too long.
        $this->assertSame([], $this->listed($viewer, 'from[]=Неизвестный Никто'));

        $this->assertNotSame($accepted->id, $address->id);
    }

    /**
     * The date filter narrows by whole days, at either end.
     *
     * By days and not by moments, because that is what the box above the column
     * takes: «с 5 ноября» means the notes of the fifth, including the one filed
     * at half past four in the afternoon, and a filter comparing against
     * midnight would leave out everything but the first seconds of the day.
     */
    public function test_the_date_filter_narrows_by_whole_days()
    {
        $viewer = $this->viewer();
        $office = User::factory()->create(['surname' => 'Сафаров', 'name' => 'Шухрат'])->assignRole('hr');

        $old = $this->accepted($viewer, $office, now()->subDays(3)->setTime(16, 30));
        $middle = $this->accepted($viewer, $office, now()->subDays(1)->setTime(9, 15));
        $today = $this->accepted($viewer, $office, now()->setTime(11, 45));

        $yesterday = now()->subDay()->toDateString();

        $this->assertSame([$today->id, $middle->id], $this->listed($viewer, 'at_from='.$yesterday));
        $this->assertSame([$middle->id, $old->id], $this->listed($viewer, 'at_to='.$yesterday));
        $this->assertSame([$middle->id], $this->listed($viewer, 'at_from='.$yesterday.'&at_to='.$yesterday));
    }

    /**
     * The search box reads the heading, the sentence and the sender's name.
     *
     * Those three because they are the three things the table prints, and the
     * sender's name because it is the one people actually type: a list of notes
     * is read the way a list of letters is, first for who it is from. It is
     * also the one that proves the machinery underneath — two levels into the
     * JSON, where a path written slightly wrong does not fail but silently
     * matches nothing.
     */
    public function test_the_search_reads_the_heading_the_sentence_and_the_sender()
    {
        ['viewer' => $viewer, 'asked' => $asked, 'accepted' => $accepted, 'paper' => $paper, 'address' => $address] = $this->fourNotes();

        // By the sender: nothing else on the note says «Ашуров».
        $this->assertSame([$paper->id], $this->listed($viewer, 'q=Ашуров'));
        $this->assertSame([$asked->id], $this->listed($viewer, 'q=Рахимов Икром'));

        // By the heading, which two of the four share.
        $this->assertSame([$address->id, $paper->id], $this->listed($viewer, 'q=отклонён'));

        // By the sentence, including the reason typed into a refusal — the one
        // place where a note's words are nobody's but the officer's.
        $this->assertSame([$accepted->id], $this->listed($viewer, 'q=записаны в вашу карточку'));
        $this->assertSame([$address->id], $this->listed($viewer, 'q=не совпадает с пропиской'));

        // A word every heading holds finds the whole list.
        $this->assertCount(4, $this->listed($viewer, 'q=Личный листок'));

        // And a word nothing holds finds nothing rather than everything: a
        // search that silently stops narrowing is a search nobody notices is
        // broken.
        $this->assertSame([], $this->listed($viewer, 'q=автомобиль'));

        // The search box and the tabs narrow together, not instead of each
        // other.
        $this->accepted($viewer, User::factory()->create(['surname' => 'Ашуров', 'name' => 'Бахром']));
        $paper->markAsRead();

        $this->assertSame([], $this->listed($viewer, 'q=Ашуров&filter=unread&title[]=Личный листок отклонён'));
    }

    /**
     * «50%» searches for those three characters.
     *
     * A per cent sign is what LIKE reads as «anything at all», so a search for
     * a figure out of a note about a raise is the one search that silently
     * matches too much instead of too little — and the reader has no way to
     * tell, the extra rows all holding the number they typed. It is also the
     * reason the escaping could not be left to the database's own default:
     * MySQL has one and SQLite has none, so the clause says which character
     * does the escaping, and this is the test that would have caught either
     * half of that being wrong.
     */
    public function test_the_search_reads_a_per_cent_sign_as_a_per_cent_sign()
    {
        $viewer = $this->viewer();
        $office = User::factory()->create(['surname' => 'Сафаров', 'name' => 'Шухрат'])->assignRole('hr');

        $share = $this->rejected($viewer, $office, 'надбавка 50% не согласована', now()->subMinutes(10));
        $sum = $this->rejected($viewer, $office, 'надбавка 5000 сомони не согласована');

        $this->assertSame([$share->id], $this->listed($viewer, 'q=50%25'));

        // The figure without the sign still finds both, so the narrowing above
        // is the sign being read literally and not the search having stopped
        // working.
        $this->assertSame([$sum->id, $share->id], $this->listed($viewer, 'q=50'));
    }

    /**
     * A question nobody offered is answered with the list, not with a refusal.
     *
     * Every one of these arrives from the address bar as often as from the page
     * — out of a saved filter set, out of a link pasted to a colleague, out of
     * a bookmark kept from before a column was renamed — and a 422 has no page
     * to be shown on. So a sort key that is not a column reads newest first, a
     * direction that is not a direction reads the default one, and a page size
     * nobody offers reads the smallest that is offered.
     */
    public function test_a_question_nobody_offered_falls_back_instead_of_refusing()
    {
        ['viewer' => $viewer, 'address' => $address] = $this->fourNotes();

        $this->actingAs($viewer)
            ->get('/notifications?sort=отправитель&direction=вверх&per_page=7&filter=вчерашние')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('sort', ['key' => 'at', 'direction' => 'desc'])
                ->where('perPage', 10)
                ->where('filter', 'all')
                ->where('notes.data.0.id', $address->id)
                ->count('notes.data', 4)
                ->etc());

        // A page size that is a number and still not one of the four is no more
        // acceptable than a word: the list offers four sizes and answers in one
        // of them.
        $this->assertSame(10, $this->prop($viewer, 'perPage', 'per_page=1000'));

        // A sort key sent as a list, which is what a hand-written address does
        // by accident, is a key that is not a column rather than a crash.
        $this->assertSame(
            ['key' => 'at', 'direction' => 'desc'],
            $this->prop($viewer, 'sort', 'sort[]=title')->all(),
        );

        // And a date box half-filled — which is what a date box sends while
        // somebody is still typing in it — drops out instead of emptying the
        // table.
        $this->assertCount(4, $this->listed($viewer, 'at_from=05.11&at_to=никогда'));
    }

    /**
     * The funnels offer what this account's own notes hold, and nothing else.
     *
     * Both halves matter. Offering a heading nobody has ever been sent is three
     * clicks that end in an empty table; offering a sender out of somebody
     * else's notes is this section's one rule broken in the quietest possible
     * place — a list of names, in a dropdown, telling one account who has been
     * writing to another.
     */
    public function test_the_funnels_offer_this_accounts_own_senders_and_headings()
    {
        $viewer = $this->viewer();
        $author = User::factory()->create(['surname' => 'Рахимов', 'name' => 'Икром']);
        $office = User::factory()->create(['surname' => 'Сафаров', 'name' => 'Шухрат'])->assignRole('hr');

        $this->asked($viewer, $author, now()->subMinutes(10));
        $this->accepted($viewer, $office);

        // Another account, written to by somebody this viewer has never heard
        // from, about something this viewer has never been sent.
        $other = $this->viewer();
        $stranger = User::factory()->create(['surname' => 'Ашуров', 'name' => 'Бахром'])->assignRole('hr');
        $this->rejected($other, $stranger, 'нет скана паспорта');

        // Each value once, in the order the funnel lists them, and the value is
        // the text the filter sends back.
        $this->assertSame(
            [
                ['value' => 'Рахимов Икром', 'label' => 'Рахимов Икром'],
                ['value' => 'Сафаров Шухрат', 'label' => 'Сафаров Шухрат'],
            ],
            $this->prop($viewer, 'options.from')->all(),
        );

        $this->assertSame(
            ['Личный листок на проверку', 'Личный листок принят'],
            $this->prop($viewer, 'options.title')->pluck('value')->all(),
        );

        // The other account's officer and the other account's heading are on
        // neither list, and the other account sees its own.
        $this->assertSame(['Ашуров Бахром'], $this->prop($other, 'options.from')->pluck('value')->all());
        $this->assertSame(['Личный листок отклонён'], $this->prop($other, 'options.title')->pluck('value')->all());

        // A sender said once however many notes they sent: the funnel is a list
        // of people, not of notes.
        $this->accepted($viewer, $office, now()->subMinutes(20));
        $this->assertCount(2, $this->prop($viewer, 'options.from'));

        // And an account nothing has happened to is offered nothing rather than
        // everybody.
        $this->assertSame([], $this->prop($this->viewer(), 'options.from')->all());
    }

    /**
     * The figures over the tabs ignore the search box and the funnels.
     *
     * They say how much there is, which is what a tab is read for before it is
     * opened — and a figure that moved with the search would be saying how much
     * of it is on the page, which the paging under the rows already says.
     */
    public function test_the_counts_ignore_the_search_and_the_funnels()
    {
        ['viewer' => $viewer, 'paper' => $paper] = $this->fourNotes();

        $paper->markAsRead();

        $this->actingAs($viewer)
            ->get('/notifications?q=Ашуров&title[]=Личный листок отклонён&per_page=10')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('counts.all', 4)
                ->where('counts.unread', 3)
                // The search found the one note it was about, and said so in
                // the rows and nowhere else.
                ->count('notes.data', 1)
                ->where('notes.data.0.id', $paper->id)
                ->etc());
    }

    /**
     * A page holds what the reader asked it to hold, and the search runs over
     * the whole list rather than over the page.
     *
     * The second half is the one worth pinning: a filter applied after the
     * paging would hand back whatever of the first ten rows happened to match,
     * and on a list of a dozen notes that looks exactly like a filter that
     * works.
     */
    public function test_the_paging_holds_the_size_asked_for_and_the_search_runs_over_the_whole_list()
    {
        $viewer = $this->viewer();
        $office = User::factory()->create(['surname' => 'Сафаров', 'name' => 'Шухрат'])->assignRole('hr');

        // Twelve notes with nothing to find in them, and the thirteenth, oldest
        // of the lot, the only one that says «пропиской».
        for ($minutes = 1; $minutes <= 12; $minutes++) {
            $this->accepted($viewer, $office, now()->subMinutes($minutes));
        }

        $wanted = $this->rejected($viewer, $office, 'адрес не совпадает с пропиской', now()->subMinutes(60));

        $this->assertCount(10, $this->listed($viewer));
        $this->assertCount(13, $this->listed($viewer, 'per_page=25'));
        $this->assertSame([$wanted->id], $this->listed($viewer, 'q=пропиской'));
    }

    /** And a stranger to the application is sent to sign in first. */
    public function test_the_notifications_are_not_open_to_a_guest()
    {
        $viewer = $this->viewer();
        $note = $this->noted($viewer);

        $this->get('/notifications')->assertRedirect('/login');
        $this->post('/notifications/read', ['ids' => [$note->id], 'read' => true])->assertRedirect('/login');
        $this->post('/notifications/read-all')->assertRedirect('/login');

        $this->assertNull($note->refresh()->read_at);
    }
}
