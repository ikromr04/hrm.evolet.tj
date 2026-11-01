<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The site, for the two roles it is not finished for yet.
 *
 * A head of department and a specialist have a card here, and a company has
 * every reason to show them theirs — but what they should see of it, and of
 * anybody else's, has not been decided. Until it is, they are not shown a
 * half-built version and left to guess which half is which: every address
 * answers with one page that says the work is under way, and it says their own
 * name, because somebody who has just typed a password is owed the
 * confirmation that it was accepted.
 *
 * Signing out is the one way out and stays open. The two roles that hold
 * everything are untouched, and so is anybody holding a role of neither kind —
 * which is what keeps the rights of the rest of the system testable at all.
 */
class ClosedSiteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    /**
     * Four addresses from four different corners of the application: the front
     * page, a list, one's own card and a settings page.
     *
     * @return list<string>
     */
    private function addresses(): array
    {
        return ['/', '/employees', '/profile', '/settings/profile'];
    }

    public function test_a_head_of_department_is_shown_the_one_page_wherever_they_go()
    {
        $this->actingAs(User::factory()->create()->assignRole('head'));

        foreach ($this->addresses() as $address) {
            $this->get($address)
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page->component('closed'));
        }
    }

    public function test_a_specialist_is_shown_it_too()
    {
        $this->actingAs(User::factory()->create()->assignRole('specialist'));

        foreach ($this->addresses() as $address) {
            $this->get($address)
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page->component('closed'));
        }
    }

    public function test_the_page_carries_their_own_name_and_the_role_they_hold()
    {
        $user = User::factory()->create(['name' => 'Фарход', 'surname' => 'Рахимов']);
        $user->assignRole('head');

        $this->actingAs($user)
            ->get('/')
            ->assertInertia(fn (Assert $page) => $page
                ->component('closed')
                ->where('name', 'Рахимов Фарход')
                ->where('role', 'Руководитель')
            );
    }

    public function test_a_wrong_address_answers_with_it_rather_than_the_error_page()
    {
        // Nothing is wrong with the address as far as they are concerned: they
        // were never shown a menu to mistype it from.
        $this->actingAs(User::factory()->create()->assignRole('specialist'))
            ->get('/no-such-page')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('closed'));
    }

    public function test_the_sign_in_page_answers_with_it_as_well()
    {
        // They are signed in already, so the page that asks them to sign in has
        // nothing to say — and the middleware stands in front of the guard that
        // would otherwise send them to the front page.
        $this->actingAs(User::factory()->create()->assignRole('head'))
            ->get('/login')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('closed'));
    }

    public function test_signing_out_still_works()
    {
        $this->actingAs(User::factory()->create()->assignRole('specialist'))
            ->post('/logout')
            ->assertRedirect('/');

        $this->assertGuest();

        // And from there they land where anybody not signed in lands.
        $this->get('/')->assertRedirect('/login');
    }

    public function test_the_two_roles_that_hold_everything_are_unaffected()
    {
        foreach (['sysadmin', 'hr'] as $role) {
            $this->actingAs(User::factory()->create()->assignRole($role))
                ->get('/')
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page->component('dashboard'));
        }
    }

    public function test_a_role_the_company_made_for_itself_is_not_shut_out()
    {
        // The two closed roles are named rather than worked out from the two open
        // ones, so a card holding some other role is read by its rights like
        // always — which is what the whole surface of the rights tests rests on.
        $role = Role::create(['name' => 'r-'.Role::count(), 'title' => 'Роль '.Role::count(), 'guard_name' => 'web']);
        $role->syncPermissions(['employees.view']);

        $this->actingAs(User::factory()->create()->assignRole($role))
            ->get('/employees')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('employees/index'));
    }

    public function test_a_guest_still_reaches_the_login_page()
    {
        // Nobody is signed in, so there is no role to read and nothing to close.
        $this->get('/')->assertRedirect('/login');

        $this->get('/login')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('auth/login'));
    }
}
