<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserDetail;
use Database\Seeders\PositionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The "Паспорт" card of the profile is edited from its own dialog.
 */
class EmployeePassportTest extends TestCase
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
            'passport_series' => 'A',
            'passport_number' => '0512345',
            'passport_issued_at' => '2018-06-14',
            'passport_issued_by' => 'МВД Республики Таджикистан',
            'tax_number' => '012345678',
            ...$overrides,
        ];
    }

    public function test_an_admin_edits_the_passport()
    {
        $admin = User::factory()->create()->assignRole('sysadmin');
        $employee = User::factory()->has(UserDetail::factory(), 'details')->create();

        $this->actingAs($admin)
            ->put("/employees/{$employee->id}/passport", $this->payload())
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $details = $employee->refresh()->details;
        $this->assertSame('A', $details->passport_series);
        $this->assertSame('0512345', $details->passport_number);
        $this->assertSame('2018-06-14', $details->passport_issued_at->toDateString());
        $this->assertSame('МВД Республики Таджикистан', $details->passport_issued_by);
        $this->assertSame('012345678', $details->tax_number);
    }

    public function test_the_details_row_is_created_when_the_employee_has_none()
    {
        $admin = User::factory()->create()->assignRole('sysadmin');
        $employee = $this->colleague();

        $this->actingAs($admin)
            ->put("/employees/{$employee->id}/passport", $this->payload())
            ->assertSessionHasNoErrors();

        $this->assertSame('0512345', $employee->refresh()->details->passport_number);
    }

    public function test_the_whole_card_may_stay_empty()
    {
        $admin = User::factory()->create()->assignRole('sysadmin');
        $employee = User::factory()->has(UserDetail::factory(), 'details')->create();

        // A new hire can be on file before their document is.
        $this->actingAs($admin)
            ->put("/employees/{$employee->id}/passport", [
                'passport_series' => '',
                'passport_number' => '',
                'passport_issued_at' => '',
                'passport_issued_by' => '',
                'tax_number' => '',
            ])
            ->assertSessionHasNoErrors();

        $details = $employee->refresh()->details;
        $this->assertNull($details->passport_number);
        $this->assertNull($details->passport_issued_at);
    }

    public function test_invalid_data_is_rejected()
    {
        $admin = User::factory()->create()->assignRole('sysadmin');
        $employee = User::factory()->has(UserDetail::factory(), 'details')->create();

        $this->actingAs($admin)
            ->put("/employees/{$employee->id}/passport", $this->payload([
                // No passport is issued tomorrow, and neither field is this long.
                'passport_issued_at' => now()->addDay()->toDateString(),
                'passport_series' => str_repeat('A', 11),
                'passport_issued_by' => str_repeat('В', 151),
                'tax_number' => str_repeat('0', 21),
            ]))
            ->assertSessionHasErrors(['passport_issued_at', 'passport_series', 'passport_issued_by', 'tax_number']);
    }

    public function test_an_employee_cannot_edit_anyones_passport()
    {
        $employee = User::factory()->has(UserDetail::factory(), 'details')->create();

        // Not even their own: the card is managed by HR.
        $this->actingAs($employee)
            ->put("/employees/{$employee->id}/passport", $this->payload())
            ->assertForbidden();

        $this->actingAs($this->colleague())
            ->put("/employees/{$employee->id}/passport", $this->payload())
            ->assertForbidden();
    }
}
