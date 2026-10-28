<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Routing\RedirectController;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The table of addresses itself, read the way the company's server reads it.
 *
 * On the server the table is cached, and a cached table is matched differently
 * from the one built on every request: the built one is held by method and
 * address, so a route written further down the file quietly replaces an earlier
 * claim on the same pair, while the cached one keeps both and the first
 * registered answers. Anything that relies on the replacement therefore works
 * in development and fails in production, where nobody can run a test — so the
 * one rule that makes the two agree is asserted here.
 */
class RouteTableTest extends TestCase
{
    /**
     * A redirect left behind for old links answers GET and nothing else.
     *
     * Route::redirect() registers for every method, which is how a form post
     * came to be answered with "go and read the list instead": the redirect
     * standing where «Инвентаризация» used to be read took the POST that files
     * a new unit, the browser followed it, and the unit was never written.
     */
    public function test_a_redirect_for_old_links_answers_only_a_reading(): void
    {
        $redirects = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => str_contains((string) $route->getActionName(), class_basename(RedirectController::class)));

        $this->assertNotEmpty($redirects, 'There are no redirects left for old links; this test is then watching nothing.');

        foreach ($redirects as $route) {
            $this->assertSame(
                ['GET', 'HEAD'],
                $route->methods(),
                "The redirect at {$route->uri()} answers more than a reading, and will take a form's post in a cached table.",
            );
        }
    }

    /**
     * And the post it took: filing a unit reaches the controller that files it,
     * in the table as the server compiles it rather than as a request builds it.
     */
    public function test_filing_a_unit_reaches_the_controller_in_a_cached_table(): void
    {
        // Compiled and put in place exactly as the cache file does it, so what
        // answers here is what answers there.
        $router = app('router');
        $router->setCompiledRoutes($router->getRoutes()->compile());

        $route = $router->getRoutes()->match(Request::create('/equipment', 'POST'));

        $this->assertSame('App\Http\Controllers\EquipmentController@store', $route->getActionName());
    }
}
