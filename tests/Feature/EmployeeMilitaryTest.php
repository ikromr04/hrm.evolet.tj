<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserDetail;
use Database\Seeders\PositionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The «Отношение к воинской обязанности» block: one line of text, and the one
 * document behind it — the военный билет, or the page of it that says this.
 *
 * The third block kept on the degree's terms down to the addresses, so this file
 * is the degree's and the award's too: the line is saved whole, the way the
 * passport and the contacts are, because there is one question here and the
 * answer is a sentence; the document is the part that is not a column, it comes
 * with a save and replaces whatever was on file, while taking it away is a
 * request of its own.
 */
class EmployeeMilitaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RoleSeeder::class, PositionSeeder::class]);
    }

    /**
     * The one account that passes every check, whatever the rights say: most of
     * these tests are not about what a position may do.
     */
    private function sysadmin(): User
    {
        return User::factory()->create()->assignRole('sysadmin');
    }

    /** Somebody whose position holds exactly these rights, plus the staff list. */
    private function withRights(string ...$rights): User
    {
        $role = Role::create(['name' => 'r-'.Role::count(), 'title' => 'Роль '.Role::count(), 'guard_name' => 'web']);
        $role->syncPermissions(['employees.view', ...$rights]);

        return User::factory()->create()->assignRole($role);
    }

    /** A card with the row the block lives on already filed. */
    private function employee(?string $military = null): User
    {
        return $this->mayLookAround(
            User::factory()->has(UserDetail::factory()->state(['military' => $military]), 'details')->create(),
        );
    }

    /** A paper of the kind the block takes, under the name it was scanned as. */
    private function paper(string $name): UploadedFile
    {
        return UploadedFile::fake()->create($name, 120, 'application/pdf');
    }

    /**
     * A document already on the card, filed the way a save files one: the
     * columns, and the file behind them on the private disk.
     */
    private function attach(User $employee, string $name): string
    {
        $path = Storage::disk('documents')->putFile("employees/{$employee->id}", $this->paper($name));

        $employee->details->update(['military_document_path' => $path, 'military_document_name' => $name]);

        return $path;
    }

    /**
     * The line as somebody typed it, and as nothing when they cleared the box.
     *
     * The two have to be told apart from a save that did not carry the line at
     * all: the block is sent by a form which may be showing only the document,
     * and such a form must not read as «nothing is known about this».
     */
    public function test_an_admin_retypes_the_line_and_an_emptied_box_blanks_it()
    {
        $employee = $this->employee();
        $admin = $this->sysadmin();

        $this->actingAs($admin)
            ->put("/employees/{$employee->id}/military", ['military' => 'Военнообязанный, старший лейтенант запаса'])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertSame('Военнообязанный, старший лейтенант запаса', $employee->details->fresh()->military);

        // Cleared: the box arrives empty, which is an answer and not a silence.
        $this->actingAs($admin)
            ->put("/employees/{$employee->id}/military", ['military' => ''])
            ->assertSessionHasNoErrors();

        $this->assertNull($employee->details->fresh()->military);

        // And a save that says nothing about the line leaves it where it was.
        $this->actingAs($admin)
            ->put("/employees/{$employee->id}/military", ['military' => 'Невоеннообязанный'])
            ->assertSessionHasNoErrors();

        $this->actingAs($admin)
            ->put("/employees/{$employee->id}/military", [])
            ->assertSessionHasNoErrors();

        $this->assertSame('Невоеннообязанный', $employee->details->fresh()->military);
    }

    /** One line and not a page of them: the column holds two hundred characters. */
    public function test_a_line_longer_than_the_column_is_refused()
    {
        $employee = $this->employee('Военнообязанный');

        $this->actingAs($this->sysadmin())
            ->put("/employees/{$employee->id}/military", ['military' => str_repeat('я', 201)])
            ->assertSessionHasErrors('military');

        $this->assertSame('Военнообязанный', $employee->details->fresh()->military);
    }

    /**
     * A save brings the document along with the line, and the card keeps where
     * the file went and what it is called.
     */
    public function test_a_save_files_the_document_with_the_line()
    {
        Storage::fake('documents');
        $employee = $this->employee();

        $this->actingAs($this->sysadmin())->put("/employees/{$employee->id}/military", [
            'military' => 'Военнообязанный, старший лейтенант запаса',
            'military_document' => $this->paper('Военный билет.pdf'),
        ])->assertSessionHasNoErrors()->assertRedirect();

        $details = $employee->details->fresh();

        $this->assertSame('Военнообязанный, старший лейтенант запаса', $details->military);

        // Filed under the colleague, under a name of the disk's own choosing...
        $this->assertStringStartsWith("employees/{$employee->id}/", $details->military_document_path);
        Storage::disk('documents')->assertExists($details->military_document_path);

        // ...while the name the card shows is the one the file arrived under:
        // the line above has already said which paper this is, so nobody is
        // asked to type a name for it.
        $this->assertSame('Военный билет.pdf', $details->military_document_name);
    }

    /**
     * One document, so a new one takes the old one's place — and the old file
     * goes off the disk with the column that named it.
     *
     * A file nothing points at any more is a военный билет lying on a disk for
     * good, which nobody notices until a backup is restored somewhere it should
     * not have been.
     */
    public function test_a_new_document_replaces_the_one_on_file_and_the_old_file_goes()
    {
        Storage::fake('documents');
        $employee = $this->employee('Военнообязанный, старший лейтенант запаса');
        $admin = $this->sysadmin();

        $was = $this->attach($employee, 'Старый скан.pdf');

        $this->actingAs($admin)->put("/employees/{$employee->id}/military", [
            // The line travels with it, as the form sends it: a document is not
            // taken without one.
            'military' => 'Военнообязанный, старший лейтенант запаса',
            'military_document' => $this->paper('Военный билет.pdf'),
        ])->assertSessionHasNoErrors();

        $details = $employee->details->fresh();

        $this->assertNotSame($was, $details->military_document_path);
        $this->assertSame('Военный билет.pdf', $details->military_document_name);
        Storage::disk('documents')->assertMissing($was);
        Storage::disk('documents')->assertExists($details->military_document_path);

        // Nothing is left over: one document means one file on the disk.
        $this->assertCount(1, Storage::disk('documents')->allFiles());
    }

    /**
     * A save that brings no document leaves the one on file alone.
     *
     * Not every save is about the document — the block is one form, and
     * retyping the line is the commonest thing done in it — so an absent file
     * cannot read as «take it away». That is what the DELETE beside it is for.
     */
    public function test_a_save_without_a_document_leaves_the_one_on_file_alone()
    {
        Storage::fake('documents');
        $employee = $this->employee();
        $kept = $this->attach($employee, 'Военный билет.pdf');

        $this->actingAs($this->sysadmin())
            ->put("/employees/{$employee->id}/military", ['military' => 'Военнообязанный, старший лейтенант запаса'])
            ->assertSessionHasNoErrors();

        $details = $employee->details->fresh();

        $this->assertSame('Военнообязанный, старший лейтенант запаса', $details->military);
        $this->assertSame($kept, $details->military_document_path);
        $this->assertSame('Военный билет.pdf', $details->military_document_name);
        Storage::disk('documents')->assertExists($kept);
    }

    /**
     * «Нет» is an answer, and an empty column is not the same one.
     *
     * A card nobody has asked about and a card whose answer was none read alike
     * on paper — both of them show nothing — so the line says which: HR would
     * otherwise go round asking the same colleague every quarter.
     */
    public function test_the_line_can_say_there_is_none()
    {
        $employee = $this->employee();

        $this->actingAs($this->sysadmin())
            ->put("/employees/{$employee->id}/military", ['military' => 'Нет'])
            ->assertSessionHasNoErrors();

        $this->assertSame('Нет', $employee->details->fresh()->military);
    }

    /**
     * And «Нет» takes the paper already on file with it.
     *
     * The rules refuse a document sent under «Нет»; this is the same card
     * two saves later, which is how it would really happen — the answer was
     * one thing, somebody asked again, and it is another. A card that said
     * there is none and still showed a scan of it would be two answers
     * contradicting each other.
     */
    public function test_saying_there_is_none_takes_the_document_off_the_card()
    {
        Storage::fake('documents');
        $employee = $this->employee();
        $admin = $this->sysadmin();

        $this->actingAs($admin)->put("/employees/{$employee->id}/military", [
            'military' => 'Есть что показать',
            'military_document' => $this->paper('Военный билет.pdf'),
        ])->assertSessionHasNoErrors();

        $was = $employee->details->fresh()->military_document_path;

        $this->actingAs($admin)
            ->put("/employees/{$employee->id}/military", ['military' => 'Нет'])
            ->assertSessionHasNoErrors();

        $details = $employee->details->fresh();

        $this->assertSame('Нет', $details->military);
        $this->assertNull($details->military_document_path);
        $this->assertNull($details->military_document_name);
        Storage::disk('documents')->assertMissing($was);
    }

    /**
     * A document with no line beside it is refused.
     *
     * Not because a card must say anything here — plenty of them do not — but
     * because a card that shows a paper and answers nothing is worse than one
     * that shows neither: whoever reads it is left to open the scan and work out
     * what it was for.
     */
    public function test_a_document_is_not_taken_without_the_line()
    {
        Storage::fake('documents');
        $employee = $this->employee();

        $this->actingAs($this->sysadmin())->put("/employees/{$employee->id}/military", [
            'military' => '',
            'military_document' => $this->paper('Военный билет.pdf'),
        ])->assertSessionHasErrors('military');

        $this->assertNull($employee->details->fresh()->military_document_path);
        $this->assertCount(0, Storage::disk('documents')->allFiles());
    }

    /**
     * And the same tie read the other way round: «Нет» takes no document.
     *
     * A card that said there is none and showed a scan of it would be two
     * answers contradicting each other, and whoever reads it would have to open
     * the file to find out which of them to believe.
     */
    public function test_a_document_is_not_taken_under_a_line_that_says_there_is_none()
    {
        Storage::fake('documents');
        $employee = $this->employee();

        $this->actingAs($this->sysadmin())->put("/employees/{$employee->id}/military", [
            'military' => 'Нет',
            'military_document' => $this->paper('Военный билет.pdf'),
        ])->assertSessionHasErrors('military_document');

        $details = $employee->details->fresh();
        $this->assertNull($details->military);
        $this->assertNull($details->military_document_path);
        $this->assertCount(0, Storage::disk('documents')->allFiles());
    }

    /** Nothing but a document: a spreadsheet is not a военный билет, and ten megabytes is the most of one. */
    public function test_only_a_document_of_the_right_kind_and_size_is_taken()
    {
        Storage::fake('documents');
        $employee = $this->employee();

        $this->actingAs($this->sysadmin())->put("/employees/{$employee->id}/military", [
            'military_document' => UploadedFile::fake()->create('военный билет.xlsx', 10, 'application/vnd.ms-excel'),
        ])->assertSessionHasErrors('military_document');

        $this->actingAs($this->sysadmin())->put("/employees/{$employee->id}/military", [
            'military_document' => UploadedFile::fake()->create('Военный билет.pdf', 11 * 1024, 'application/pdf'),
        ])->assertSessionHasErrors('military_document');

        $this->assertNull($employee->details->fresh()->military_document_path);
        $this->assertCount(0, Storage::disk('documents')->allFiles());
    }

    /**
     * The document is kept where only this application can reach it, and handed
     * out by a route that asks first.
     *
     * The whole point of keeping it off the web root: a file under it is read by
     * whoever holds its address and nobody is ever asked, and a военный билет
     * carries a name, a date of birth and a registry number on one page.
     */
    public function test_a_document_is_kept_privately_and_handed_under_its_own_name()
    {
        Storage::fake('documents');
        $employee = $this->employee();

        $this->actingAs($this->sysadmin())->put("/employees/{$employee->id}/military", [
            'military' => 'Военнообязанный, старший лейтенант запаса',
            'military_document' => $this->paper('Военный билет.pdf'),
        ])->assertSessionHasNoErrors();

        $handed = $this->actingAs($this->sysadmin())
            ->get("/employees/{$employee->id}/military/document")
            ->assertOk();

        // Handed over rather than displayed: a document opened in a tab is a
        // document the next person at that desk can read. And under the name it
        // arrived with — asked of the UTF-8 parameter, because the plain one
        // beside it can only carry ASCII and a Cyrillic name is not that.
        $disposition = (string) $handed->headers->get('content-disposition');
        $this->assertStringStartsWith('attachment;', $disposition);
        $this->assertStringContainsString(rawurlencode('Военный билет.pdf'), $disposition);
    }

    /**
     * A colleague who may not read the block is refused the document behind it.
     *
     * The route carries no gate of its own: it asks the question the page that
     * shows the block asks, so the answer here is the answer that kept the
     * document off that page in the first place.
     */
    public function test_a_document_is_not_handed_to_whoever_may_not_read_the_block()
    {
        Storage::fake('documents');
        $employee = $this->employee();
        $this->attach($employee, 'Военный билет.pdf');

        $this->actingAs($this->colleague())
            ->get("/employees/{$employee->id}/military/document")
            ->assertForbidden();
    }

    /**
     * Reading the block is not changing it.
     *
     * The line and its document are open to more people than may retype them —
     * what a person owes the army is asked of them at the door of every office
     * — so the read right alone opens the document and nothing else.
     */
    public function test_reading_the_block_is_not_changing_it()
    {
        Storage::fake('documents');
        $employee = $this->employee('Военнообязанный, старший лейтенант запаса');
        $kept = $this->attach($employee, 'Военный билет.pdf');

        $reader = $this->withRights('employees.field.military');

        $this->actingAs($reader)
            ->get("/employees/{$employee->id}/military/document")
            ->assertOk();

        $this->actingAs($reader)
            ->put("/employees/{$employee->id}/military", ['military' => 'Невоеннообязанный'])
            ->assertForbidden();

        $this->actingAs($reader)
            ->delete("/employees/{$employee->id}/military/document")
            ->assertForbidden();

        $details = $employee->details->fresh();
        $this->assertSame('Военнообязанный, старший лейтенант запаса', $details->military);
        $this->assertSame($kept, $details->military_document_path);
        Storage::disk('documents')->assertExists($kept);
    }

    /**
     * A card with nothing filed here, a column that outlived its file, and one
     * person's address reaching for another person's document: none of the
     * three is there to be found.
     */
    public function test_a_document_that_is_not_there_is_not_found()
    {
        Storage::fake('documents');
        $employee = $this->employee('Военнообязанный');
        $admin = $this->sysadmin();

        // The line is filled in and no paper was ever scanned for it.
        $this->actingAs($admin)->get("/employees/{$employee->id}/military/document")->assertNotFound();
        $this->actingAs($admin)->delete("/employees/{$employee->id}/military/document")->assertNotFound();

        // The column names a file nobody can find: somebody removed it by hand,
        // and the column stayed behind.
        $employee->details->update([
            'military_document_path' => "employees/{$employee->id}/gone.pdf",
            'military_document_name' => 'Военный билет.pdf',
        ]);

        $this->actingAs($admin)->get("/employees/{$employee->id}/military/document")->assertNotFound();

        // And one person's document is not read off another person's address:
        // the block is a column of the card it belongs to, so there is nothing
        // under the colleague who never filled it in.
        $this->attach($employee, 'Приписное свидетельство.pdf');
        $other = $this->employee();

        $this->actingAs($admin)->get("/employees/{$other->id}/military/document")->assertNotFound();
        $this->actingAs($admin)->delete("/employees/{$other->id}/military/document")->assertNotFound();

        $this->assertNotNull($employee->details->fresh()->military_document_path);
    }

    /**
     * The document taken off the card goes off the disk — and the line stays.
     *
     * A военный билет may be scanned badly or be the wrong page altogether, and
     * losing the standing along with the scan would be losing the answer over
     * the paperwork.
     */
    public function test_the_document_is_taken_off_the_disk_and_the_line_stays()
    {
        Storage::fake('documents');
        $employee = $this->employee('Военнообязанный, старший лейтенант запаса');
        $was = $this->attach($employee, 'Военный билет.pdf');

        $this->actingAs($this->sysadmin())
            ->delete("/employees/{$employee->id}/military/document")
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $details = $employee->details->fresh();

        $this->assertNull($details->military_document_path);
        $this->assertNull($details->military_document_name);
        Storage::disk('documents')->assertMissing($was);

        // The answer to the question the block asks is still on the card.
        $this->assertSame('Военнообязанный, старший лейтенант запаса', $details->military);
    }

    /** The block is HR's to fill in, so an ordinary colleague writes nothing in it. */
    public function test_an_employee_cannot_touch_anyones_military_standing()
    {
        Storage::fake('documents');
        $employee = $this->employee('Военнообязанный, старший лейтенант запаса');
        $kept = $this->attach($employee, 'Военный билет.pdf');

        // Not even on their own card.
        $this->actingAs($employee);
        $this->put("/employees/{$employee->id}/military", ['military' => 'Невоеннообязанный'])->assertForbidden();
        $this->delete("/employees/{$employee->id}/military/document")->assertForbidden();

        $details = $employee->details->fresh();
        $this->assertSame('Военнообязанный, старший лейтенант запаса', $details->military);
        $this->assertSame($kept, $details->military_document_path);
    }

    /**
     * The block as the create page sends it: the line and its document travel
     * with the rest of the card in one request.
     *
     * Only the document is weighed here — EmployeeCreateTest already covers the
     * line among everything else the page carries.
     */
    public function test_the_whole_card_is_filed_with_the_line_and_its_document()
    {
        Storage::fake('documents');

        $this->actingAs($this->sysadmin())->post('/employees', [
            'surname' => 'Азимова',
            'name' => 'Нилуфар',
            'email' => 'nilufar@evolet.tj',
            'role' => 'specialist',
            'placements' => [],
            // «Знание языков» asks for at least one row, so the card the page
            // sends carries one — this test is about the document, not the list.
            'languages' => [['name' => 'Русский', 'level' => 'intermediate']],
            'military' => 'Военнообязанный, старший лейтенант запаса',
            'military_document' => $this->paper('Военный билет.pdf'),
        ])->assertSessionHasNoErrors()->assertRedirect();

        $details = User::firstWhere('email', 'nilufar@evolet.tj')->details;

        $this->assertSame('Военнообязанный, старший лейтенант запаса', $details->military);
        $this->assertSame('Военный билет.pdf', $details->military_document_name);
        Storage::disk('documents')->assertExists($details->military_document_path);
        $this->assertCount(1, Storage::disk('documents')->allFiles());
    }
}
