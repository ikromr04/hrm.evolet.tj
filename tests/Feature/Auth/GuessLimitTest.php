<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The forms that take a guess at something and how often they will take one
 * (App\Support\GuessLimit).
 *
 * What is pinned here as much as the counting is the shape of the refusal: a
 * validation error under a field of the form, so that the page the person is
 * looking at says what happened. A 429 from the `throttle` middleware would be
 * the framework's own screen, and inside an Inertia visit a modal with a refusal
 * in it over a form that still looks ready to send — which is why every test
 * below asserts a redirect back with errors rather than a status.
 */
class GuessLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_signing_in_is_still_refused_after_five_attempts(): void
    {
        $user = User::factory()->create();

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'wrong-password'])
                ->assertSessionHasErrors(['email' => __('auth.failed')]);
        }

        $this->post('/login', ['email' => $user->email, 'password' => 'wrong-password'])
            ->assertRedirect();

        // The sentence the sign-in page has always said, counted in seconds.
        $this->assertStringContainsString('Слишком много попыток входа', $this->errorOn('email'));

        // And the right password is of no use until the wait is over.
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_asking_for_a_reset_letter_is_refused_after_three_tries(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        // The first asking sends a letter. The second and the third are refused
        // by Laravel's own broker, which will not write to the same address
        // twice within the minute — and they are counted here all the same,
        // since what this limit is about is the asking.
        $this->post('/forgot-password', ['email' => $user->email])
            ->assertSessionHasNoErrors();

        for ($attempt = 2; $attempt <= 3; $attempt++) {
            $this->post('/forgot-password', ['email' => $user->email])
                ->assertSessionHasErrors(['email' => __('passwords.throttled')]);
        }

        $this->post('/forgot-password', ['email' => $user->email])
            ->assertRedirect();

        $this->assertSame(
            'Ссылку запрашивали слишком часто. Попробуйте ещё раз через 10 минут.',
            $this->errorOn('email'),
        );

        // One letter, however many times it was asked for.
        Notification::assertSentTimes(ResetPassword::class, 1);
    }

    public function test_guessing_a_reset_token_is_refused_after_five_attempts(): void
    {
        $user = User::factory()->create();

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->post('/reset-password', [
                'token' => 'not-the-token-either',
                'email' => $user->email,
                'password' => 'novyi-parol-2026',
                'password_confirmation' => 'novyi-parol-2026',
            ])->assertSessionHasErrors(['email' => __('passwords.token')]);
        }

        $this->post('/reset-password', [
            'token' => 'not-the-token-either',
            'email' => $user->email,
            'password' => 'novyi-parol-2026',
            'password_confirmation' => 'novyi-parol-2026',
        ])->assertRedirect();

        $this->assertSame('Слишком много попыток. Попробуйте ещё раз через 10 минут.', $this->errorOn('email'));
    }

    public function test_confirming_a_password_is_refused_after_five_attempts(): void
    {
        $user = $this->colleague();

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->actingAs($user)
                ->post('/confirm-password', ['password' => 'wrong-password'])
                ->assertSessionHasErrors(['password' => __('auth.password')]);
        }

        $this->actingAs($user)
            ->post('/confirm-password', ['password' => 'wrong-password'])
            ->assertRedirect();

        $this->assertStringContainsString('Слишком много попыток', $this->errorOn('password'));

        // Even the right password waits, which is the point: the form is a
        // sign-in page, and this one is in front of somebody else's open laptop.
        $this->actingAs($user)
            ->post('/confirm-password', ['password' => 'password'])
            ->assertSessionHasErrors('password');
    }

    public function test_changing_a_password_is_refused_after_five_wrong_current_passwords(): void
    {
        $user = $this->colleague();

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->actingAs($user)
                ->from('/settings/password')
                ->put('/settings/password', [
                    'current_password' => 'wrong-password',
                    'password' => 'novyi-parol-2026',
                    'password_confirmation' => 'novyi-parol-2026',
                ])
                ->assertSessionHasErrors('current_password');
        }

        $this->actingAs($user)
            ->from('/settings/password')
            ->put('/settings/password', [
                'current_password' => 'password',
                'password' => 'novyi-parol-2026',
                'password_confirmation' => 'novyi-parol-2026',
            ])
            ->assertRedirect('/settings/password');

        $this->assertStringContainsString('Слишком много попыток', $this->errorOn('current_password'));

        // The right current password was refused along with the rest, so nothing
        // was written.
        $this->assertTrue(Hash::check('password', $user->refresh()->password));
    }

    public function test_a_new_password_that_was_refused_does_not_spend_the_attempts(): void
    {
        $user = $this->colleague();

        // Six goes at the form with the current password right every time and
        // the new one too short: filling a form in badly is not guessing at
        // anything, and six of those would have used up a limit of five.
        for ($attempt = 1; $attempt <= 6; $attempt++) {
            $this->actingAs($user)
                ->from('/settings/password')
                ->put('/settings/password', [
                    'current_password' => 'password',
                    'password' => 'korotko',
                    'password_confirmation' => 'korotko',
                ])
                ->assertSessionHasErrors('password');
        }

        $this->actingAs($user)
            ->from('/settings/password')
            ->put('/settings/password', [
                'current_password' => 'password',
                'password' => 'novyi-parol-2026',
                'password_confirmation' => 'novyi-parol-2026',
            ])
            ->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('novyi-parol-2026', $user->refresh()->password));
    }

    public function test_a_current_password_left_empty_does_not_spend_the_attempts(): void
    {
        $user = $this->colleague();

        // The mistake anybody makes on this page: the two new fields filled in
        // and the current password forgotten — and the page then clears that
        // field, so it is a mistake people make twice in a row. It is not a
        // guess at anybody's password and may be made as often as one likes.
        for ($attempt = 1; $attempt <= 6; $attempt++) {
            $this->actingAs($user)
                ->from('/settings/password')
                ->put('/settings/password', [
                    'current_password' => '',
                    'password' => 'novyi-parol-2026',
                    'password_confirmation' => 'novyi-parol-2026',
                ])
                ->assertSessionHasErrors('current_password');
        }

        $this->actingAs($user)
            ->from('/settings/password')
            ->put('/settings/password', [
                'current_password' => 'password',
                'password' => 'novyi-parol-2026',
                'password_confirmation' => 'novyi-parol-2026',
            ])
            ->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('novyi-parol-2026', $user->refresh()->password));
    }

    public function test_confirming_a_password_with_an_empty_field_does_not_spend_the_attempts(): void
    {
        $user = $this->colleague();

        for ($attempt = 1; $attempt <= 6; $attempt++) {
            $this->actingAs($user)
                ->post('/confirm-password', ['password' => ''])
                ->assertSessionHasErrors('password');
        }

        $this->actingAs($user)
            ->post('/confirm-password', ['password' => 'password'])
            ->assertSessionHasNoErrors();
    }

    public function test_each_form_counts_its_own_attempts(): void
    {
        $user = User::factory()->create();

        // Five wrong tokens are five wrong tokens, and say nothing about whether
        // this person may sign in.
        for ($attempt = 1; $attempt <= 6; $attempt++) {
            $this->post('/reset-password', [
                'token' => 'not-the-token-either',
                'email' => $user->email,
                'password' => 'novyi-parol-2026',
                'password_confirmation' => 'novyi-parol-2026',
            ]);
        }

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasNoErrors();

        $this->assertAuthenticatedAs($user);
    }

    /**
     * The limit per address cannot see a list being walked: every address on it
     * is asked for its first letter, and a first letter is never too many. So
     * the letters are counted a second time without the address at all.
     */
    public function test_a_list_of_addresses_cannot_be_walked_for_a_letter_each(): void
    {
        Notification::fake();

        // Twenty colleagues, each asked for exactly once — within every limit
        // counted per address, and the whole of what the form allows in an hour.
        $staff = User::factory()->count(21)->create();

        foreach ($staff->take(20) as $colleague) {
            $this->post('/forgot-password', ['email' => $colleague->email])
                ->assertSessionHasNoErrors();
        }

        $this->post('/forgot-password', ['email' => $staff->last()->email])
            ->assertRedirect();

        $this->assertStringContainsString('Писем со ссылкой отправлено слишком много', $this->errorOn('email'));

        // Twenty letters went out and the twenty-first did not, so the cap is
        // what refused it rather than anything about that last address.
        Notification::assertSentTimes(ResetPassword::class, 20);
    }

    /**
     * A form refused by one limit does not spend the other's allowance. Asking
     * four times for one address is refused on the fourth by the limit per
     * address, and that refusal must not also count as a letter against the
     * hour — nothing was sent, and a person pressing the button twice would
     * otherwise eat the company's allowance for everybody.
     */
    public function test_a_refusal_for_one_address_is_not_counted_as_a_letter(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        // Three asked for and a fourth refused: four presses, three letters'
        // worth of the hour spent.
        for ($attempt = 1; $attempt <= 4; $attempt++) {
            $this->post('/forgot-password', ['email' => $user->email]);
        }

        // Seventeen left of the twenty, so seventeen other addresses still work
        // and the eighteenth does not.
        $staff = User::factory()->count(18)->create();

        foreach ($staff->take(17) as $colleague) {
            $this->post('/forgot-password', ['email' => $colleague->email])
                ->assertSessionHasNoErrors();
        }

        $this->post('/forgot-password', ['email' => $staff->last()->email])
            ->assertRedirect();

        $this->assertStringContainsString('Писем со ссылкой отправлено слишком много', $this->errorOn('email'));
    }

    /**
     * The sentence the form was left showing under one of its fields.
     */
    private function errorOn(string $field): string
    {
        return (string) session('errors')->first($field);
    }
}
