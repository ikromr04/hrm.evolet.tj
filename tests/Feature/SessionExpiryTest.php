<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserDetail;
use Database\Seeders\PositionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * A session that ran out while a dialog sat open.
 *
 * The form is sent, the token with it is stale, and what comes back has to leave
 * the dialog standing with everything typed in it: a sentence to explain, and an
 * error so Inertia calls onError rather than onSuccess, which is what closes it.
 */
class SessionExpiryTest extends TestCase
{
    use RefreshDatabase;

    private const NOTICE = 'Сессия истекла, пока страница была открыта. Повторите действие ещё раз.';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RoleSeeder::class, PositionSeeder::class]);
    }

    /**
     * The token check stands aside while tests run (VerifyCsrfToken::runningUnitTests),
     * and it asks the application which environment it is in. Saying "local" for the
     * length of one test puts the real middleware back on its feet, so the 419 below
     * is raised by the check itself rather than by hand.
     */
    private function withTokenCheck(): void
    {
        $this->app->instance('env', 'local');
    }

    private function payload(array $overrides = []): array
    {
        return [
            'surname' => 'Азимова',
            'name' => 'Нилуфар',
            'patronymic' => 'Рустамовна',
            'sex' => 'female',
            'birth_date' => '1990-04-17',
            'birth_place' => 'г. Худжанд',
            'citizenship' => ['Таджикистан'],
            'nationality' => 'таджичка',
            'home_address' => 'г. Душанбе, ул. Рудаки, 25',
            'roles' => [],
            'positions' => [],
            'departments' => [],
            ...$overrides,
        ];
    }

    public function test_a_stale_token_sends_the_form_back_with_a_sentence_and_an_error()
    {
        $admin = User::factory()->create()->assignRole('sysadmin');
        // Named outright: the factory picks from a list that holds the surname
        // the form below sends, and on the run where it picks that one the
        // check at the end would compare a string with itself and pass.
        $employee = User::factory()->has(UserDetail::factory(), 'details')->create(['surname' => 'Шарипова']);

        $this->withTokenCheck();

        $this->actingAs($admin)
            ->from("/employees/{$employee->id}")
            ->put("/employees/{$employee->id}/personal", $this->payload(['_token' => 'the-token-of-an-hour-ago']))
            ->assertRedirect("/employees/{$employee->id}")
            ->assertSessionHas('notice', self::NOTICE)
            // The key of a field that does not exist, so nothing in the form is
            // marked wrong — but the bag is not empty, and that is what Inertia
            // reads to decide between onSuccess and onError.
            ->assertSessionHasErrors('__session');

        // Nothing was written: the check runs before the controller.
        $this->assertNotSame('Азимова', $employee->fresh()->surname);
    }

    public function test_the_same_form_goes_through_once_the_token_matches()
    {
        // Otherwise the test above would pass on any refusal at all.
        $admin = User::factory()->create()->assignRole('sysadmin');
        $employee = User::factory()->has(UserDetail::factory(), 'details')->create();

        $this->withTokenCheck();
        $this->actingAs($admin);

        // A page first, so there is a started session to take a live token from.
        $this->withoutVite()->get("/employees/{$employee->id}")->assertOk();

        $this->from("/employees/{$employee->id}")
            ->put("/employees/{$employee->id}/personal", $this->payload(['_token' => session()->token()]))
            ->assertSessionHasNoErrors();

        $this->assertSame('Азимова', $employee->fresh()->surname);
    }

    public function test_the_answer_asks_for_the_page_with_get()
    {
        // The answer to an exception never passes Inertia's middleware, which is
        // what turns the 302 of back() into a 303 on a PUT. Left at 302, a browser
        // would repeat the PUT to the address it names.
        $admin = User::factory()->create()->assignRole('sysadmin');
        $employee = User::factory()->has(UserDetail::factory(), 'details')->create();

        $this->withTokenCheck();

        $this->actingAs($admin)
            ->from("/employees/{$employee->id}")
            ->put("/employees/{$employee->id}/personal", $this->payload(['_token' => 'stale']))
            ->assertStatus(303);
    }

    public function test_the_page_they_land_on_carries_the_error_so_the_dialog_stays_open()
    {
        $admin = User::factory()->create()->assignRole('sysadmin');
        $employee = User::factory()->has(UserDetail::factory(), 'details')->create();

        $this->withTokenCheck();

        $this->actingAs($admin)
            ->from("/employees/{$employee->id}")
            ->put("/employees/{$employee->id}/personal", $this->payload(['_token' => 'stale']));

        // Following the redirect the way the browser does: the errors prop that
        // Inertia shares is no longer empty, and the flash carries the sentence.
        $this->withoutVite()
            ->get("/employees/{$employee->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('errors.__session', self::NOTICE)
                ->where('flash.notice', self::NOTICE));
    }

    public function test_a_reading_request_is_left_as_it_was()
    {
        // A GET carries no form and no dialog waits on its answer, so it keeps the
        // plain redirect it always had — an errors bag there would mark a page
        // wrong that nobody had filled in. The check never fires on a GET, so the
        // expiry is raised the way the check would raise it.
        Route::middleware('web')->get('/_qa-expired', fn () => throw new TokenMismatchException);

        $this->actingAs(User::factory()->create())
            ->from('/profile')
            ->get('/_qa-expired')
            ->assertStatus(302)
            ->assertRedirect('/profile')
            ->assertSessionHas('notice', self::NOTICE)
            ->assertSessionHasNoErrors();
    }

    public function test_a_request_that_asked_for_data_still_gets_data()
    {
        Route::middleware('web')->post('/_qa-expired', fn () => throw new TokenMismatchException);

        $this->actingAs(User::factory()->create())
            ->postJson('/_qa-expired')
            ->assertStatus(419)
            ->assertJsonStructure(['message']);
    }
}
