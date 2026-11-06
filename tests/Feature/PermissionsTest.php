<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\User;
use App\Support\Access;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Who may do what.
 *
 * A right travels with the position a person holds, and nothing stands beside
 * it: there is one answer per right, and that answer is what every page, route
 * and gate goes by.
 */
class PermissionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
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
        $colleague = $this->withRights(...Access::defaults());

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

        $this->get('/inventory')->assertOk();
        $this->get('/equipment/journal')->assertOk();

        // The fleet is theirs to read, not to change; the staff and the lists the
        // company keeps are neither.
        $this->get('/inventory/equipment/create')->assertForbidden();
        $this->get('/employees')->assertForbidden();
        $this->get('/directories/document_types')->assertForbidden();
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

    public function test_everybody_reaches_their_own_card_without_a_single_right()
    {
        $employee = User::factory()->create();

        // At its own address, where no id is asked for and none is guarded.
        $this->actingAs($employee)->get('/profile')->assertOk();
        $this->actingAs($employee)->get("/employees/{$employee->id}")->assertRedirect('/profile');

        // Somebody else's takes the right to read the staff.
        $this->actingAs($employee)->get('/employees/'.User::factory()->create()->id)->assertForbidden();
    }

    public function test_the_structure_of_the_company_needs_no_right_at_all()
    {
        $department = Department::create(['name' => 'Отдел Дизайна']);
        // Somebody whose position carries nothing whatsoever.
        $nobody = User::factory()->create();

        $this->actingAs($nobody)->get('/org-chart')->assertOk();
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
