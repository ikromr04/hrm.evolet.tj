<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rules\Password;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * What a password has to be, wherever one is set: twelve characters with at
 * least one letter and at least one digit in them (AppServiceProvider).
 *
 * The forms that set one are checked through the settings page, since all three
 * of them — this one, the page a reset letter leads to, and `hrm:sysadmin` —
 * ask for `Password::defaults()` and therefore ask for the same thing. That
 * they all do is pinned at the end.
 */
class PasswordPolicyTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('passwordsThatAreNotGoodEnough')]
    public function test_a_password_that_does_not_meet_the_policy_is_refused(string $password): void
    {
        $user = $this->colleague();

        $this->actingAs($user)
            ->from('/settings/password')
            ->put('/settings/password', [
                'current_password' => 'password',
                'password' => $password,
                'password_confirmation' => $password,
            ])
            ->assertSessionHasErrors('password');

        // Nothing was written: the old password is still the one that works.
        $this->assertTrue(Hash::check('password', $user->refresh()->password));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function passwordsThatAreNotGoodEnough(): array
    {
        return [
            'eleven characters, one short' => ['parol-2026x'],
            'eight characters, which used to be enough' => ['parol-26'],
            'long, but nothing except letters' => ['tolkobukvybezcifr'],
            'long, but nothing except digits' => ['2026202620262026'],
        ];
    }

    #[DataProvider('passwordsThatAreGoodEnough')]
    public function test_twelve_characters_with_a_letter_and_a_digit_are_accepted(string $password): void
    {
        $user = $this->colleague();

        $this->actingAs($user)
            ->from('/settings/password')
            ->put('/settings/password', [
                'current_password' => 'password',
                'password' => $password,
                'password_confirmation' => $password,
            ])
            ->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check($password, $user->refresh()->password));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function passwordsThatAreGoodEnough(): array
    {
        return [
            'exactly twelve' => ['parol-2026ab'],
            'two words and a year, which is what people actually type' => ['sinii-slon-2026'],
            // A password is not asked to be unpronounceable, and the interface
            // these people work in is Russian.
            'written in Russian' => ['синий-слон-2026'],
        ];
    }

    public function test_the_same_policy_applies_to_a_password_set_from_a_reset_link(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
            $this->post('/reset-password', [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'tolkobukvybezcifr',
                'password_confirmation' => 'tolkobukvybezcifr',
            ])->assertSessionHasErrors('password');

            return true;
        });

        $this->assertTrue(Hash::check('password', $user->refresh()->password));
    }

    public function test_a_leaked_password_is_looked_up_only_in_production(): void
    {
        $rules = Password::defaults()->appliedRules();

        $this->assertSame(12, $rules['min']);
        $this->assertTrue($rules['letters']);
        $this->assertTrue($rules['numbers']);

        // Nothing in a test or on a developer's machine reaches out to
        // api.pwnedpasswords.com: that would be a request over the network for
        // every password asserted about, including the ones above.
        $this->assertFalse($rules['uncompromised']);

        // The closure form of Password::defaults() is what makes this a decision
        // taken at each use rather than once at boot.
        $this->app['env'] = 'production';

        $this->assertTrue(Password::defaults()->appliedRules()['uncompromised']);
    }
}
