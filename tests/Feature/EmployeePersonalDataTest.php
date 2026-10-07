<?php

namespace Tests\Feature;

use App\Models\Citizenship;
use App\Models\Department;
use App\Models\Position;
use App\Models\User;
use App\Models\UserDetail;
use Database\Seeders\PositionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * The "Основные данные" card of the profile is edited from its own dialog.
 */
class EmployeePersonalDataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RoleSeeder::class, PositionSeeder::class]);
    }

    private function payload(array $overrides = []): array
    {
        return [
            'surname' => 'Азимова',
            'name' => 'Нилуфар',
            'patronymic' => 'Рустамовна',
            'sex' => 'female',
            'birth_date' => '1990-04-17',
            'birth_place' => 'г. Худжанд',
            'citizenship' => ['Таджикистан'],
            'nationality' => 'таджичка',
            'home_address' => 'г. Душанбе, ул. Рудаки, 25',
            'roles' => [],
            'positions' => [],
            'departments' => [],
            ...$overrides,
        ];
    }

    public function test_citizenship_is_a_list_of_countries()
    {
        $admin = User::factory()->create()->assignRole('sysadmin');
        $employee = User::factory()->has(UserDetail::factory(), 'details')->create();
        $this->actingAs($admin);

        // Trimmed, blanks and repeats dropped; a country new to the directory is added to it.
        $this->put("/employees/{$employee->id}/personal", $this->payload(['citizenship' => [' Таджикистан ', '', 'Россия', 'Таджикистан']]))
            ->assertSessionHasNoErrors();
        $this->assertSame(['Россия', 'Таджикистан'], $employee->citizenships()->pluck('name')->all());
        $this->assertSame(['Россия', 'Таджикистан'], Citizenship::orderBy('name')->pluck('name')->all());

        // Matched without regard to case: the same country, not a second entry.
        $this->put("/employees/{$employee->id}/personal", $this->payload(['citizenship' => ['россия']]))->assertSessionHasNoErrors();
        $this->assertSame(['Россия'], $employee->citizenships()->pluck('name')->all());
        $this->assertSame(2, Citizenship::count());

        $this->put("/employees/{$employee->id}/personal", $this->payload(['citizenship' => ['']]))->assertSessionHasNoErrors();
        $this->assertSame([], $employee->citizenships()->pluck('name')->all());

        $this->put("/employees/{$employee->id}/personal", $this->payload(['citizenship' => [str_repeat('а', 101)]]))
            ->assertSessionHasErrors('citizenship.0');
    }

    public function test_an_admin_edits_the_personal_data_of_an_employee()
    {
        $admin = User::factory()->create()->assignRole('sysadmin');
        $employee = User::factory()->has(UserDetail::factory(), 'details')->create(['sex' => 'male']);

        $this->actingAs($admin)
            ->put("/employees/{$employee->id}/personal", $this->payload())
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $employee->refresh();
        // The name sits on the user, everything else on the details.
        $this->assertSame(['Азимова', 'Нилуфар', 'Рустамовна', 'female'], [
            $employee->surname, $employee->name, $employee->patronymic, $employee->sex,
        ]);
        $this->assertSame('1990-04-17', $employee->details->birth_date->toDateString());
        $this->assertSame('г. Худжанд', $employee->details->birth_place);
        $this->assertSame('таджичка', $employee->details->nationality);
        $this->assertSame('г. Душанбе, ул. Рудаки, 25', $employee->details->home_address);
    }

    public function test_the_details_row_is_created_when_the_employee_has_none()
    {
        $admin = User::factory()->create()->assignRole('sysadmin');
        $employee = $this->colleague();

        $this->actingAs($admin)
            ->put("/employees/{$employee->id}/personal", $this->payload())
            ->assertSessionHasNoErrors();

        $this->assertSame('г. Худжанд', $employee->refresh()->details->birth_place);
    }

    public function test_empty_optional_fields_are_accepted()
    {
        $admin = User::factory()->create()->assignRole('sysadmin');
        $employee = User::factory()->has(UserDetail::factory(), 'details')->create();

        $this->actingAs($admin)
            ->put("/employees/{$employee->id}/personal", [
                'surname' => 'Азимов',
                'name' => 'Рустам',
                'patronymic' => '',
                'sex' => 'male',
                'birth_date' => '',
                'birth_place' => '',
                'citizenship' => '',
                'nationality' => '',
                'home_address' => '',
                'roles' => [],
                'positions' => [],
                'departments' => [],
            ])
            ->assertSessionHasNoErrors();

        $this->assertNull($employee->refresh()->patronymic);
        $this->assertNull($employee->details->birth_date);
        $this->assertNull($employee->details->home_address);
    }

    public function test_invalid_data_is_rejected()
    {
        $admin = User::factory()->create()->assignRole('sysadmin');
        $employee = User::factory()->has(UserDetail::factory(), 'details')->create();

        $this->actingAs($admin)
            ->put("/employees/{$employee->id}/personal", $this->payload([
                'surname' => '',
                'sex' => 'other',
                // Nobody is born tomorrow.
                'birth_date' => now()->addDay()->toDateString(),
            ]))
            ->assertSessionHasErrors(['surname', 'sex', 'birth_date']);
    }

    public function test_an_admin_files_the_employee_under_a_role_position_and_department()
    {
        $admin = User::factory()->create()->assignRole('sysadmin');
        $employee = User::factory()->has(UserDetail::factory(), 'details')->create();
        $position = Position::firstOrFail();
        $department = Department::create(['name' => 'Отдел продаж']);

        $this->actingAs($admin)
            ->put("/employees/{$employee->id}/personal", $this->payload([
                'roles' => ['specialist'],
                'positions' => [$position->id],
                'departments' => [$department->id],
            ]))
            ->assertSessionHasNoErrors();

        $employee->refresh();
        $this->assertSame(['specialist'], $employee->roles->pluck('name')->all());
        $this->assertSame([$position->id], $employee->positions->pluck('id')->all());
        $this->assertSame([$department->id], $employee->departments->pluck('id')->all());
    }

    public function test_a_department_head_keeps_the_flag_when_the_card_is_saved_again()
    {
        $admin = User::factory()->create()->assignRole('sysadmin');
        $employee = User::factory()->has(UserDetail::factory(), 'details')->create();
        $department = Department::create(['name' => 'Отдел продаж']);
        $employee->departments()->attach($department, ['is_head' => true]);

        $this->actingAs($admin)
            ->put("/employees/{$employee->id}/personal", $this->payload(['departments' => [$department->id]]))
            ->assertSessionHasNoErrors();

        $this->assertTrue((bool) $employee->refresh()->departments->first()->pivot->is_head);
    }

    public function test_the_one_position_cannot_be_dropped_from_its_own_card()
    {
        // Positions on one's own card are an ordinary line, opened by an ordinary
        // right — except this one, which cannot be given up because nobody could
        // hand it back.
        $sysadmin = User::factory()->has(UserDetail::factory(), 'details')->create()->assignRole('sysadmin');

        $this->actingAs($sysadmin)
            ->put("/employees/{$sysadmin->id}/personal", $this->payload(['roles' => ['specialist']]))
            ->assertSessionHasErrors('roles');

        $this->assertTrue($sysadmin->fresh()->hasRole('sysadmin'));
    }

    public function test_an_employee_cannot_edit_anyones_personal_data()
    {
        $employee = User::factory()->has(UserDetail::factory(), 'details')->create();

        // Not even their own: the card is managed by HR.
        $this->actingAs($employee)
            ->put("/employees/{$employee->id}/personal", $this->payload())
            ->assertForbidden();

        $this->actingAs($this->colleague())
            ->put("/employees/{$employee->id}/personal", $this->payload())
            ->assertForbidden();
    }

    public function test_the_edit_button_and_suggestions_reach_only_editors()
    {
        $admin = User::factory()->create()->assignRole('sysadmin');
        $employee = User::factory()->has(UserDetail::factory(), 'details')->create(['sex' => 'male']);
        $employee->details()->update(['nationality' => 'таджик']);

        $this->actingAs($admin)
            ->get("/employees/{$employee->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('canEdit', true)
                ->where('options.nationalities', ['таджик'])
            );

        // A colleague gets neither the flag nor the suggestion lists.
        $this->actingAs($this->colleague())
            ->get("/employees/{$employee->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('canEdit', false)
                ->where('options', null)
            );
    }
}
