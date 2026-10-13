<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * A wrong address.
 *
 * It is answered by a page of the application rather than by the framework's own
 * screen — and it still answers 404, so a link checker and a browser are told the
 * same thing the page says.
 */
class ErrorPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_wrong_address_is_a_page_of_the_application()
    {
        $this->actingAs(User::factory()->create())
            ->get('/no-such-page')
            ->assertNotFound()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('errors/404'));
    }

    public function test_a_visitor_who_is_not_signed_in_gets_it_too()
    {
        // Not a redirect to the login page: the address is wrong whoever asks, and
        // saying "sign in" would send somebody looking for a page that is not there.
        $this->get('/no-such-page')
            ->assertNotFound()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('errors/404')->where('auth.user', null));
    }

    public function test_an_unknown_employee_is_not_found_rather_than_refused()
    {
        $this->actingAs($this->colleague())
            ->get('/employees/999999')
            ->assertNotFound()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('errors/404'));
    }

    public function test_the_api_still_answers_in_json()
    {
        // A page is for a person; a request that asked for data gets data.
        $this->actingAs(User::factory()->create())
            ->getJson('/no-such-page')
            ->assertNotFound()
            ->assertJsonStructure(['message']);
    }

    public function test_a_refusal_is_a_page_of_the_application_too()
    {
        // The page exists and is simply not theirs, which is a different sentence
        // and a different number.
        $this->actingAs(User::factory()->create())
            ->get('/employees')
            ->assertForbidden()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('errors/403'));
    }

    public function test_an_expired_session_sends_them_back_with_a_sentence()
    {
        // Tests run without the token check, so the expiry is raised by hand the
        // way the check would raise it.
        Route::middleware('web')->post('/_qa-expired', fn () => throw new TokenMismatchException);

        $this->actingAs(User::factory()->create())
            ->from('/profile')
            ->post('/_qa-expired')
            ->assertRedirect('/profile')
            ->assertSessionHas('notice');
    }

    public function test_a_failure_of_ours_is_a_page_of_the_application_in_production()
    {
        // With debugging off, as on the company's server, the framework would
        // answer a bare "500 | Server Error".
        config(['app.debug' => false]);
        Route::middleware('web')->get('/_qa-broken', fn () => throw new \RuntimeException('Something of ours broke.'));

        $this->withoutVite()
            ->actingAs(User::factory()->create())
            ->get('/_qa-broken')
            ->assertStatus(500)
            ->assertInertia(fn (AssertableInertia $page) => $page->component('errors/500'))
            // What broke is for the log, not for the page.
            ->assertDontSee('Something of ours broke.');
    }

    public function test_while_debugging_a_failure_keeps_the_trace()
    {
        config(['app.debug' => true]);
        Route::middleware('web')->get('/_qa-broken', fn () => throw new \RuntimeException('Something of ours broke.'));

        $this->actingAs(User::factory()->create())
            ->get('/_qa-broken')
            ->assertStatus(500)
            ->assertSee('Something of ours broke.');
    }

    public function test_a_failure_asked_for_in_json_stays_json()
    {
        config(['app.debug' => false]);
        Route::middleware('web')->get('/_qa-broken', fn () => throw new \RuntimeException('Something of ours broke.'));

        $this->actingAs(User::factory()->create())
            ->getJson('/_qa-broken')
            ->assertStatus(500)
            ->assertExactJson(['message' => 'Server Error']);
    }

    public function test_closed_for_an_update_is_a_page_that_needs_nobody_signed_in()
    {
        // `artisan down` answers before the session is started, so the page is
        // drawn with no shared data at all — hence a route outside the web group.
        config(['app.debug' => false]);
        Route::get('/_qa-down', fn () => abort(503, headers: ['Retry-After' => 60]));

        $this->withoutVite()
            ->get('/_qa-down')
            ->assertStatus(503)
            ->assertHeader('Retry-After', 60)
            ->assertInertia(fn (AssertableInertia $page) => $page->component('errors/503')->missing('auth'));
    }

    public function test_the_update_page_asks_nothing_of_the_server_it_is_shown_from()
    {
        // The React page above is the better one, and it is only reachable while
        // the application can boot. The update deletes `vendor` and `public/build`
        // for a few minutes, so `artisan down --render` draws this one beforehand
        // and `public/index.php` echoes it as a file. Whatever it asked the server
        // for — a stylesheet, a script, a font, the Evolet mark as an image —
        // would be asked of a server that cannot answer.
        $html = view('maintenance')->render();

        $this->assertStringContainsString('Идёт обновление системы', $html);
        $this->assertStringContainsString('Все данные в сохранности', $html);

        foreach (['/build/', '@vite', '<link', '<script src', 'http://', 'https://', 'url('] as $fetched) {
            $this->assertStringNotContainsString($fetched, $html, "The update page reaches for [{$fetched}].");
        }
    }

    public function test_closing_for_an_update_leaves_that_page_on_disk()
    {
        // What the deploy document tells whoever is updating to run. The page has
        // to be sitting in the "down" file complete, because the request that
        // serves it stops before Composer's autoloader is required.
        // Into a storage of its own, never the project's. The file this writes is
        // what every request in the process looks for before anything else, and
        // the suite runs sixteen processes against one storage directory — so
        // closing the real one here would answer 503 to whatever the other
        // fifteen happened to be asking at that moment, in a different test each
        // run. (It did: three unrelated files failed that way before this.)
        $storage = $this->app->storagePath();
        $isolated = sys_get_temp_dir().'/hrm-down-'.getmypid().'-'.uniqid();

        mkdir($isolated.'/framework', 0777, true);
        $this->app->useStoragePath($isolated);

        try {
            $this->artisan('down', ['--render' => 'maintenance', '--retry' => 60])->assertSuccessful();

            $payload = json_decode(file_get_contents($isolated.'/framework/down'), true);

            $this->assertStringContainsString('Идёт обновление системы', $payload['template']);
            $this->assertSame(503, $payload['status']);
            $this->assertSame(60, $payload['retry']);

            // The other half of it: the file `public/index.php` looks for.
            $this->assertFileExists($isolated.'/framework/maintenance.php');
        } finally {
            $this->artisan('up');
            $this->app->useStoragePath($storage);

            foreach ((glob($isolated.'/framework/*') ?: []) as $file) {
                @unlink($file);
            }

            @rmdir($isolated.'/framework');
            @rmdir($isolated);
        }
    }

    public function test_a_refusal_asked_for_in_json_stays_json()
    {
        $this->actingAs(User::factory()->create())
            ->getJson('/employees')
            ->assertForbidden()
            ->assertJsonStructure(['message']);
    }
}
