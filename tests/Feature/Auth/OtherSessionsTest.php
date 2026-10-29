<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Changing a password signs the other browsers out (App\Support\Sessions).
 *
 * The case worth building for is the one where the password is changed because
 * somebody else knows it. A change that leaves that somebody signed in — which
 * is what a session row and a remember cookie between them are good for, weeks
 * of it — tells the person they are safe and leaves them exactly where they
 * were.
 */
class OtherSessionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The application keeps its sessions in the database, which is the whole
        // reason one account's sessions can be picked out and ended. The test
        // suite otherwise runs on the array driver, where there is nothing to
        // pick out and nothing kept between requests either.
        config(['session.driver' => 'database']);
    }

    public function test_changing_the_password_ends_the_other_sessions_and_keeps_this_one(): void
    {
        $user = $this->colleague();
        $token = $user->remember_token;

        $elsewhere = $this->sessionOf($user);

        // Somebody else's browser, which has nothing to do with any of this.
        $colleague = $this->colleague();
        $theirs = $this->sessionOf($colleague);

        $this->actingAs($user)
            ->from('/settings/password')
            ->put('/settings/password', [
                'current_password' => 'password',
                'password' => 'novyi-parol-2026',
                'password_confirmation' => 'novyi-parol-2026',
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('sessions', ['id' => $elsewhere]);

        // The browser the person is sitting in front of is still signed in: they
        // changed their password, they did not ask to be thrown out of the tab
        // they were typing in.
        $this->assertDatabaseHas('sessions', ['id' => session()->getId(), 'user_id' => $user->id]);

        // Nobody else is signed out by somebody else's password.
        $this->assertDatabaseHas('sessions', ['id' => $theirs]);
        $this->assertSame($colleague->remember_token, $colleague->refresh()->remember_token);

        // And the remember cookie, which is not a session and would otherwise
        // let a browser sign itself straight back in, no longer matches anything.
        $this->assertNotSame($token, $user->refresh()->remember_token);
    }

    public function test_resetting_the_password_ends_every_session(): void
    {
        $user = User::factory()->create();
        $token = $user->remember_token;

        $first = $this->sessionOf($user);
        $second = $this->sessionOf($user);

        $this->resetThePasswordOf($user);

        // A reset is asked for by somebody who cannot get in, so there is no
        // browser of theirs here to keep — and every one that is signed in
        // belongs to whoever had the old password.
        $this->assertDatabaseMissing('sessions', ['id' => $first]);
        $this->assertDatabaseMissing('sessions', ['id' => $second]);
        $this->assertNotSame($token, $user->refresh()->remember_token);
    }

    public function test_a_browser_whose_session_was_ended_is_no_longer_signed_in(): void
    {
        $user = User::factory()->create();
        $cookie = config('session.cookie');

        $elsewhere = $this->sessionOf($user);

        // First, that the row really is a browser signed in as them: it reads a
        // page that only somebody signed in can read. Without this the
        // assertion at the end would pass for a session that never worked.
        $this->withCookie($cookie, $elsewhere)
            ->get('/settings/password')
            ->assertOk();

        // Somebody resets the password from somewhere else entirely — a browser
        // carrying a session id that belongs to nothing, which is what anybody
        // arriving for the first time carries.
        $this->forgetWhoIsSignedIn();
        $this->withCookie($cookie, Str::random(40));

        $this->resetThePasswordOf($user);

        // And the browser that was signed in is signed in no longer: the page it
        // could read a moment ago sends it to the sign-in form.
        $this->forgetWhoIsSignedIn();

        $this->withCookie($cookie, $elsewhere)
            ->get('/settings/password')
            ->assertRedirect('/login');
    }

    /**
     * A second browser of this person's, signed in: the row the database session
     * driver writes, with the key the web guard remembers somebody by inside the
     * payload it serializes.
     */
    private function sessionOf(User $user): string
    {
        $id = Str::random(40);

        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $user->id,
            'ip_address' => '203.0.113.7',
            'user_agent' => 'A browser somewhere else',
            'payload' => base64_encode(serialize([
                '_token' => Str::random(40),
                Auth::guard('web')->getName() => $user->id,
            ])),
            'last_activity' => time(),
        ]);

        return $id;
    }

    /**
     * The whole reset, from the letter to the new password.
     */
    private function resetThePasswordOf(User $user): void
    {
        Notification::fake();

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
            $this->post('/reset-password', [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'novyi-parol-2026',
                'password_confirmation' => 'novyi-parol-2026',
            ])->assertSessionHasNoErrors();

            return true;
        });
    }

    /**
     * Between two requests a test keeps the guard it resolved and the session it
     * read, which a browser does not: it arrives with a cookie and nothing else.
     * Forgetting both is what makes the next request a visit from somewhere new.
     */
    private function forgetWhoIsSignedIn(): void
    {
        $this->app['auth']->forgetGuards();
        $this->flushSession();
    }
}
