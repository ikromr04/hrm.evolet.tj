<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Equipment;
use App\Models\EquipmentType;
use App\Models\Language;
use App\Models\Position;
use App\Models\User;
use App\Support\Access;
use Database\Seeders\ProductionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * What the company's own installation starts with: the rights and the four
 * roles — and not one list, person or laptop made up for it.
 */
class ProductionSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_brings_the_rights_and_the_four_roles()
    {
        $this->seed(ProductionSeeder::class);

        $this->assertEqualsCanonicalizing(Access::keys(), Permission::pluck('name')->all());

        // All four, not just the one the system cannot work without: there is no
        // page left on which a company could add the other three for itself.
        $this->assertEqualsCanonicalizing(array_keys(Access::ROLES), Role::pluck('name')->all());

        foreach (Access::ROLES as $name => $title) {
            $role = Role::findByName($name);

            $this->assertSame($title, $role->title);
            // Nothing is hung on a role: two of the four pass every check by
            // themselves and the other two wait for a site that is open to them.
            $this->assertSame(0, $role->permissions()->count());
        }
    }

    public function test_the_directories_start_empty()
    {
        $this->seed(ProductionSeeder::class);

        $this->assertSame(0, Department::count());
        $this->assertSame(0, Position::count());
        $this->assertSame(0, Language::count());
        $this->assertSame(0, EquipmentType::count());
    }

    public function test_it_invents_no_people_and_no_hardware()
    {
        $this->seed(ProductionSeeder::class);

        // The administrator is asked for at a terminal, never made up: with
        // nobody to ask, as here, there is no account and no invented password.
        $this->assertSame(0, User::count());
        $this->assertSame(0, Equipment::count());
    }

    public function test_it_says_how_to_create_the_administrator_when_it_cannot_ask()
    {
        $this->artisan('db:seed', ['--class' => ProductionSeeder::class])
            ->expectsOutputToContain('php artisan hrm:sysadmin')
            ->assertSuccessful();

        // Once there is one, there is nothing left to say.
        User::factory()->create()->assignRole(Access::SOLE_ROLE);

        $this->artisan('db:seed', ['--class' => ProductionSeeder::class])
            ->doesntExpectOutputToContain('php artisan hrm:sysadmin')
            ->assertSuccessful();
    }

    public function test_a_second_run_leaves_what_the_company_changed_alone()
    {
        $this->seed(ProductionSeeder::class);

        // Somebody has been at work in «Справочники» since the first day. Two of
        // the four are renamed rather than one: a role is looked for by its name
        // and made only where there is none, so a run that wrote the titles back
        // would be caught whichever of them it started with.
        Role::findByName(Access::SOLE_ROLE)->update(['title' => 'Главный администратор']);
        Role::findByName(Access::HEAD)->update(['title' => 'Начальник подразделения']);
        $position = Role::create(['name' => 'accountant', 'guard_name' => 'web', 'title' => 'Бухгалтер']);
        $position->givePermissionTo('employees.view');
        Department::create(['name' => 'Бухгалтерия']);

        $this->seed(ProductionSeeder::class);

        $this->assertSame('Главный администратор', Role::findByName(Access::SOLE_ROLE)->title);
        $this->assertSame('Начальник подразделения', Role::findByName(Access::HEAD)->title);
        $this->assertSame(count(Access::ROLES) + 1, Role::count());
        $this->assertSame(['employees.view'], Role::findByName('accountant')->permissions->pluck('name')->all());
        $this->assertSame(1, Department::count());
    }

    public function test_a_second_run_brings_a_right_added_by_an_update()
    {
        $this->seed(ProductionSeeder::class);

        // As the database looked before the update that introduced this right.
        Permission::findByName('employees.fire')->delete();

        $this->seed(ProductionSeeder::class);

        $this->assertTrue(Permission::where('name', 'employees.fire')->exists());
    }

    public function test_a_role_is_restored_if_it_is_missing()
    {
        $this->seed(ProductionSeeder::class);

        Role::findByName(Access::SOLE_ROLE)->delete();

        $this->seed(ProductionSeeder::class);

        $this->assertEqualsCanonicalizing(array_keys(Access::ROLES), Role::pluck('name')->all());
        $this->assertSame(Access::ROLES[Access::SOLE_ROLE], Role::findByName(Access::SOLE_ROLE)->title);
    }

    public function test_a_plain_db_seed_in_production_is_the_careful_one()
    {
        $this->app['env'] = 'production';

        // In production the command asks before it runs; --force is the answer.
        $this->artisan('db:seed', ['--force' => true, '--no-interaction' => true])->assertSuccessful();

        $this->assertSame(0, User::count());
        $this->assertEqualsCanonicalizing(array_keys(Access::ROLES), Role::pluck('name')->all());
    }
}
