<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserDetail;
use App\Support\EmployeeFields;
use Database\Seeders\PositionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Which lines of a card a position may change, and what is done to a colleague
 * rather than to their card.
 *
 * Reading and changing are separate questions: plenty of people should see a
 * passport and nobody but HR should retype one. And moving somebody about,
 * ending an employment and striking a card out are three different rights,
 * because they are three different conversations.
 */
class FieldEditingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RoleSeeder::class, PositionSeeder::class]);
    }

    /** Somebody whose position holds exactly these rights, plus the staff list. */
    private function withRights(string ...$rights): User
    {
        $role = Role::create(['name' => 'r-'.Role::count(), 'title' => 'Роль '.Role::count(), 'guard_name' => 'web']);
        $role->syncPermissions(['employees.view', ...$rights]);

        return User::factory()->create(['surname' => 'Ятимов'])->assignRole($role);
    }

    private function subject(): User
    {
        return User::factory()
            ->has(UserDetail::factory()->state([
                'home_address' => 'Душанбе, Рудаки 55',
                'phone' => '905554433',
                'passport_number' => '1234567',
            ]), 'details')
            ->create(['surname' => 'Азимов', 'name' => 'Далер', 'patronymic' => 'Саидович']);
    }

    /**
     * @return array<string, mixed>
     */
    private function personal(User $employee, array $overrides = []): array
    {
        return [
            'surname' => $employee->surname,
            'name' => $employee->name,
            'patronymic' => $employee->patronymic,
            'sex' => $employee->sex,
            'home_address' => $employee->details?->home_address,
            'roles' => $employee->roles->pluck('name')->all(),
            'placements' => [],
            ...$overrides,
        ];
    }

    public function test_a_block_is_edited_only_by_whoever_may_change_something_in_it()
    {
        $employee = $this->subject();

        // Reads the passport, may change nothing: the form behind it is closed.
        $reader = $this->withRights('employees.field.passport_number');
        $this->actingAs($reader)
            ->put("/employees/{$employee->id}/passport", ['passport_number' => '7654321'])
            ->assertForbidden();

        $editor = $this->withRights('employees.field.passport_number', 'employees.edit.passport_number');
        $this->actingAs($editor)
            ->put("/employees/{$employee->id}/passport", ['passport_number' => '7654321'])
            ->assertRedirect();

        $this->assertSame('7654321', $employee->fresh()->details->passport_number);

        // And the passport says nothing about the family.
        $this->actingAs($editor)
            ->put("/employees/{$employee->id}/family", ['marital_status' => 'single', 'has_children' => 'no', 'children' => []])
            ->assertForbidden();
    }

    public function test_a_line_nobody_may_change_is_not_saved_even_when_the_form_sends_it()
    {
        $employee = $this->subject();

        // May change the patronymic, reads the address but may not touch it. The
        // form of that block carries both, and only one of them lands.
        $editor = $this->withRights(
            'employees.field.patronymic',
            'employees.field.home_address',
            'employees.field.sex',
            'employees.edit.patronymic',
        );

        $this->actingAs($editor)
            ->put("/employees/{$employee->id}/personal", $this->personal($employee, [
                'patronymic' => 'Саидуллоевич',
                'home_address' => 'Другой адрес',
            ]))
            ->assertSessionHasNoErrors();

        $employee->refresh();
        $this->assertSame('Саидуллоевич', $employee->patronymic);
        $this->assertSame('Душанбе, Рудаки 55', $employee->details->home_address);
    }

    public function test_changing_is_never_wider_than_reading()
    {
        // The right to change a line without the right to read it counts for
        // nothing: nobody retypes what they cannot see.
        $editor = $this->withRights('employees.edit.home_address');

        $this->assertSame([], EmployeeFields::editableBy($editor));
        $this->actingAs($editor)
            ->put('/employees/'.$this->subject()->id.'/personal', [])
            ->assertForbidden();
    }

    public function test_the_card_says_which_lines_are_the_viewers_to_change()
    {
        $employee = $this->subject();

        $this->actingAs($this->withRights(
            'employees.field.phone',
            'employees.field.home_address',
            'employees.edit.phone',
        ))
            ->get("/employees/{$employee->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                // The surname and the name are read without a right, so they are on
                // every card whatever the position says.
                ->where('visibleFields', fn ($fields) => collect($fields)->sort()->values()->all() === ['home_address', 'name', 'phone', 'surname'])
                ->where('editableFields', ['phone'])
            );
    }

    public function test_moving_somebody_letting_them_go_and_striking_them_out_are_three_rights()
    {
        $mover = $this->withRights('employees.transfer');
        $firer = $this->withRights('employees.fire');
        $eraser = $this->withRights('employees.delete');

        $first = $this->subject();
        $this->actingAs($mover)->post("/employees/{$first->id}/transfer", ['date' => '2026-09-01', 'note' => 'Перевод'])->assertRedirect();
        $this->actingAs($mover)->post("/employees/{$first->id}/fire", ['date' => '2026-09-01'])->assertForbidden();
        $this->actingAs($mover)->delete("/employees/{$first->id}")->assertForbidden();

        $second = $this->subject();
        $this->actingAs($firer)->post("/employees/{$second->id}/fire", ['date' => '2026-09-01'])->assertRedirect();
        // Taking somebody back is the counterpart of letting them go.
        $this->actingAs($firer)->post("/employees/{$second->id}/restore")->assertRedirect();
        $this->actingAs($firer)->post("/employees/{$second->id}/transfer", ['date' => '2026-09-01', 'note' => 'X'])->assertForbidden();

        $third = $this->subject();
        $this->actingAs($eraser)->delete("/employees/{$third->id}")->assertRedirect();
        $this->assertNull(User::find($third->id));
    }

    public function test_changing_a_line_of_a_card_is_not_adding_a_colleague()
    {
        // Adding somebody is a right of its own: being allowed to retype a line of
        // an existing card says nothing about putting a new person on the books.
        $this->actingAs($this->withRights('employees.field.patronymic', 'employees.edit.patronymic'))
            ->get('/employees/create')
            ->assertForbidden();

        $this->actingAs($this->withRights('employees.create'))->get('/employees/create')->assertOk();
    }

    public function test_why_somebody_left_is_for_whoever_ends_an_employment()
    {
        $employee = $this->subject();
        $employee->update(['status' => 'fired', 'status_note' => 'По собственному желанию']);

        $this->actingAs($this->withRights('employees.transfer'))
            ->get("/employees/{$employee->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('employee.status_note', null));

        $this->actingAs($this->withRights('employees.fire'))
            ->get("/employees/{$employee->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('employee.status_note', 'По собственному желанию'));
    }

    public function test_a_position_is_given_both_kinds_of_right_in_one_save()
    {
        $role = Role::findByName('analyst');

        $this->actingAs(User::factory()->create()->assignRole('sysadmin'))
            ->put("/directories/access/{$role->id}", ['permissions' => [
                'employees.view',
                'employees.field.phone',
                'employees.edit.phone',
                'employees.fire',
            ]])
            ->assertRedirect();

        $this->assertSame(
            ['employees.edit.phone', 'employees.field.phone', 'employees.fire', 'employees.view'],
            $role->fresh()->permissions->pluck('name')->sort()->values()->all(),
        );
    }

    public function test_the_catalogue_offers_both_rights_for_every_line()
    {
        $this->actingAs(User::factory()->create()->assignRole('sysadmin'))
            ->get('/directories/access')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('fields', count(EmployeeFields::GROUPS))
                // A block people pick apart opens up; a passport is one answer.
                ->where('fields', fn ($groups) => collect($groups)->firstWhere('key', 'main')['expandable'] === true
                    && collect($groups)->firstWhere('key', 'passport')['expandable'] === false)
                ->where('fields', fn ($groups) => collect($groups)->firstWhere('key', 'contacts')['fields'][1] === [
                    'key' => 'phone',
                    'title' => 'Телефон',
                    'always' => false,
                    'permission' => 'employees.field.phone',
                    'editPermission' => 'employees.edit.phone',
                ])
            );
    }
}
