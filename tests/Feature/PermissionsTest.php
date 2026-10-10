<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\PermissionOverride;
use App\Models\User;
use App\Support\Access;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Who may do what.
 *
 * A right travels with a position, and can be given to — or taken from — one
 * person in particular. What the two ways add up to is one answer per right,
 * and that answer is what every page, route and gate goes by.
 */
class PermissionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    private function sysadmin(): User
    {
        return User::factory()->create()->assignRole('sysadmin');
    }

    /** Somebody whose position carries exactly the given rights. */
    private function withRights(string ...$rights): User
    {
        $role = Role::create(['name' => 'test-'.Role::count(), 'title' => 'Тестовая позиция '.Role::count(), 'guard_name' => 'web']);
        $role->syncPermissions($rights);

        return User::factory()->create()->assignRole($role);
    }

    public function test_every_right_in_the_catalogue_exists_as_a_row()
    {
        $this->assertSame(Access::keys(), Permission::orderBy('id')->pluck('name')->all());

        // And nothing else: a right that leaves the code leaves the database.
        $this->assertSame(count(Access::keys()), Permission::count());
    }

    public function test_a_right_with_no_row_behind_it_answers_no_rather_than_failing()
    {
        // The catalogue lives in code and the database is told the same list by a
        // seeder. Between the two — a branch with a new right, a database not yet
        // re-seeded — the answer is "no", not a broken page.
        $colleague = $this->colleague();

        $this->assertFalse($colleague->can('employees.teleport'));
        $this->assertFalse($colleague->hasPermissionTo('employees.teleport'));

        // And once the row is gone, a position that held the right loses it too.
        $this->assertTrue($colleague->can('employees.view'));
        Permission::findByName('employees.view')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertFalse($colleague->fresh()->can('employees.view'));
    }

    public function test_a_position_carries_looking_around_and_nothing_more()
    {
        $colleague = User::factory()->create()->assignRole('analyst');

        $this->assertTrue($colleague->can('employees.view'));
        // The fleet comes in parts, and a position starts with its own desk.
        $this->assertTrue($colleague->can('equipment.view.own'));
        $this->assertFalse($colleague->can('equipment.view.department'));
        $this->assertFalse($colleague->can('equipment.view.all'));
        // The structure of the company needs no right at all.
        $this->assertTrue($colleague->can('employees.view'));

        $this->assertFalse($colleague->can('employees.manage'));
        // Looking around includes the lines of a card that were never private,
        // and nothing beyond them.
        $this->assertTrue($colleague->can('employees.field.positions'));
        $this->assertFalse($colleague->can('employees.field.home_address'));
        $this->assertFalse($colleague->can('employees.field.passport_number'));
        $this->assertFalse($colleague->can('equipment.journal.own'));
        $this->assertFalse($colleague->can('directories.view.positions'));
    }

    public function test_a_right_opens_the_page_it_names_and_only_that_page()
    {
        $this->actingAs($this->withRights('equipment.view.all', 'equipment.journal.all'));

        $this->get('/equipment')->assertOk();
        $this->get('/equipment/journal')->assertOk();

        // The fleet is theirs to read, not to change; the staff is neither.
        $this->get('/equipment/create')->assertForbidden();
        $this->get('/employees')->assertForbidden();
        $this->get('/directories/roles')->assertForbidden();
    }

    public function test_reading_a_directory_and_changing_it_are_separate_rights()
    {
        // Read on the job titles, which are kept by two rights of the section
        // even though their page is a section of its own.
        $reader = $this->withRights('directories.view.positions');

        $this->actingAs($reader)->get('/positions')->assertOk();
        $this->actingAs($reader)->post('/positions', ['name' => 'Хакер'])->assertForbidden();

        $editor = $this->withRights('directories.view.positions', 'directories.edit.positions');
        $this->actingAs($editor)->post('/positions', ['name' => 'Аналитик данных'])->assertRedirect();
    }

    public function test_moving_somebody_about_and_striking_them_out_are_separate_rights()
    {
        $colleague = User::factory()->create();
        $mover = $this->withRights('employees.view', 'employees.fire');

        $this->actingAs($mover)->post("/employees/{$colleague->id}/fire", ['date' => '2026-09-01'])->assertRedirect();
        $this->actingAs($mover)->delete("/employees/{$colleague->id}")->assertForbidden();
        $this->assertNotNull(User::find($colleague->id));

        $this->actingAs($this->withRights('employees.view', 'employees.delete'))
            ->delete("/employees/{$colleague->id}")
            ->assertRedirect();
        $this->assertNull(User::find($colleague->id));
    }

    public function test_a_right_given_to_one_person_beats_their_position()
    {
        $colleague = $this->withRights('employees.view', 'equipment.view.all');

        $this->actingAs($colleague)->get('/equipment/journal')->assertForbidden();

        PermissionOverride::create(['user_id' => $colleague->id, 'permission' => 'equipment.journal.all', 'allowed' => true]);

        $this->actingAs($colleague->fresh())->get('/equipment/journal')->assertOk();
    }

    public function test_a_right_taken_from_one_person_beats_their_position_too()
    {
        $colleague = $this->withRights('employees.view', 'employees.field.home_address');

        $this->assertTrue($colleague->can('employees.field.home_address'));

        PermissionOverride::create(['user_id' => $colleague->id, 'permission' => 'employees.field.home_address', 'allowed' => false]);

        $this->assertFalse($colleague->fresh()->can('employees.field.home_address'));
        // What the position gives is untouched: the exception is about this person.
        $this->assertTrue($colleague->fresh()->can('employees.view'));
    }

    public function test_the_access_page_is_a_directory_like_any_other()
    {
        $role = Role::findByName('analyst');

        $this->actingAs($this->sysadmin())
            ->get('/directories/access')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('directories/access')
                ->has('sections', count(Access::SECTIONS))
                // Exactly one row has nothing to tick, and it is the system
                // administrator's: every other position is filled in by hand,
                // «Администратор» along with the rest.
                ->where('roles', fn ($roles) => collect($roles)->where('everything', true)->pluck('name')->all() === ['sysadmin']
                    && collect($roles)->firstWhere('name', 'admin')['permissions'] === Access::defaults())
            );

        // A position gets it the way it gets any other list, and reading the table
        // is not deciding what is in it.
        $reader = $this->withRights('directories.view.access');
        $this->actingAs($reader)->get('/directories/access')->assertOk();
        $this->actingAs($reader)
            ->put("/directories/access/{$role->id}", ['permissions' => ['employees.view']])
            ->assertForbidden();

        // And without the right the page is somebody else's business.
        $this->actingAs($this->withRights('directories.view.positions'))
            ->get('/directories/access')
            ->assertForbidden();
    }

    public function test_the_access_page_saves_what_a_position_may_do()
    {
        $role = Role::findByName('analyst');

        $this->actingAs($this->sysadmin())
            ->put("/directories/access/{$role->id}", ['permissions' => ['employees.view', 'employees.field.home_address']])
            ->assertRedirect();

        $this->assertSame(['employees.field.home_address', 'employees.view'], $role->fresh()->permissions->pluck('name')->sort()->values()->all());

        // Changing the table takes the right for it, not merely reading it.
        $this->actingAs($this->withRights('directories.view.access'))
            ->put("/directories/access/{$role->id}", ['permissions' => []])
            ->assertForbidden();

        // An unknown right is refused, and the one row with nothing to tick is the
        // system administrator's: it answers yes whatever the table holds.
        $this->actingAs($this->sysadmin())
            ->put("/directories/access/{$role->id}", ['permissions' => ['employees.everything']])
            ->assertSessionHasErrors('permissions.0');

        $this->actingAs($this->sysadmin())
            ->put('/directories/access/'.Role::findByName('sysadmin')->id, ['permissions' => []])
            ->assertForbidden();
    }

    public function test_a_personal_exception_is_made_and_called_off_from_the_card()
    {
        $colleague = $this->withRights('employees.view');
        $sysadmin = $this->sysadmin();

        $this->actingAs($sysadmin)
            ->put("/employees/{$colleague->id}/access", ['permission' => 'equipment.journal.all', 'allowed' => true])
            ->assertRedirect();
        $this->assertTrue($colleague->fresh()->can('equipment.journal.all'));

        $this->actingAs($sysadmin)
            ->put("/employees/{$colleague->id}/access", ['permission' => 'equipment.journal.all', 'allowed' => false])
            ->assertRedirect();
        $this->assertFalse($colleague->fresh()->can('equipment.journal.all'));

        // Nothing at all means "back to whatever the position says".
        $this->actingAs($sysadmin)
            ->put("/employees/{$colleague->id}/access", ['permission' => 'equipment.journal.all', 'allowed' => null])
            ->assertRedirect();
        $this->assertSame(0, PermissionOverride::count());
        $this->assertFalse($colleague->fresh()->can('equipment.journal.all'));

        // It takes the same right as the table itself: whoever holds that right
        // may, somebody who merely reads the table may not.
        $this->actingAs($this->withRights('employees.view', 'directories.view.access', 'directories.edit.access'))
            ->put("/employees/{$colleague->id}/access", ['permission' => 'equipment.journal.all', 'allowed' => true])
            ->assertRedirect();

        $this->actingAs($this->withRights('employees.view', 'directories.view.access'))
            ->put("/employees/{$colleague->id}/access", ['permission' => 'equipment.journal.all', 'allowed' => true])
            ->assertForbidden();
    }

    public function test_the_card_shows_a_system_administrator_where_each_right_comes_from()
    {
        $colleague = $this->withRights('employees.view', 'employees.field.home_address');
        PermissionOverride::create(['user_id' => $colleague->id, 'permission' => 'employees.field.home_address', 'allowed' => false]);
        PermissionOverride::create(['user_id' => $colleague->id, 'permission' => 'equipment.journal.all', 'allowed' => true]);

        $right = fn (array $rights, string $key) => collect($rights)->firstWhere('key', $key);

        $this->actingAs($this->sysadmin())
            ->get("/employees/{$colleague->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('access.everything', false)
                ->where('access.rights', fn ($rights) => $right($rights->all(), 'employees.field.home_address') === ['key' => 'employees.field.home_address', 'position' => true, 'override' => false]
                    && $right($rights->all(), 'equipment.journal.all') === ['key' => 'equipment.journal.all', 'position' => false, 'override' => true])
            );

        // Only whoever hands rights out is shown it; a position that merely reads
        // the staff is not.
        $this->actingAs($this->withRights('employees.view', 'directories.view.access', 'directories.edit.access'))
            ->get("/employees/{$colleague->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('access.everything', false));

        $this->actingAs($this->withRights('employees.view'))
            ->get("/employees/{$colleague->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('access', null));
    }

    public function test_everybody_reaches_their_own_card_without_a_single_right()
    {
        $employee = User::factory()->create();

        // At its own address, where no id is asked for and none is guarded.
        $this->actingAs($employee)->get('/profile')->assertOk();
        $this->actingAs($employee)->get("/employees/{$employee->id}")->assertRedirect('/profile');

        // Somebody else's takes the right to read the staff.
        $this->actingAs($employee)->get('/employees/'.User::factory()->create()->id)->assertForbidden();
    }

    public function test_a_position_is_created_with_its_rights_in_one_go()
    {
        $this->actingAs($this->sysadmin())
            ->post('/directories/roles', ['title' => 'Кладовщик', 'permissions' => ['equipment.view.all', 'equipment.issue', 'equipment.take']])
            ->assertRedirect();

        $role = Role::findByName('kladovschik');
        $this->assertSame(
            ['equipment.issue', 'equipment.take', 'equipment.view.all'],
            $role->permissions->pluck('name')->sort()->values()->all(),
        );
    }

    public function test_a_position_created_without_a_word_about_rights_may_look_around()
    {
        // Whoever keeps the positions directory is not offered the list of rights,
        // so a position they create starts with what every position carries rather
        // than with nothing at all.
        $this->actingAs($this->withRights('directories.view.roles', 'directories.edit.roles'))
            ->post('/directories/roles', ['title' => 'Курьер'])
            ->assertRedirect();

        $this->assertSame(collect(Access::defaults())->sort()->values()->all(), Role::findByName('kurer')->permissions->pluck('name')->sort()->values()->all());
    }

    public function test_the_rights_of_a_position_are_edited_from_its_own_dialog()
    {
        $role = Role::findByName('analyst');

        $this->actingAs($this->sysadmin())
            ->put("/directories/roles/{$role->id}", ['title' => 'Аналитик', 'permissions' => ['employees.view', 'employees.field.phone']])
            ->assertRedirect();

        $this->assertSame(['employees.field.phone', 'employees.view'], $role->fresh()->permissions->pluck('name')->sort()->values()->all());
    }

    public function test_renaming_a_position_leaves_its_rights_where_they_are()
    {
        $role = Role::findByName('analyst');
        $held = $role->permissions->pluck('name')->sort()->values()->all();

        // Whoever keeps the positions directory renames them all day; deciding
        // what they open is a right of its own, so a list sent without it changes
        // nothing.
        $keeper = $this->withRights('directories.view.roles', 'directories.edit.roles');

        $this->actingAs($keeper)
            ->put("/directories/roles/{$role->id}", ['title' => 'Аналитик данных', 'permissions' => ['directories.edit.positions']])
            ->assertRedirect();

        $this->assertSame('Аналитик данных', $role->fresh()->title);
        $this->assertSame($held, $role->fresh()->permissions->pluck('name')->sort()->values()->all());
    }

    public function test_the_position_directory_offers_the_rights_to_whoever_decides_on_them()
    {
        $this->actingAs($this->sysadmin())
            ->get('/directories/roles')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('canManageAccess', true)
                ->has('sections', count(Access::SECTIONS))
                ->where('items', fn ($items) => collect(collect($items)->firstWhere('name', 'analyst')['permissions'])->sort()->values()->all() === collect(Access::defaults())->sort()->values()->all())
            );

        // A position that only keeps the positions directory is not offered it.
        $this->actingAs($this->withRights('directories.view.roles'))
            ->get('/directories/roles')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('canManageAccess', false));
    }

    public function test_the_structure_of_the_company_needs_no_right_at_all()
    {
        $department = Department::create(['name' => 'Отдел Дизайна']);
        // Somebody whose position carries nothing whatsoever.
        $nobody = User::factory()->create();

        $this->actingAs($nobody)->get('/departments')->assertOk();
        $this->actingAs($nobody)->get("/departments/{$department->id}")->assertOk();

        // And the search offers it, since there is nowhere it could not go.
        $this->actingAs($nobody)
            ->getJson('/search?q='.urlencode('Дизайн'))
            ->assertOk()
            ->assertJsonPath('departments.0.name', 'Отдел Дизайна');
    }

    public function test_the_search_only_offers_what_the_viewer_may_open()
    {
        $colleague = $this->withRights('equipment.view.all');
        User::factory()->create(['surname' => 'Рахимов', 'name' => 'Фарход']);

        $this->actingAs($colleague)
            ->getJson('/search?q='.urlencode('Рахимов'))
            ->assertOk()
            ->assertJsonCount(0, 'employees')
            ->assertJsonCount(0, 'positions');
    }
}
