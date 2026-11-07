<?php

namespace Tests\Feature;

use App\Models\SheetChangeRequest;
use App\Models\User;
use App\Notifications\SheetChangeRequested;
use Database\Seeders\PositionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
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
     * One note on an account, filed by the class that files them for real.
     *
     * Through the notification itself rather than by writing a row, because the
     * shape of «data» is that class's business and a fixture inventing its own
     * would be pinning the page against a note no notification ever sends.
     *
     * @param  int  $minutesAgo  how long ago it arrived, so that a list read
     *                           newest first has an order to be read in: the
     *                           column keeps whole seconds, and three notes
     *                           filed in one of them are in no order at all
     */
    private function noted(User $to, int $minutesAgo = 0): DatabaseNotification
    {
        $filed = SheetChangeRequest::query()->create([
            'user_id' => $to->id,
            'status' => SheetChangeRequest::PENDING,
            'payload' => [],
            'papers' => [],
        ]);

        return $this->travelTo(now()->subMinutes($minutesAgo), function () use ($to, $filed) {
            $to->notify(new SheetChangeRequested($filed));

            // Picked out by the request it is about and not by being the latest
            // row: these are filed out of order on purpose, and «the newest
            // one» would hand back somebody else's note half the time.
            return $to->notifications()->get()
                ->sole(fn (DatabaseNotification $note) => $note->data['request_id'] === $filed->id);
        });
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
                ->where('items.0.id', $newer->id)
                ->where('items.1.id', $older->id)
                ->count('items', 2)
                ->where('unread', 2)
                // Each note says what it says: the heading, the sentence and
                // the address are the notification's own words, written when it
                // was filed, so a second kind of note needs no second page.
                ->where('items.0.kind', 'sheet_request')
                ->where('items.0.title', 'Личный листок на проверку')
                ->where('items.0.body', $newer->data['body'])
                ->where('items.0.url', $newer->data['url'])
                ->where('items.0.read', false)
            );

        // Their note is not on it, by id and not merely by count.
        $this->actingAs($viewer)
            ->get('/notifications')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('items', fn ($items) => collect($items)->pluck('id')->doesntContain($theirs->id))
                ->etc()
            );

        // And an account nothing has happened to is shown an empty list rather
        // than everybody else's.
        $this->actingAs($this->viewer())
            ->get('/notifications')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->count('items', 0)->where('unread', 0));
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
            ->post('/notifications/read', ['id' => $note->id])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $note->refresh();
        $this->assertNotNull($note->read_at);
        $this->assertNull($untouched->refresh()->read_at);
        $this->assertSame(1, $viewer->unreadNotifications()->count());

        // Pressed again a day later: the moment it was read stands.
        $was = $note->read_at;

        $this->travel(1)->day();

        $this->actingAs($viewer)->post('/notifications/read', ['id' => $note->id])->assertRedirect();

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
            ->post('/notifications/read', ['id' => $theirs->id])
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
            ->assertInertia(fn (AssertableInertia $page) => $page->where('unread', 0)->etc());

        $this->actingAs($other)
            ->get('/notifications')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('unread', 1)->etc());
    }

    /** And a stranger to the application is sent to sign in first. */
    public function test_the_notifications_are_not_open_to_a_guest()
    {
        $viewer = $this->viewer();
        $note = $this->noted($viewer);

        $this->get('/notifications')->assertRedirect('/login');
        $this->post('/notifications/read', ['id' => $note->id])->assertRedirect('/login');
        $this->post('/notifications/read-all')->assertRedirect('/login');

        $this->assertNull($note->refresh()->read_at);
    }
}
