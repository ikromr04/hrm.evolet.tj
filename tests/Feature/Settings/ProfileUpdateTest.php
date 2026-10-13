<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use App\Notifications\ConfirmNewEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The address a person signs in with. It is asked for here and changed only by
 * answering the letter sent to it: that letter arriving is the only proof the
 * address belongs to them, and until it is answered nobody can be locked out by
 * a typo.
 */
class ProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed()
    {
        $user = $this->colleague();

        $this->actingAs($user)->get('/settings/profile')->assertOk();
    }

    public function test_a_new_address_waits_for_its_letter()
    {
        Notification::fake();

        $user = $this->colleague(['email' => 'old@evolet.tj']);

        $this->actingAs($user)
            ->patch('/settings/profile', ['email' => 'new@evolet.tj'])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/settings/profile');

        $user->refresh();
        // Still signing in with the old one; the new one only waits.
        $this->assertSame('old@evolet.tj', $user->email);
        $this->assertSame('new@evolet.tj', $user->pending_email);

        Notification::assertSentTo($user, ConfirmNewEmail::class);
    }

    public function test_the_link_from_the_letter_changes_the_address()
    {
        $user = $this->colleague(['email' => 'old@evolet.tj']);
        $user->forceFill(['pending_email' => 'new@evolet.tj'])->save();

        $link = URL::temporarySignedRoute('email.confirm', now()->addHour(), [
            'user' => $user->id,
            'hash' => sha1('new@evolet.tj'),
        ]);

        $this->actingAs($user)->get($link)->assertRedirect('/settings/profile');

        $user->refresh();
        $this->assertSame('new@evolet.tj', $user->email);
        $this->assertNull($user->pending_email);
        $this->assertNotNull($user->email_verified_at);
    }

    public function test_a_letter_cannot_confirm_an_address_asked_for_after_it()
    {
        $user = $this->colleague(['email' => 'old@evolet.tj']);
        $user->forceFill(['pending_email' => 'first@evolet.tj'])->save();

        $link = URL::temporarySignedRoute('email.confirm', now()->addHour(), [
            'user' => $user->id,
            'hash' => sha1('first@evolet.tj'),
        ]);

        // Thought better of it and asked for another one before answering.
        $user->forceFill(['pending_email' => 'second@evolet.tj'])->save();

        $this->actingAs($user)->get($link)->assertForbidden();
        $this->assertSame('old@evolet.tj', $user->refresh()->email);
    }

    public function test_one_person_cannot_confirm_another_persons_address()
    {
        $user = $this->colleague();
        $other = User::factory()->create(['email' => 'other@evolet.tj']);
        $other->forceFill(['pending_email' => 'taken@evolet.tj'])->save();

        $link = URL::temporarySignedRoute('email.confirm', now()->addHour(), [
            'user' => $other->id,
            'hash' => sha1('taken@evolet.tj'),
        ]);

        $this->actingAs($user)->get($link)->assertForbidden();
        $this->assertSame('other@evolet.tj', $other->refresh()->email);
    }

    public function test_an_unsigned_link_is_refused()
    {
        $user = $this->colleague(['email' => 'old@evolet.tj']);
        $user->forceFill(['pending_email' => 'new@evolet.tj'])->save();

        $this->actingAs($user)
            ->get("/settings/email/{$user->id}/".sha1('new@evolet.tj'))
            ->assertForbidden();

        $this->assertSame('old@evolet.tj', $user->refresh()->email);
    }

    public function test_asking_for_the_address_already_in_use_drops_what_was_waiting()
    {
        Notification::fake();

        $user = $this->colleague(['email' => 'old@evolet.tj']);
        $user->forceFill(['pending_email' => 'new@evolet.tj'])->save();

        $this->actingAs($user)
            ->patch('/settings/profile', ['email' => 'old@evolet.tj'])
            ->assertSessionHasNoErrors();

        $this->assertNull($user->refresh()->pending_email);
        Notification::assertNothingSent();
    }

    public function test_the_change_can_be_called_off()
    {
        $user = $this->colleague(['email' => 'old@evolet.tj']);
        $user->forceFill(['pending_email' => 'new@evolet.tj'])->save();

        $this->actingAs($user)->delete('/settings/email')->assertRedirect('/settings/profile');

        $this->assertNull($user->refresh()->pending_email);
        $this->assertSame('old@evolet.tj', $user->email);
    }

    public function test_an_address_somebody_else_signs_in_with_is_refused()
    {
        $user = $this->colleague();
        $other = User::factory()->create(['email' => 'taken@evolet.tj']);

        $this->actingAs($user)
            ->patch('/settings/profile', ['email' => $other->email])
            ->assertSessionHasErrors('email');

        $this->assertNull($user->refresh()->pending_email);
    }

    #[DataProvider('addressesFromAnotherAlphabet')]
    public function test_an_address_with_a_letter_from_another_alphabet_is_refused(string $typed)
    {
        Notification::fake();

        $user = $this->colleague(['email' => 'old@evolet.tj']);

        $this->actingAs($user)
            ->patch('/settings/profile', ['email' => $typed])
            ->assertSessionHasErrors('email');

        // Nothing waits for a letter that could never arrive.
        $this->assertNull($user->refresh()->pending_email);
        Notification::assertNothingSent();

        // The address that really is Latin reads the same and goes through.
        $this->actingAs($user)
            ->patch('/settings/profile', ['email' => 'new@evolet.tj'])
            ->assertSessionHasNoErrors();

        $this->assertSame('new@evolet.tj', $user->refresh()->pending_email);
    }

    /**
     * Addresses nobody can tell from a Latin one by looking at them: every «е»
     * below is Cyrillic.
     *
     * @return array<string, array{string}>
     */
    public static function addressesFromAnotherAlphabet(): array
    {
        return [
            'a stray letter in the local part' => ['nеw@evolet.tj'],
            'a stray letter in the domain' => ['new@evolеt.tj'],
            'the whole address' => ['нилуфар@почта.рф'],
        ];
    }

    public function test_nobody_closes_their_own_account()
    {
        $user = $this->colleague();

        // There is no such action to call: the catch-all answers every method for
        // an address with nothing behind it, so the refusal is a 404 rather than
        // "method not allowed". What matters is that the account is still there.
        $this->actingAs($user)->delete('/settings/profile')->assertNotFound();

        $this->assertNotNull($user->fresh());
    }
}
