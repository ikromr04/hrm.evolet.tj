<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserWorkExperience;
use Database\Seeders\PositionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Previous jobs are kept record by record: adding one must not rewrite the rest.
 */
class EmployeeWorkExperienceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RoleSeeder::class, PositionSeeder::class]);
    }

    /**
     * The one account that passes every check, whatever the rights say: these
     * tests are not about what a position may do.
     */
    private function sysadmin(): User
    {
        return User::factory()->create()->assignRole('sysadmin');
    }

    private function payload(array $overrides = []): array
    {
        return [
            'organization' => 'ООО «Шифобахш»',
            'position' => 'Фармацевт',
            'started_month' => 3,
            'started_year' => 2015,
            'ended_month' => 6,
            'ended_year' => 2018,
            'reason' => 'Перевод на другую работу',
            ...$overrides,
        ];
    }

    public function test_an_admin_adds_a_job_without_touching_the_others()
    {
        $employee = $this->colleague();
        $kept = UserWorkExperience::factory()->for($employee)->create();

        $this->actingAs($this->sysadmin())
            ->post("/employees/{$employee->id}/experiences", $this->payload())
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertCount(2, $employee->refresh()->workExperiences);
        $this->assertNotNull($kept->fresh());
    }

    public function test_an_admin_changes_one_record()
    {
        $employee = $this->colleague();
        $job = UserWorkExperience::factory()->for($employee)->create();

        $this->actingAs($this->sysadmin())
            ->put("/employees/{$employee->id}/experiences/{$job->id}", $this->payload(['position' => 'Аналитик']))
            ->assertSessionHasNoErrors();

        $this->assertSame('Аналитик', $job->fresh()->position);
    }

    public function test_an_admin_deletes_one_record()
    {
        $employee = $this->colleague();
        $job = UserWorkExperience::factory()->for($employee)->create();
        $kept = UserWorkExperience::factory()->for($employee)->create();

        $this->actingAs($this->sysadmin())
            ->delete("/employees/{$employee->id}/experiences/{$job->id}")
            ->assertSessionHasNoErrors();

        $this->assertNull($job->fresh());
        $this->assertNotNull($kept->fresh());
    }

    public function test_a_job_the_person_still_holds_has_no_end()
    {
        $employee = $this->colleague();

        $this->actingAs($this->sysadmin())
            // The reason goes with the date, as the form sends it: there is no
            // leaving to give one for.
            ->post("/employees/{$employee->id}/experiences", $this->payload(['ended_month' => '', 'ended_year' => '', 'reason' => '']))
            ->assertSessionHasNoErrors();

        $job = $employee->refresh()->workExperiences->first();
        $this->assertNull($job->ended_month);
        $this->assertNull($job->ended_year);
        $this->assertNull($job->reason);
    }

    /**
     * A job that ended says why: the sheet prints the reason beside the leaving
     * date, in the words a labour book uses.
     */
    public function test_a_job_that_ended_says_why()
    {
        $employee = $this->colleague();

        $this->actingAs($this->sysadmin())
            ->post("/employees/{$employee->id}/experiences", $this->payload())
            ->assertSessionHasNoErrors();

        $this->assertSame('Перевод на другую работу', $employee->refresh()->workExperiences->first()->reason);
    }

    /** And a record that names the leaving date owes that answer. */
    public function test_a_job_that_ended_without_saying_why_is_refused()
    {
        $employee = $this->colleague();

        $this->actingAs($this->sysadmin())
            ->post("/employees/{$employee->id}/experiences", $this->payload(['reason' => '']))
            ->assertSessionHasErrors('reason');

        $this->assertCount(0, $employee->refresh()->workExperiences);
    }

    /**
     * Nobody still working somewhere is asked why they left.
     *
     * The reason is asked for with the leaving date and not before it: a card
     * that insisted on one would be asking them to invent it.
     */
    public function test_a_job_the_person_still_holds_is_not_asked_why_it_ended()
    {
        $employee = $this->colleague();

        $this->actingAs($this->sysadmin())
            ->post("/employees/{$employee->id}/experiences", $this->payload([
                'ended_month' => '',
                'ended_year' => '',
                'reason' => '',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertNull($employee->refresh()->workExperiences->first()->reason);
    }

    /**
     * And is refused one, which is the same tie read the other way round.
     *
     * A record that says somebody still works there and that they left over
     * something is two answers contradicting each other, and whichever of them
     * is wrong, the card is read by somebody who cannot tell which.
     */
    public function test_a_job_the_person_still_holds_cannot_say_why_it_ended()
    {
        $employee = $this->colleague();

        $this->actingAs($this->sysadmin())
            ->post("/employees/{$employee->id}/experiences", $this->payload([
                'ended_month' => '',
                'ended_year' => '',
                'reason' => 'По собственному желанию',
            ]))
            ->assertSessionHasErrors('reason');

        $this->assertCount(0, $employee->refresh()->workExperiences);
    }

    /** One line out of a labour book, not a page: the column holds two hundred characters. */
    public function test_a_reason_longer_than_the_column_is_refused()
    {
        $employee = $this->colleague();

        $this->actingAs($this->sysadmin())
            ->post("/employees/{$employee->id}/experiences", $this->payload(['reason' => str_repeat('я', 201)]))
            ->assertSessionHasErrors('reason');

        $this->assertCount(0, $employee->refresh()->workExperiences);
    }

    public function test_half_an_end_date_is_rejected()
    {
        $employee = $this->colleague();

        // A month without a year says nothing about when the job ended.
        $this->actingAs($this->sysadmin())
            ->post("/employees/{$employee->id}/experiences", $this->payload(['ended_year' => '']))
            ->assertSessionHasErrors('ended_year');
    }

    public function test_dates_must_make_sense()
    {
        $employee = $this->colleague();
        $admin = $this->sysadmin();

        // Left before joining.
        $this->actingAs($admin)
            ->post("/employees/{$employee->id}/experiences", $this->payload([
                'started_month' => 5,
                'started_year' => 2020,
                'ended_month' => 4,
                'ended_year' => 2020,
            ]))
            ->assertSessionHasErrors('ended_year');

        // Nobody starts a past job next year.
        $this->actingAs($admin)
            ->post("/employees/{$employee->id}/experiences", $this->payload([
                'started_month' => 1,
                'started_year' => (int) date('Y') + 1,
                'ended_month' => '',
                'ended_year' => '',
            ]))
            ->assertSessionHasErrors('started_year');
    }

    public function test_a_record_cannot_be_reached_through_another_employee()
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $job = UserWorkExperience::factory()->for($owner)->create(['position' => 'Фармацевт']);

        // The id is real, but it belongs to somebody else.
        $this->actingAs($this->sysadmin())
            ->put("/employees/{$stranger->id}/experiences/{$job->id}", $this->payload())
            ->assertNotFound();

        $this->actingAs($this->sysadmin())
            ->delete("/employees/{$stranger->id}/experiences/{$job->id}")
            ->assertNotFound();

        $this->assertSame('Фармацевт', $job->fresh()->position);
    }

    public function test_an_employee_cannot_touch_anyones_work_experience()
    {
        $employee = $this->colleague();
        $job = UserWorkExperience::factory()->for($employee)->create();

        // Not even their own: the block is managed by HR.
        $this->actingAs($employee);
        $this->post("/employees/{$employee->id}/experiences", $this->payload())->assertForbidden();
        $this->put("/employees/{$employee->id}/experiences/{$job->id}", $this->payload())->assertForbidden();
        $this->delete("/employees/{$employee->id}/experiences/{$job->id}")->assertForbidden();
    }
}
