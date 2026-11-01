<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserDetail;
use App\Support\Access;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UserSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_creates_admin_working_and_former_employees_with_details()
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(77, User::count());
        $this->assertSame(77, UserDetail::count());
        // 1 admin + 67 working + 6 fired + 3 transferred.
        $this->assertSame(68, User::active()->count());
        $this->assertSame(6, User::where('status', 'fired')->count());
        $this->assertSame(3, User::where('status', 'transferred')->count());
        $this->assertSame(1, User::role(Access::SOLE_ROLE)->count());
        $this->assertSame(0, User::whereNull('surname')->orWhereNull('sex')->count());
        $this->assertSame(0, User::where('email', 'not like', '%@evolet.test')->count());
        $this->assertSame(0, UserDetail::whereNull('nationality')->count());

        $this->assertSame(0, User::has('children')->whereHas('details', fn ($q) => $q->where('marital_status', 'single'))->count());

        User::with('details')->get()->each(fn (User $user) => $this->assertContains(
            $user->details->nationality,
            $user->sex === 'female' ? ['таджичка', 'узбечка', 'русская'] : ['таджик', 'узбек', 'русский'],
        ));
    }

    public function test_the_four_roles_are_seeded_with_russian_titles_and_everybody_holds_one()
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(count(Access::ROLES), Role::count());
        $this->assertEqualsCanonicalizing(array_keys(Access::ROLES), Role::pluck('name')->all());

        foreach (Access::ROLES as $name => $title) {
            $this->assertSame($title, Role::findByName($name)->title);
        }

        // Exactly one each, and never a fifth name from anywhere: a person is
        // one thing to the system, and the four are all there is to be.
        $this->assertSame(0, User::doesntHave('roles')->count());
        $this->assertSame(0, User::has('roles', '>', 1)->count());

        // The two that are appointed rather than earned, by name.
        $this->assertSame([Access::SOLE_ROLE], User::role(Access::SOLE_ROLE)->sole()->getRoleNames()->all());
        $this->assertSame([Access::HR], User::firstWhere('email', UserSeeder::PERSONNEL)->getRoleNames()->all());

        // Everybody else holds what their work says, with nothing drawn: whoever
        // holds a leading position leads, and the rest are specialists. No count
        // is written down here on purpose — it follows from the structure, and a
        // number would only say how many heads the demo happened to make.
        User::whereDoesntHave('roles', fn ($q) => $q->whereIn('name', [Access::SOLE_ROLE, Access::HR]))
            ->with('positions')
            ->get()
            ->each(fn (User $user) => $this->assertSame(
                $user->positions->contains('is_head', true) ? Access::HEAD : Access::SPECIALIST,
                $user->getRoleNames()->sole(),
            ));
    }

    public function test_admin_can_log_in_and_passes_every_authorization_check()
    {
        $this->seed(DatabaseSeeder::class);

        $admin = User::firstWhere('email', 'admin@evolet.test');
        $employee = User::firstWhere('email', 'd.nazarova@evolet.test');

        $this->assertTrue(Auth::validate(['email' => 'admin@evolet.test', 'password' => 'password']));
        $this->assertTrue(Gate::forUser($admin)->allows('viewPrivateDetails', $employee));
        $this->assertFalse(Gate::forUser($employee)->allows('viewPrivateDetails', $admin));
    }

    public function test_seeder_can_run_twice_without_duplicates()
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(77, User::count());
        $this->assertSame(77, UserDetail::count());
        $this->assertSame(1, User::role(Access::SOLE_ROLE)->count());
    }
}
