<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\PositionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The «Учёная степень, звание» block: the answer whether there are any, and
 * then a record for each, one line of text with the one paper behind it.
 *
 * It used to be a single column with a single document — one answer per card,
 * which had a degree and a title crammed into the same box with one scan to
 * prove both. It is kept the way the places of study are now, record by
 * record, so this file asks what EmployeeEducationTest asks of those: adding
 * one must not rewrite the rest, the answer about the list is told from an
 * empty list, and the paper is private until somebody who may read the block
 * asks for it.
 */
class EmployeeDegreeTest extends TestCase
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

    /** One record as a form sends it: the line, and nothing else unless asked. */
    private function payload(array $overrides = []): array
    {
        return ['name' => 'кандидат экономических наук', ...$overrides];
    }

    /** A paper of the kind the block takes, under the name it was scanned as. */
    private function paper(string $name): UploadedFile
    {
        return UploadedFile::fake()->create($name, 120, 'application/pdf');
    }

    public function test_an_admin_adds_a_record_without_touching_the_others()
    {
        $employee = $this->colleague();
        $kept = $employee->degrees()->create(['name' => 'доцент']);

        $this->actingAs($this->sysadmin())
            ->post("/employees/{$employee->id}/degrees", $this->payload())
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertEqualsCanonicalizing(
            ['доцент', 'кандидат экономических наук'],
            $employee->refresh()->degrees->pluck('name')->all(),
        );
        $this->assertNotNull($kept->fresh());
    }

    public function test_an_admin_changes_one_record()
    {
        $employee = $this->colleague();
        $degree = $employee->degrees()->create(['name' => 'доцент']);

        $this->actingAs($this->sysadmin())
            ->put("/employees/{$employee->id}/degrees/{$degree->id}", $this->payload(['name' => 'профессор']))
            ->assertSessionHasNoErrors();

        $this->assertSame('профессор', $degree->fresh()->name);
    }

    public function test_an_admin_deletes_one_record()
    {
        $employee = $this->colleague();
        $degree = $employee->degrees()->create(['name' => 'доцент']);
        $kept = $employee->degrees()->create(['name' => 'кандидат экономических наук']);

        $this->actingAs($this->sysadmin())
            ->delete("/employees/{$employee->id}/degrees/{$degree->id}")
            ->assertSessionHasNoErrors();

        $this->assertNull($degree->fresh());
        $this->assertNotNull($kept->fresh());
    }

    /**
     * «Нет» is written down, because an empty list cannot say it.
     *
     * The old block carried the word «Нет» in the line itself to be told from a
     * line nobody had filled in; a list has no line to carry it, so the answer
     * is asked outright — through a door of its own, because the records beside
     * it are edited one at a time while this is said about all of them at once.
     */
    public function test_a_card_records_that_this_person_holds_no_degree()
    {
        $employee = $this->colleague();
        $admin = $this->sysadmin();

        $this->actingAs($admin)
            ->put("/employees/{$employee->id}/degrees/answer", ['has_degrees' => false])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertFalse($employee->refresh()->details->has_degrees);

        // And an answer taken back leaves the question open again rather than
        // standing as «нет»: the two are different things to a department
        // deciding whom to go and ask.
        $this->actingAs($admin)
            ->put("/employees/{$employee->id}/degrees/answer", ['has_degrees' => null])
            ->assertSessionHasNoErrors();

        $this->assertNull($employee->refresh()->details->has_degrees);
    }

    /**
     * The answer is worked out from the rows rather than taken as it was sent.
     *
     * «Есть» is not an answer this door takes: the rows are what say there are
     * any, so a card claiming a degree it does not list would be the old
     * ambiguity the other way up. Read the other way round, a card that lists
     * one cannot be told it has none — the record is there to be seen, and the
     * two answers would contradict each other.
     */
    public function test_the_answer_is_worked_out_from_the_rows_rather_than_taken_as_sent()
    {
        $employee = $this->colleague();
        $admin = $this->sysadmin();

        $this->actingAs($admin)
            ->put("/employees/{$employee->id}/degrees/answer", ['has_degrees' => true])
            ->assertSessionHasNoErrors();

        $this->assertNull($employee->refresh()->details->has_degrees);

        $employee->degrees()->create(['name' => 'доцент']);

        $this->actingAs($admin)
            ->put("/employees/{$employee->id}/degrees/answer", ['has_degrees' => false])
            ->assertSessionHasNoErrors();

        $this->assertTrue($employee->refresh()->details->has_degrees);
    }

    /**
     * A record filed answers the question, and the last record taken off asks
     * it again.
     *
     * Not «нет»: a record removed is a correction, and nobody has said this
     * person holds no degree.
     */
    public function test_a_record_filed_says_there_are_some_and_the_last_one_removed_asks_again()
    {
        $employee = $this->colleague();
        $admin = $this->sysadmin();

        // Answered «нет» first, so what follows is the answer changing rather
        // than being given for the first time.
        $this->actingAs($admin)->put("/employees/{$employee->id}/degrees/answer", ['has_degrees' => false]);

        $this->actingAs($admin)
            ->post("/employees/{$employee->id}/degrees", $this->payload())
            ->assertSessionHasNoErrors();
        $this->assertTrue($employee->refresh()->details->has_degrees);

        // A second record, so that the first one going is not the list emptying.
        $this->actingAs($admin)->post("/employees/{$employee->id}/degrees", $this->payload(['name' => 'доцент']));

        $records = $employee->refresh()->degrees;
        $this->actingAs($admin)->delete("/employees/{$employee->id}/degrees/{$records->first()->id}");
        $this->assertTrue($employee->refresh()->details->has_degrees);

        $this->actingAs($admin)->delete("/employees/{$employee->id}/degrees/{$records->last()->id}");
        $this->assertNull($employee->refresh()->details->has_degrees);
    }

    /** And a list filed in one go answers it the same way, papers and all. */
    public function test_a_list_filed_in_one_go_says_there_are_some()
    {
        Storage::fake('documents');
        $employee = $this->colleague();

        $this->actingAs($this->sysadmin())
            ->post("/employees/{$employee->id}/degrees/many", ['records' => [
                $this->payload(['document' => $this->paper('Диплом кандидата наук.pdf')]),
                $this->payload(['name' => 'доцент']),
            ]])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $records = $employee->refresh()->degrees;

        $this->assertSame(['кандидат экономических наук', 'доцент'], $records->pluck('name')->all());
        $this->assertTrue($employee->refresh()->details->has_degrees);

        // The paper of the row that brought one is filed, and the row beside it
        // is left without one rather than given the same file.
        Storage::disk('documents')->assertExists($records->first()->document_path);
        $this->assertNull($records->last()->document_path);
        $this->assertCount(1, Storage::disk('documents')->allFiles());
    }

    /**
     * A record names something, and no more of it than the column holds.
     *
     * The line used to be allowed to arrive empty, because an empty line was
     * how a card said nobody had asked; the flag beside the list says that now,
     * so a record that names nothing is a record somebody opened and did not
     * fill in. Two hundred characters is one line of a card and not a page of
     * them.
     */
    public function test_a_record_names_something_and_no_more_than_the_column_holds()
    {
        $employee = $this->colleague();
        $admin = $this->sysadmin();

        $this->actingAs($admin)
            ->post("/employees/{$employee->id}/degrees", $this->payload(['name' => '']))
            ->assertSessionHasErrors('name');

        // Refused in the block's own words: «Поле название обязательно» says
        // nothing about which part of the sheet was being filled in.
        $this->assertStringContainsString('учёная степень, звание', session('errors')->first('name'));

        $this->actingAs($admin)
            ->post("/employees/{$employee->id}/degrees", $this->payload(['name' => str_repeat('я', 201)]))
            ->assertSessionHasErrors('name');

        $this->assertCount(0, $employee->refresh()->degrees);

        // The same of a record corrected, and of a list filed in one go — where
        // the refusal names the row it happened in.
        $degree = $employee->degrees()->create(['name' => 'доцент']);

        $this->actingAs($admin)
            ->put("/employees/{$employee->id}/degrees/{$degree->id}", $this->payload(['name' => '']))
            ->assertSessionHasErrors('name');

        $this->actingAs($admin)
            ->post("/employees/{$employee->id}/degrees/many", ['records' => [$this->payload(['name' => ''])]])
            ->assertSessionHasErrors('records.0.name');

        $this->assertSame(['доцент'], $employee->refresh()->degrees->pluck('name')->all());
    }

    /**
     * The paper is kept where only this application can reach it, and handed
     * out by a route that asks first.
     *
     * The whole point of keeping it off the web root: a file under it is read by
     * whoever holds its address and nobody is ever asked, and an аттестат
     * carries a name, a date of birth and a registry number on one page.
     */
    public function test_a_paper_is_kept_privately_and_handed_to_whoever_may_read_the_block()
    {
        Storage::fake('documents');
        $employee = $this->colleague();

        $this->actingAs($this->sysadmin())
            ->post("/employees/{$employee->id}/degrees", $this->payload([
                'document' => $this->paper('Диплом кандидата наук.pdf'),
            ]))
            ->assertSessionHasNoErrors();

        $degree = $employee->refresh()->degrees->first();

        // Filed under the colleague, under a name of the disk's own choosing...
        $this->assertStringStartsWith("employees/{$employee->id}/", $degree->document_path);
        Storage::disk('documents')->assertExists($degree->document_path);

        // ...while the name the card shows is the one the file arrived under.
        // There is no box to type it in any more: the form lets the name of the
        // file be edited before it is sent, so the one name that arrives is the
        // one name the card shows.
        $this->assertSame('Диплом кандидата наук.pdf', $degree->document_name);

        $url = "/employees/{$employee->id}/degrees/{$degree->id}/document";

        $handed = $this->actingAs($this->sysadmin())->get($url)->assertOk();

        // Shown rather than downloaded: a paper on a card is looked at far
        // oftener than it is kept, and every one of them is a PDF, which the
        // browser hands to its own reader. And under the name it arrived with
        // — asked of the UTF-8 parameter, because the plain one beside it can
        // only carry ASCII and a Cyrillic name is not that.
        $disposition = (string) $handed->headers->get('content-disposition');
        $this->assertStringStartsWith('inline;', $disposition);
        $this->assertStringContainsString(rawurlencode('Диплом кандидата наук.pdf'), $disposition);

        // A colleague who may not read this block is refused the paper behind
        // it — the same answer that kept the records off the page in the first
        // place.
        $this->actingAs($this->colleague())->get($url)->assertForbidden();

        // And one person's address cannot reach another person's record.
        $other = $this->colleague();
        $this->actingAs($this->sysadmin())
            ->get("/employees/{$other->id}/degrees/{$degree->id}/document")
            ->assertNotFound();
    }

    /**
     * Nothing but a PDF, and ten megabytes is the most of one.
     *
     * A photograph is refused now, which it was not before: every document this
     * application keeps is a PDF, so that a reader opens them all the same way
     * and nobody is handed a snapshot of an аттестат taken at an angle.
     */
    public function test_only_a_pdf_of_the_right_size_is_taken()
    {
        Storage::fake('documents');
        $employee = $this->colleague();
        $admin = $this->sysadmin();

        foreach ([
            UploadedFile::fake()->create('аттестат.jpg', 120, 'image/jpeg'),
            UploadedFile::fake()->create('аттестат.png', 120, 'image/png'),
            UploadedFile::fake()->create('степень.xlsx', 10, 'application/vnd.ms-excel'),
            UploadedFile::fake()->create('Аттестат.pdf', 11 * 1024, 'application/pdf'),
        ] as $refused) {
            $this->actingAs($admin)
                ->post("/employees/{$employee->id}/degrees", $this->payload(['document' => $refused]))
                ->assertSessionHasErrors('document');
        }

        $this->assertCount(0, $employee->refresh()->degrees);
        $this->assertCount(0, Storage::disk('documents')->allFiles());
    }

    /**
     * A record that goes takes its paper with it, and one corrected leaves no
     * copy of the document it used to carry.
     *
     * A file nothing points at any more is an аттестат lying on a disk for
     * good, which nobody notices until a backup is restored somewhere it should
     * not have been.
     */
    public function test_the_file_goes_when_the_record_goes_or_changes_its_paper()
    {
        Storage::fake('documents');
        $employee = $this->colleague();
        $admin = $this->sysadmin();

        $this->actingAs($admin)->post("/employees/{$employee->id}/degrees", $this->payload([
            'document' => $this->paper('Диплом кандидата наук.pdf'),
        ]))->assertSessionHasNoErrors();

        $degree = $employee->refresh()->degrees->first();
        $was = $degree->document_path;

        // Replaced: the new one is kept and the old one is not.
        $this->actingAs($admin)->put("/employees/{$employee->id}/degrees/{$degree->id}", $this->payload([
            'name' => 'доцент',
            'document' => $this->paper('Аттестат доцента.pdf'),
        ]))->assertSessionHasNoErrors();

        $degree->refresh();
        $this->assertNotSame($was, $degree->document_path);
        $this->assertSame('Аттестат доцента.pdf', $degree->document_name);
        Storage::disk('documents')->assertMissing($was);
        Storage::disk('documents')->assertExists($degree->document_path);

        // Nothing is left over: one record with one paper means one file.
        $this->assertCount(1, Storage::disk('documents')->allFiles());

        // A correction that says nothing about the document leaves it alone:
        // not every save is about the paper, and absent is not «take it away».
        $kept = $degree->document_path;
        $this->actingAs($admin)->put("/employees/{$employee->id}/degrees/{$degree->id}", $this->payload([
            'name' => 'профессор',
        ]))->assertSessionHasNoErrors();

        $this->assertSame($kept, $degree->refresh()->document_path);
        Storage::disk('documents')->assertExists($kept);

        // And deleted: the file goes with the row.
        $this->actingAs($admin)->delete("/employees/{$employee->id}/degrees/{$degree->id}")
            ->assertSessionHasNoErrors();

        Storage::disk('documents')->assertMissing($kept);
    }

    /**
     * A record with no paper behind it, and a row that outlived its file:
     * neither is there to be handed over.
     */
    public function test_a_paper_that_is_not_there_is_not_found()
    {
        Storage::fake('documents');
        $employee = $this->colleague();
        $admin = $this->sysadmin();

        // The line is filled in and no paper was ever scanned for it.
        $degree = $employee->degrees()->create(['name' => 'доцент']);

        $this->actingAs($admin)->get("/employees/{$employee->id}/degrees/{$degree->id}/document")->assertNotFound();

        // The row names a file nobody can find: somebody removed it by hand,
        // and the columns stayed behind.
        $degree->update([
            'document_path' => "employees/{$employee->id}/gone.pdf",
            'document_name' => 'Аттестат доцента.pdf',
        ]);

        $this->actingAs($admin)->get("/employees/{$employee->id}/degrees/{$degree->id}/document")->assertNotFound();
    }

    public function test_a_record_cannot_be_reached_through_another_employee()
    {
        $owner = $this->colleague();
        $stranger = $this->colleague();
        $degree = $owner->degrees()->create(['name' => 'доцент']);

        // The id is real, but it belongs to somebody else.
        $this->actingAs($this->sysadmin())
            ->put("/employees/{$stranger->id}/degrees/{$degree->id}", $this->payload())
            ->assertNotFound();

        $this->actingAs($this->sysadmin())
            ->delete("/employees/{$stranger->id}/degrees/{$degree->id}")
            ->assertNotFound();

        $this->assertSame('доцент', $degree->fresh()->name);

        // And the answer about the list is left where it was on both cards: a
        // record removed is what sets it, so a removal that did not happen must
        // not have said anything either.
        $this->assertNull($owner->refresh()->details?->has_degrees);
        $this->assertNull($stranger->refresh()->details?->has_degrees);
    }

    /**
     * Twenty records is the most a list takes in one request.
     *
     * Not because twenty degrees is too many to hold — nobody has twenty — but
     * because the cap is what keeps a form from filing a thousand rows and a
     * card from being a thing nothing can show.
     */
    public function test_no_more_than_twenty_records_are_filed_in_one_go()
    {
        $employee = $this->colleague();
        $admin = $this->sysadmin();

        $rows = fn (int $count) => array_map(
            fn (int $i) => $this->payload(['name' => "звание {$i}"]),
            range(1, $count),
        );

        $this->actingAs($admin)
            ->post("/employees/{$employee->id}/degrees/many", ['records' => $rows(21)])
            ->assertSessionHasErrors('records');

        $this->assertCount(0, $employee->refresh()->degrees);

        $this->actingAs($admin)
            ->post("/employees/{$employee->id}/degrees/many", ['records' => $rows(20)])
            ->assertSessionHasNoErrors();

        $this->assertCount(20, $employee->refresh()->degrees);
    }

    /**
     * Reading the block is not changing it.
     *
     * The records and their papers are open to more people than may retype
     * them — a degree is the one line of this card a person puts on a business
     * card themselves — so the read right alone opens a paper and nothing else.
     * Changing a record is the block's own gate, which this position has not
     * got.
     */
    public function test_reading_the_paper_is_not_changing_the_records()
    {
        Storage::fake('documents');
        $employee = $this->colleague();
        $admin = $this->sysadmin();

        $this->actingAs($admin)->post("/employees/{$employee->id}/degrees", $this->payload([
            'name' => 'доцент',
            'document' => $this->paper('Аттестат доцента.pdf'),
        ]))->assertSessionHasNoErrors();

        $degree = $employee->refresh()->degrees->first();
        $kept = $degree->document_path;

        $reader = $this->withRights('employees.field.degree');

        $this->actingAs($reader)
            ->get("/employees/{$employee->id}/degrees/{$degree->id}/document")
            ->assertOk();

        $this->actingAs($reader)
            ->post("/employees/{$employee->id}/degrees", $this->payload())
            ->assertForbidden();

        $this->actingAs($reader)
            ->post("/employees/{$employee->id}/degrees/many", ['records' => [$this->payload()]])
            ->assertForbidden();

        $this->actingAs($reader)
            ->put("/employees/{$employee->id}/degrees/{$degree->id}", $this->payload(['name' => 'профессор']))
            ->assertForbidden();

        $this->actingAs($reader)
            ->delete("/employees/{$employee->id}/degrees/{$degree->id}")
            ->assertForbidden();

        // The answer about the list is behind the same gate as the records it is
        // about: «нет учёной степени» is a line of the card like the records.
        $this->actingAs($reader)
            ->put("/employees/{$employee->id}/degrees/answer", ['has_degrees' => false])
            ->assertForbidden();

        $this->assertSame('доцент', $degree->fresh()->name);
        $this->assertTrue($employee->refresh()->details->has_degrees);
        Storage::disk('documents')->assertExists($kept);
    }

    /** The block is HR's to fill in, so an ordinary colleague writes nothing in it. */
    public function test_an_employee_cannot_touch_anyones_degrees()
    {
        $employee = $this->colleague();
        $degree = $employee->degrees()->create(['name' => 'доцент']);

        // Not even on their own card.
        $this->actingAs($employee);
        $this->post("/employees/{$employee->id}/degrees", $this->payload())->assertForbidden();
        $this->post("/employees/{$employee->id}/degrees/many", ['records' => [$this->payload()]])->assertForbidden();
        $this->put("/employees/{$employee->id}/degrees/{$degree->id}", $this->payload())->assertForbidden();
        $this->delete("/employees/{$employee->id}/degrees/{$degree->id}")->assertForbidden();
        $this->put("/employees/{$employee->id}/degrees/answer", ['has_degrees' => false])->assertForbidden();

        $this->assertSame('доцент', $degree->fresh()->name);
        $this->assertNull($employee->refresh()->details?->has_degrees);
    }
}
