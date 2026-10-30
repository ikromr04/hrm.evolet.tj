<?php

namespace Tests\Feature;

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

    /**
     * What a number may be: the company's own business, down to a shape nobody
     * here prescribes.
     *
     * It used to be refused unless it was five digits or more and nothing but
     * digits. That is no longer asked: a card takes the number it is given, of
     * whatever shape, as long as it fits the column and nobody else carries it.
     * The two things still asked are the two that are not about taste — a
     * number two cards share names neither of them, and a box left empty is no
     * number rather than a number of none.
     */
    public function test_a_number_is_taken_as_given_but_must_be_the_only_one()
    {
        $admin = User::factory()->create()->assignRole('sysadmin');
        $employee = User::factory()->has(UserDetail::factory(), 'details')->create();
        $this->actingAs($admin);

        // Short, and written with a letter in it: both stand.
        foreach (['1234', 'А0001', '004217'] as $number) {
            $this->put("/employees/{$employee->id}/personal", $this->payload(['employee_number' => $number]))
                ->assertSessionHasNoErrors();
            $this->assertSame($number, $employee->fresh()->employee_number);
        }

        // Longer than the column, which is the one shape still refused.
        $this->put("/employees/{$employee->id}/personal", $this->payload(['employee_number' => str_repeat('7', 21)]))
            ->assertSessionHasErrors('employee_number');

        // No two cards carry the same one.
        $other = User::factory()->has(UserDetail::factory(), 'details')->create();
        $this->put("/employees/{$other->id}/personal", $this->payload(['employee_number' => '004217']))
            ->assertSessionHasErrors('employee_number');

        // A box left empty is no number at all.
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

    /**
     * The kind of work: one entry of a list, named rather than numbered.
     *
     * It used to share this test with the branch somebody worked from. That
     * list is gone — the company does not file its people by where they sit —
     * and the kind of work is now the only line of a card that points at a
     * list this way, so everything the pair proved between them is proved here
     * of the one that is left.
     */
    public function test_the_kind_of_work_is_one_entry_of_its_list()
    {
        $admin = User::factory()->create()->assignRole('sysadmin');
        $employee = User::factory()->has(UserDetail::factory(), 'details')->create();
        $full = EmploymentType::create(['name' => 'Полная занятость']);
        $this->actingAs($admin);

        $this->put("/employees/{$employee->id}/personal", $this->payload([
            'employment_type' => 'полная занятость',
        ]))->assertSessionHasNoErrors();

        // Matched without regard to case: the entry on the list, not a second one.
        $this->assertSame([$full->id], EmploymentType::pluck('id')->all());
        $this->assertSame('Полная занятость', $employee->fresh()->employmentType->name);

        // The card reads it back by name.
        $this->get("/employees/{$employee->id}")->assertInertia(fn (AssertableInertia $page) => $page
            ->where('employee.employment_type', 'Полная занятость')
            ->where('employee.employee_number', User::numberOf($employee->id))
        );

        // One the list has not heard of is written into it here, the way a
        // country typed into the citizenships is.
        $this->put("/employees/{$employee->id}/personal", $this->payload(['employment_type' => 'Подработка']))
            ->assertSessionHasNoErrors();
        $this->assertSame('Подработка', $employee->fresh()->employmentType->name);
        $this->assertTrue(EmploymentType::where('name', 'Подработка')->exists());

        // An empty box is no entry at all.
        $this->put("/employees/{$employee->id}/personal", $this->payload(['employment_type' => '']))
            ->assertSessionHasNoErrors();
        $this->assertNull($employee->fresh()->employment_type_id);

        // And an entry that leaves the directory leaves the card standing, with
        // that one line empty.
        $employee->update(['employment_type_id' => $full->id]);
        $full->delete();
        $this->assertNull($employee->fresh()->employment_type_id);
    }
}
