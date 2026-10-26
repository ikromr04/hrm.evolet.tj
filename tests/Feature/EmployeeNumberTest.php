<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\EmploymentType;
use App\Models\User;
use App\Models\UserDetail;
use Database\Seeders\PositionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * The three lines «Основное» holds beside the position and the department: the
 * number the company files somebody under, the kind of work they were taken on
 * for and the place they work from.
 */
class EmployeeNumberTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RoleSeeder::class, PositionSeeder::class]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'surname' => 'Азимова',
            'name' => 'Нилуфар',
            'sex' => 'female',
            'roles' => [],
            'placements' => [],
            ...$overrides,
        ];
    }

    public function test_an_account_is_numbered_as_it_is_created()
    {
        $employee = User::factory()->create();

        $this->assertSame(User::numberOf($employee->id), $employee->fresh()->employee_number);
        $this->assertSame(5, mb_strlen($employee->fresh()->employee_number));
    }

    public function test_the_number_is_at_least_five_digits_and_may_be_emptied()
    {
        $admin = User::factory()->create()->assignRole('sysadmin');
        $employee = User::factory()->has(UserDetail::factory(), 'details')->create();
        $this->actingAs($admin);

        $this->put("/employees/{$employee->id}/personal", $this->payload(['employee_number' => '1234']))
            ->assertSessionHasErrors('employee_number');
        $this->put("/employees/{$employee->id}/personal", $this->payload(['employee_number' => 'А0001']))
            ->assertSessionHasErrors('employee_number');

        $this->put("/employees/{$employee->id}/personal", $this->payload(['employee_number' => '004217']))
            ->assertSessionHasNoErrors();
        $this->assertSame('004217', $employee->fresh()->employee_number);

        // No two cards carry the same one.
        $other = User::factory()->has(UserDetail::factory(), 'details')->create();
        $this->put("/employees/{$other->id}/personal", $this->payload(['employee_number' => '004217']))
            ->assertSessionHasErrors('employee_number');

        // A box left empty is no number at all rather than five digits of none.
        $this->put("/employees/{$employee->id}/personal", $this->payload(['employee_number' => ' ']))
            ->assertSessionHasNoErrors();
        $this->assertNull($employee->fresh()->employee_number);
    }

    public function test_a_window_saves_only_the_lines_it_carries()
    {
        $admin = User::factory()->create()->assignRole('sysadmin');
        $employee = User::factory()->has(UserDetail::factory(), 'details')->create(['surname' => 'Азимова', 'name' => 'Нилуфар']);
        $employee->details()->update(['nationality' => 'таджичка']);
        $this->actingAs($admin);

        // «Основное» carries neither the name nor the roles, and a save from it
        // leaves both as they were rather than emptying them.
        $this->put("/employees/{$employee->id}/personal", ['employee_number' => '004217'])->assertSessionHasNoErrors();

        $employee->refresh();
        $this->assertSame('004217', $employee->employee_number);
        $this->assertSame('Азимова', $employee->surname);
        $this->assertSame('таджичка', $employee->details->nationality);
    }

    public function test_the_kind_of_work_and_the_branch_are_one_entry_each()
    {
        $admin = User::factory()->create()->assignRole('sysadmin');
        $employee = User::factory()->has(UserDetail::factory(), 'details')->create();
        $full = EmploymentType::create(['name' => 'Полная занятость']);
        $office = Branch::create(['name' => 'Душанбе']);
        $this->actingAs($admin);

        $this->put("/employees/{$employee->id}/personal", $this->payload([
            'employment_type' => 'полная занятость',
            'branch' => 'Душанбе',
        ]))->assertSessionHasNoErrors();

        // Matched without regard to case: the entry on the list, not a second one.
        $this->assertSame([$full->id], EmploymentType::pluck('id')->all());

        $this->assertSame('Полная занятость', $employee->fresh()->employmentType->name);
        $this->assertSame('Душанбе', $employee->fresh()->branch->name);

        // The card reads them back by name.
        $this->get("/employees/{$employee->id}")->assertInertia(fn (AssertableInertia $page) => $page
            ->where('employee.employment_type', 'Полная занятость')
            ->where('employee.branch', 'Душанбе')
            ->where('employee.employee_number', User::numberOf($employee->id))
        );

        // An entry that leaves the directory leaves the card standing.
        $office->delete();
        $this->assertNull($employee->fresh()->branch_id);

        // A kind of work the list has not heard of is written into it here,
        // the way a country typed into the citizenships is.
        $this->put("/employees/{$employee->id}/personal", $this->payload(['employment_type' => 'Подработка']))
            ->assertSessionHasNoErrors();
        $this->assertSame('Подработка', $employee->fresh()->employmentType->name);
        $this->assertTrue(EmploymentType::where('name', 'Подработка')->exists());

        // And an empty box is no entry at all.
        $this->put("/employees/{$employee->id}/personal", $this->payload(['branch' => '']))
            ->assertSessionHasNoErrors();
        $this->assertNull($employee->fresh()->branch_id);
    }
}
