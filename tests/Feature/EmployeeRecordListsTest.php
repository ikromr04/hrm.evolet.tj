<?php

namespace Tests\Feature;

use App\Models\Equipment;
use App\Models\EquipmentType;
use App\Models\User;
use App\Models\UserDetail;
use Database\Seeders\EquipmentTypeSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The endpoints of a card that take a whole list at a time rather than a record
 * at a time: where somebody studied, where they worked, and what hardware they
 * are handed. An empty list is an answer, and a bad row is named on the row it
 * happened in.
 *
 * These used to be the last steps of the "new colleague" wizard, which is gone —
 * a new colleague is now written in one request, from one page. The endpoints
 * are not: the card still fills these blocks in, and what each row may hold is
 * described once, in EmployeeCard, and read from there by both.
 */
class EmployeeRecordListsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RoleSeeder::class, EquipmentTypeSeeder::class]);
    }

    /**
     * The one account that passes every check, whatever the rights say: these
     * tests are not about what a position may do.
     */
    private function sysadmin(): User
    {
        return User::factory()->create()->assignRole('sysadmin');
    }

    private function employee(): User
    {
        return User::factory()->has(UserDetail::factory(), 'details')->create();
    }

    public function test_several_places_of_study_are_filed_in_one_request()
    {
        $employee = $this->employee();

        $this->actingAs($this->sysadmin())
            ->post("/employees/{$employee->id}/educations/many", [
                'records' => [
                    [
                        'institution' => 'ТНУ',
                        'faculty' => 'Экономический',
                        'specialty' => 'Финансы',
                        'qualification' => 'бакалавр',
                        'started_year' => 2010,
                        'graduated_year' => 2014,
                    ],
                    [
                        'institution' => 'РТСУ',
                        'faculty' => 'Юридический',
                        'specialty' => 'Право',
                        'qualification' => 'магистр',
                        'started_year' => 2015,
                        'graduated_year' => 2019,
                    ],
                ],
            ])
            ->assertSessionHasNoErrors();

        $this->assertCount(2, $employee->educations);
    }

    public function test_a_bad_record_names_its_own_row()
    {
        $employee = $this->employee();

        $this->actingAs($this->sysadmin())
            ->post("/employees/{$employee->id}/educations/many", [
                'records' => [
                    ['institution' => 'ТНУ', 'faculty' => 'Экономический', 'specialty' => 'Финансы', 'started_year' => 2010],
                    // A diploma cannot predate enrolment, and the faculty is missing.
                    ['institution' => 'РТСУ', 'specialty' => 'Право', 'started_year' => 2015, 'graduated_year' => 2012],
                ],
            ])
            ->assertSessionHasErrors(['records.1.faculty', 'records.1.graduated_year'])
            ->assertSessionDoesntHaveErrors('records.0.institution');

        $this->assertCount(0, $employee->educations);
    }

    public function test_previous_jobs_are_filed_together_and_checked_row_by_row()
    {
        $employee = $this->employee();
        $admin = $this->sysadmin();

        $this->actingAs($admin)
            ->post("/employees/{$employee->id}/experiences/many", [
                'records' => [
                    [
                        'organization' => 'ООО «Ориён»',
                        'position' => 'Аналитик',
                        'country' => 'Таджикистан',
                        'started_month' => 3,
                        'started_year' => 2018,
                        'ended_month' => 8,
                        'ended_year' => 2021,
                    ],
                ],
            ])
            ->assertSessionHasNoErrors();

        $this->assertCount(1, $employee->workExperiences);

        // Leaving before joining is caught on the row it happened in.
        $this->actingAs($admin)
            ->post("/employees/{$employee->id}/experiences/many", [
                'records' => [
                    [
                        'organization' => 'ООО «Ориён»',
                        'position' => 'Аналитик',
                        'country' => 'Таджикистан',
                        'started_month' => 3,
                        'started_year' => 2021,
                        'ended_month' => 8,
                        'ended_year' => 2018,
                    ],
                ],
            ])
            ->assertSessionHasErrors('records.0.ended_year');
    }

    public function test_an_empty_list_files_nothing()
    {
        $employee = $this->employee();
        $admin = $this->sysadmin();

        $this->actingAs($admin)->post("/employees/{$employee->id}/educations/many", ['records' => []])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post("/employees/{$employee->id}/experiences/many", ['records' => []])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post("/employees/{$employee->id}/equipment", ['equipment' => []])->assertSessionHasNoErrors();

        $this->assertCount(0, $employee->educations);
        $this->assertCount(0, $employee->workExperiences);
        $this->assertCount(0, $employee->equipment);
    }

    public function test_a_workplace_is_handed_over_in_one_go()
    {
        $employee = $this->employee();
        $type = EquipmentType::firstWhere('name', 'Ноутбуки');
        $laptop = Equipment::factory()->ofType($type)->create();
        $monitor = Equipment::factory()->ofType($type)->create();
        // Somebody else already has this one, so it stays with them.
        $taken = Equipment::factory()->ofType($type)->issuedTo(User::factory()->create()->id)->create();

        $this->actingAs($this->sysadmin())
            ->post("/employees/{$employee->id}/equipment", [
                'equipment' => [$laptop->id, $monitor->id, $taken->id],
                'issued_at' => '2026-03-02',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('issued', $laptop->refresh()->status);
        $this->assertSame($employee->id, $laptop->holder_user_id);
        $this->assertSame('2026-03-02', $laptop->issued_at->toDateString());
        // The handover is in its history, like any other.
        $this->assertSame($employee->id, $laptop->refresh()->holder_user_id);

        $this->assertSame($employee->id, $monitor->refresh()->holder_user_id);
        $this->assertNotSame($employee->id, $taken->refresh()->holder_user_id);
    }

    public function test_only_managers_file_these_blocks()
    {
        $employee = $this->employee();

        $this->actingAs($this->colleague());
        $this->post("/employees/{$employee->id}/educations/many", ['records' => []])->assertForbidden();
        $this->post("/employees/{$employee->id}/experiences/many", ['records' => []])->assertForbidden();
        $this->post("/employees/{$employee->id}/equipment", ['equipment' => []])->assertForbidden();
    }
}
