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

    /**
     * «Нет» is written down, because an empty list cannot say it.
     *
     * A list with no rows in it used to mean both «нигде не учился» and «ещё не
     * спрашивали», and the sheet counted the two alike; the answer is asked
     * outright now, through a door of its own because the records beside it are
     * edited one at a time while this is said about all of them at once.
     */
    public function test_a_card_records_that_there_is_nowhere_this_person_studied()
    {
        $employee = $this->colleague();
        $admin = $this->sysadmin();

        $this->actingAs($admin)
            ->put("/employees/{$employee->id}/educations/answer", ['has_educations' => false])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertFalse($employee->refresh()->details->has_educations);

        // And an answer taken back leaves the question open again rather than
        // standing as «нет»: the two are different things to a department
        // deciding whom to go and ask.
        $this->actingAs($admin)
            ->put("/employees/{$employee->id}/educations/answer", ['has_educations' => null])
            ->assertSessionHasNoErrors();

        $this->assertNull($employee->refresh()->details->has_educations);
    }

    /**
     * «Есть» is not an answer this door takes.
     *
     * The rows are what say there are any, so a card claiming studies it does
     * not list would be the old ambiguity the other way up — and nobody would
     * know which of the two the card meant.
     */
    public function test_saying_there_are_some_without_naming_any_leaves_the_question_open()
    {
        $employee = $this->colleague();

        $this->actingAs($this->sysadmin())
            ->put("/employees/{$employee->id}/educations/answer", ['has_educations' => true])
            ->assertSessionHasNoErrors();

        $this->assertNull($employee->refresh()->details->has_educations);
    }

    /**
     * A record filed answers the question, and the last record taken off asks
     * it again.
     *
     * Not «нет»: a record removed is a correction, and nobody has said this
     * person studied nowhere.
     */
    public function test_a_record_filed_says_there_are_some_and_the_last_one_removed_asks_again()
    {
        $employee = $this->colleague();
        $admin = $this->sysadmin();

        // Answered «нет» first, so what follows is the answer changing rather
        // than being given for the first time.
        $this->actingAs($admin)->put("/employees/{$employee->id}/educations/answer", ['has_educations' => false]);

        $this->actingAs($admin)
            ->post("/employees/{$employee->id}/educations", $this->payload())
            ->assertSessionHasNoErrors();
        $this->assertTrue($employee->refresh()->details->has_educations);

        // A second record, so that the first one going is not the list emptying.
        $this->actingAs($admin)->post("/employees/{$employee->id}/educations", $this->payload(['institution' => 'РТСУ']));

        $records = $employee->refresh()->educations;
        $this->actingAs($admin)->delete("/employees/{$employee->id}/educations/{$records->first()->id}");
        $this->assertTrue($employee->refresh()->details->has_educations);

        $this->actingAs($admin)->delete("/employees/{$employee->id}/educations/{$records->last()->id}");
        $this->assertNull($employee->refresh()->details->has_educations);
    }

    /** And a list filed in one go answers it the same way. */
    public function test_a_list_filed_in_one_go_says_there_are_some()
    {
        $employee = $this->colleague();

        $this->actingAs($this->sysadmin())
            ->post("/employees/{$employee->id}/educations/many", ['records' => [$this->payload()]])
            ->assertSessionHasNoErrors();

        $this->assertTrue($employee->refresh()->details->has_educations);
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
                'document' => UploadedFile::fake()->create('Диплом бакалавра.pdf', 120, 'application/pdf'),
            ]))
            ->assertSessionHasNoErrors();

        $education = $employee->refresh()->educations->first();

        // Filed under the colleague, under a name of the disk's own choosing.
        $this->assertNotNull($education->document_path);
        $this->assertStringStartsWith("employees/{$employee->id}/", $education->document_path);
        Storage::disk('documents')->assertExists($education->document_path);

        // The card shows the name the file arrived under, which is what one
        // presses to open it.
        $this->assertSame('Диплом бакалавра.pdf', $education->document_name);

        // Nothing of it is reachable without being asked: the page hands over an
        // address, and the address weighs the viewer against the card.
        $url = "/employees/{$employee->id}/educations/{$education->id}/document";

        $handed = $this->actingAs($this->sysadmin())->get($url)->assertOk();

        // Shown rather than downloaded: a diploma is looked at far oftener
        // than it is kept, and every paper here is a PDF, which the browser
        // hands to its own reader. And under the name it arrived with — asked
        // of the UTF-8 parameter, because the plain one beside it can only
        // carry ASCII and a Cyrillic name is not that.
        $disposition = (string) $handed->headers->get('content-disposition');
        $this->assertStringStartsWith('inline;', $disposition);
        // Under that name once, not «Диплом бакалавра.pdf.pdf»: the name kept
        // on the row is a file name now, and it already ends in the extension
        // the saved file carries.
        $this->assertStringContainsString(rawurlencode('Диплом бакалавра.pdf'), $disposition);
        $this->assertStringNotContainsString(rawurlencode('.pdf.pdf'), $disposition);

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
            ]))
            ->assertSessionHasErrors('graduated_year');

        $this->assertCount(0, $employee->refresh()->educations);
        $this->assertCount(0, Storage::disk('documents')->allFiles());
    }

    /**
     * The name on the row is the name the file arrived under.
     *
     * There used to be a box of its own beside the file, «Название документа»,
     * which had HR typing «Диплом бакалавра» next to a file already called
     * that. The form lets the name of the file itself be edited before it is
     * sent, so one name travels and it is the one the card shows — and a form
     * still sending the old field says nothing about it.
     */
    public function test_the_name_kept_is_the_name_the_file_arrived_under()
    {
        Storage::fake('documents');
        $employee = $this->colleague();

        $this->actingAs($this->sysadmin())
            ->post("/employees/{$employee->id}/educations", $this->payload([
                'document' => UploadedFile::fake()->create('Диплом магистра 2015.pdf', 10, 'application/pdf'),
                'document_name' => 'Что-то совсем другое',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame('Диплом магистра 2015.pdf', $employee->refresh()->educations->first()->document_name);
    }

    /**
     * Nothing but a PDF, and ten megabytes is the most of one.
     *
     * A photograph of a diploma used to be taken as well as a scan of it, and
     * it is refused now: every document this application keeps is a PDF, so
     * that a reader opens them all the same way and nobody is handed a page
     * photographed at an angle which cannot be printed back out at the size it
     * was.
     */
    public function test_only_a_document_of_the_right_kind_and_size_is_taken()
    {
        Storage::fake('documents');
        $employee = $this->colleague();
        $admin = $this->sysadmin();

        $this->actingAs($admin)
            ->post("/employees/{$employee->id}/educations", $this->payload([
                'document' => UploadedFile::fake()->create('диплом.xlsx', 10, 'application/vnd.ms-excel'),
            ]))
            ->assertSessionHasErrors('document');

        // The two that were taken until November 2026.
        $this->actingAs($admin)
            ->post("/employees/{$employee->id}/educations", $this->payload([
                'document' => UploadedFile::fake()->image('диплом.jpg', 1200, 900),
            ]))
            ->assertSessionHasErrors('document');

        $this->actingAs($admin)
            ->post("/employees/{$employee->id}/educations", $this->payload([
                'document' => UploadedFile::fake()->image('диплом.png', 1200, 900),
            ]))
            ->assertSessionHasErrors('document');

        $this->actingAs($admin)
            ->post("/employees/{$employee->id}/educations", $this->payload([
                'document' => UploadedFile::fake()->create('diploma.pdf', 11 * 1024, 'application/pdf'),
            ]))
            ->assertSessionHasErrors('document');

        // Nothing of the four reached the disk, and no record was written.
        $this->assertCount(0, $employee->refresh()->educations);
        $this->assertCount(0, Storage::disk('documents')->allFiles());

        // And the scan of the same diploma is taken.
        $this->actingAs($admin)
            ->post("/employees/{$employee->id}/educations", $this->payload([
                'document' => UploadedFile::fake()->create('диплом.pdf', 120, 'application/pdf'),
            ]))
            ->assertSessionHasNoErrors();

        $this->assertNotNull($employee->refresh()->educations->first()->document_path);
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
            'document' => UploadedFile::fake()->create('Первый.pdf', 10, 'application/pdf'),
        ]))->assertSessionHasNoErrors();

        $education = $employee->refresh()->educations->first();
        $was = $education->document_path;

        // Replaced: the new one is kept and the old one is not.
        $this->actingAs($admin)->put("/employees/{$employee->id}/educations/{$education->id}", $this->payload([
            'document' => UploadedFile::fake()->create('Второй.pdf', 10, 'application/pdf'),
        ]))->assertSessionHasNoErrors();

        $education->refresh();
        $this->assertNotSame($was, $education->document_path);
        // The row took the new file's name with the new file.
        $this->assertSame('Второй.pdf', $education->document_name);
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

        // And the answer about the list is left where it was on both cards: a
        // record removed is what sets it, so a removal that did not happen must
        // not have said anything either.
        $this->assertNull($owner->refresh()->details?->has_educations);
        $this->assertNull($stranger->refresh()->details?->has_educations);
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
        // The answer about the list is behind the same gate as the records it is
        // about: «нигде не учился» is a line of the card like the records.
        $this->put("/employees/{$employee->id}/educations/answer", ['has_educations' => false])->assertForbidden();

        $this->assertNull($employee->refresh()->details?->has_educations);
    }
}
