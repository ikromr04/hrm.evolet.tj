<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserEducation;
use Database\Seeders\PositionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Education is kept record by record: adding one must not rewrite the rest.
 */
class EmployeeEducationTest extends TestCase
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
            'institution' => 'Таджикский национальный университет',
            'faculty' => 'Экономический',
            'specialty' => 'Финансы и кредит',
            'qualification' => 'бакалавр',
            'started_year' => 2010,
            'graduated_year' => 2015,
            ...$overrides,
        ];
    }

    public function test_an_admin_adds_a_place_of_study_without_touching_the_others()
    {
        $employee = $this->colleague();
        $kept = UserEducation::factory()->for($employee)->create(['institution' => 'Курсы']);

        $this->actingAs($this->sysadmin())
            ->post("/employees/{$employee->id}/educations", $this->payload())
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertEqualsCanonicalizing(
            ['Курсы', 'Таджикский национальный университет'],
            $employee->refresh()->educations->pluck('institution')->all(),
        );
        $this->assertNotNull($kept->fresh());
    }

    public function test_an_admin_changes_one_record()
    {
        $employee = $this->colleague();
        $education = UserEducation::factory()->for($employee)->create();

        $this->actingAs($this->sysadmin())
            ->put("/employees/{$employee->id}/educations/{$education->id}", $this->payload(['faculty' => 'Юридический']))
            ->assertSessionHasNoErrors();

        $this->assertSame('Юридический', $education->fresh()->faculty);
    }

    public function test_an_admin_deletes_one_record()
    {
        $employee = $this->colleague();
        $education = UserEducation::factory()->for($employee)->create();
        $kept = UserEducation::factory()->for($employee)->create();

        $this->actingAs($this->sysadmin())
            ->delete("/employees/{$employee->id}/educations/{$education->id}")
            ->assertSessionHasNoErrors();

        $this->assertNull($education->fresh());
        $this->assertNotNull($kept->fresh());
    }

    public function test_a_record_may_be_left_unfinished()
    {
        $employee = $this->colleague();

        // Still studying: no graduation year and no diploma yet.
        $this->actingAs($this->sysadmin())
            ->post("/employees/{$employee->id}/educations", $this->payload(['graduated_year' => '']))
            ->assertSessionHasNoErrors();

        $education = $employee->refresh()->educations->first();
        $this->assertNull($education->graduated_year);
        $this->assertNull($education->document_path);
    }

    /**
     * The diploma is kept where only this application can reach it, and handed
     * out by a route that asks first.
     *
     * The whole point of the column: a file under the web root is read by
     * whoever holds its address and nobody is ever asked, and a diploma carries
     * a name, a date of birth and a registry number on one page.
     */
    public function test_a_diploma_is_kept_privately_and_handed_to_whoever_may_read_the_card()
    {
        Storage::fake('documents');
        $employee = $this->colleague();

        $this->actingAs($this->sysadmin())
            ->post("/employees/{$employee->id}/educations", $this->payload([
                'document' => UploadedFile::fake()->create('scan_0007.pdf', 120, 'application/pdf'),
                'document_name' => 'Диплом бакалавра',
            ]))
            ->assertSessionHasNoErrors();

        $education = $employee->refresh()->educations->first();

        // Filed under the colleague, under a name of the disk's own choosing.
        $this->assertNotNull($education->document_path);
        $this->assertStringStartsWith("employees/{$employee->id}/", $education->document_path);
        Storage::disk('documents')->assertExists($education->document_path);

        // The name is the one somebody typed, not the one the scanner gave the
        // file: the card shows this, and it is what one presses to open it.
        $this->assertSame('Диплом бакалавра', $education->document_name);

        // Nothing of it is reachable without being asked: the page hands over an
        // address, and the address weighs the viewer against the card.
        $url = "/employees/{$employee->id}/educations/{$education->id}/document";

        $handed = $this->actingAs($this->sysadmin())->get($url)->assertOk();

        // Handed over rather than displayed: a diploma opened in a tab is a
        // diploma the next person at that desk can read. And under the name it
        // arrived with — asked of the UTF-8 parameter, because the plain one
        // beside it can only carry ASCII and a Cyrillic name is not that.
        $disposition = (string) $handed->headers->get('content-disposition');
        $this->assertStringStartsWith('attachment;', $disposition);
        // Under that name, with the extension of the file itself: «Диплом
        // бакалавра» is a document and «Диплом бакалавра.pdf» is a file.
        $this->assertStringContainsString(rawurlencode('Диплом бакалавра.pdf'), $disposition);

        // A colleague who may not read this card's records is refused — the same
        // answer that kept the records off the page in the first place.
        $this->actingAs($this->colleague())->get($url)->assertForbidden();

        // And one person's address cannot reach another person's record.
        $other = $this->colleague();
        $this->actingAs($this->sysadmin())
            ->get("/employees/{$other->id}/educations/{$education->id}/document")
            ->assertNotFound();
    }

    /**
     * A document is proof that the studying ended, so a record cannot carry one
     * and still say it is going on.
     *
     * Refused on the year rather than on the file, because the year is the
     * answer that is missing.
     */
    public function test_a_diploma_without_a_year_of_graduation_is_refused()
    {
        Storage::fake('documents');
        $employee = $this->colleague();

        $this->actingAs($this->sysadmin())
            ->post("/employees/{$employee->id}/educations", $this->payload([
                'graduated_year' => '',
                'document' => UploadedFile::fake()->create('diploma.pdf', 10, 'application/pdf'),
                'document_name' => 'Диплом',
            ]))
            ->assertSessionHasErrors('graduated_year');

        $this->assertCount(0, $employee->refresh()->educations);
        $this->assertCount(0, Storage::disk('documents')->allFiles());
    }

    /**
     * A document arrives with a name for it.
     *
     * The card shows that name as the thing one presses to open the file, so a
     * document without one would be a row with nothing to show — and the name a
     * scanner gives a file («scan_0007.pdf») is not a name anybody would press.
     */
    public function test_a_document_without_a_name_is_refused()
    {
        Storage::fake('documents');
        $employee = $this->colleague();

        $this->actingAs($this->sysadmin())
            ->post("/employees/{$employee->id}/educations", $this->payload([
                'document' => UploadedFile::fake()->create('scan_0007.pdf', 10, 'application/pdf'),
            ]))
            ->assertSessionHasErrors('document_name');

        $this->assertCount(0, $employee->refresh()->educations);
        $this->assertCount(0, Storage::disk('documents')->allFiles());
    }

    /** Nothing but a document: a spreadsheet is not a diploma, and ten megabytes is the most of one. */
    public function test_only_a_document_of_the_right_kind_and_size_is_taken()
    {
        Storage::fake('documents');
        $employee = $this->colleague();

        $this->actingAs($this->sysadmin())
            ->post("/employees/{$employee->id}/educations", $this->payload([
                'document' => UploadedFile::fake()->create('диплом.xlsx', 10, 'application/vnd.ms-excel'),
                'document_name' => 'Диплом',
            ]))
            ->assertSessionHasErrors('document');

        $this->actingAs($this->sysadmin())
            ->post("/employees/{$employee->id}/educations", $this->payload([
                'document' => UploadedFile::fake()->create('diploma.pdf', 11 * 1024, 'application/pdf'),
                'document_name' => 'Диплом',
            ]))
            ->assertSessionHasErrors('document');

        $this->assertCount(0, Storage::disk('documents')->allFiles());
    }

    /**
     * A record that goes takes its diploma with it, and one corrected leaves no
     * copy of the document it used to carry.
     *
     * A file nothing points at any more is a diploma lying on a disk for good,
     * which nobody notices until a backup is restored somewhere it should not
     * have been.
     */
    public function test_the_file_goes_when_the_record_goes_or_changes_its_document()
    {
        Storage::fake('documents');
        $employee = $this->colleague();
        $admin = $this->sysadmin();

        $this->actingAs($admin)->post("/employees/{$employee->id}/educations", $this->payload([
            'document' => UploadedFile::fake()->create('first.pdf', 10, 'application/pdf'),
            'document_name' => 'Первый',
        ]))->assertSessionHasNoErrors();

        $education = $employee->refresh()->educations->first();
        $was = $education->document_path;

        // Replaced: the new one is kept and the old one is not.
        $this->actingAs($admin)->put("/employees/{$employee->id}/educations/{$education->id}", $this->payload([
            'document' => UploadedFile::fake()->create('second.pdf', 10, 'application/pdf'),
            'document_name' => 'Второй',
        ]))->assertSessionHasNoErrors();

        $education->refresh();
        $this->assertNotSame($was, $education->document_path);
        Storage::disk('documents')->assertMissing($was);
        Storage::disk('documents')->assertExists($education->document_path);

        // A correction that says nothing about the document leaves it alone.
        $kept = $education->document_path;
        $this->actingAs($admin)->put("/employees/{$employee->id}/educations/{$education->id}", $this->payload([
            'institution' => 'ТНУ',
        ]))->assertSessionHasNoErrors();

        $this->assertSame($kept, $education->refresh()->document_path);
        Storage::disk('documents')->assertExists($kept);

        // And deleted: the file goes with the row.
        $this->actingAs($admin)->delete("/employees/{$employee->id}/educations/{$education->id}")
            ->assertSessionHasNoErrors();

        Storage::disk('documents')->assertMissing($kept);
    }

    public function test_invalid_data_is_rejected()
    {
        $employee = $this->colleague();

        $this->actingAs($this->sysadmin())
            ->post("/employees/{$employee->id}/educations", $this->payload([
                'institution' => '',
                // Nobody graduates before enrolling.
                'started_year' => 2020,
                'graduated_year' => 2018,
            ]))
            ->assertSessionHasErrors(['institution', 'graduated_year']);
    }

    public function test_a_record_cannot_be_reached_through_another_employee()
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $education = UserEducation::factory()->for($owner)->create(['faculty' => 'Экономический']);

        // The id is real, but it belongs to somebody else.
        $this->actingAs($this->sysadmin())
            ->put("/employees/{$stranger->id}/educations/{$education->id}", $this->payload())
            ->assertNotFound();

        $this->actingAs($this->sysadmin())
            ->delete("/employees/{$stranger->id}/educations/{$education->id}")
            ->assertNotFound();

        $this->assertSame('Экономический', $education->fresh()->faculty);
    }

    public function test_an_employee_cannot_touch_anyones_education()
    {
        $employee = $this->colleague();
        $education = UserEducation::factory()->for($employee)->create();

        // Not even their own: the block is managed by HR.
        $this->actingAs($employee);
        $this->post("/employees/{$employee->id}/educations", $this->payload())->assertForbidden();
        $this->put("/employees/{$employee->id}/educations/{$education->id}", $this->payload())->assertForbidden();
        $this->delete("/employees/{$employee->id}/educations/{$education->id}")->assertForbidden();
    }
}
