<?php

namespace Tests\Feature;

use App\Models\DocumentType;
use App\Models\EmployeeDocument;
use App\Models\User;
use App\Support\EmployeeFields;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The papers the company asks everybody for, handed in and looked at.
 *
 * Two hands do two different things here, and the whole of this is about
 * keeping them apart: somebody hands a document in, and somebody in the office
 * says whether it will do. A document handed in by the office itself has
 * already been looked at by the person who would be doing the looking, so it
 * needs no passing — and nobody else's «approved» can be had by attaching one.
 *
 * A paper kept on paper is the one thing nobody sends at all: the original goes
 * across a desk, and the card holds the office's word that it is in the safe.
 * That word is a judgement rather than a correction to the card, so it belongs
 * to the hands that pass documents and to no others.
 */
class EmployeeDocumentTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $employee;

    private DocumentType $passport;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('documents');

        $this->seed([RoleSeeder::class, PermissionSeeder::class]);
        $this->admin = User::factory()->create()->assignRole('sysadmin');
        $this->employee = User::factory()->create();
        $this->passport = DocumentType::create([
            'name' => 'Копия паспорта',
            'hint' => 'Разворот с фотографией',
            'on_paper' => false,
            'is_required' => true,
        ]);
    }

    private function url(string $action = 'store', ?EmployeeDocument $document = null): string
    {
        return match ($action) {
            'store' => "/employees/{$this->employee->id}/documents/{$this->passport->id}",
            default => "/employees/{$this->employee->id}/documents/{$document?->id}".($action === 'destroy' ? '' : "/{$action}"),
        };
    }

    private function scan(string $name = 'passport.pdf'): UploadedFile
    {
        return UploadedFile::fake()->create($name, 120, 'application/pdf');
    }

    /** The paper whose original is handed over across a desk instead of being sent. */
    private function book(): DocumentType
    {
        return DocumentType::create([
            'name' => 'Трудовая книжка',
            'hint' => 'Оригинал сдают в отдел кадров',
            'on_paper' => true,
            'is_required' => true,
        ]);
    }

    /** Somebody who may read and attach the documents of their own card and nothing else. */
    private function owner(): User
    {
        return $this->colleague()->givePermissionTo([
            EmployeeFields::permission('documents', EmployeeFields::OWN),
            EmployeeFields::editPermission('documents', EmployeeFields::OWN),
        ]);
    }

    public function test_a_document_the_office_attaches_itself_needs_no_passing()
    {
        $this->actingAs($this->admin)
            ->post($this->url(), ['files' => [$this->scan()]])
            ->assertSessionHasNoErrors();

        $document = EmployeeDocument::sole();

        $this->assertSame(EmployeeDocument::APPROVED, $document->status);
        $this->assertSame($this->admin->id, $document->submitted_by);
        // Looked at by whoever attached it, which is the whole of what being
        // passed by the office means.
        $this->assertSame($this->admin->id, $document->reviewed_by);
        $this->assertNotNull($document->reviewed_at);

        $file = $document->files()->sole();

        $this->assertSame('passport.pdf', $file->name);
        Storage::disk('documents')->assertExists($file->path);
    }

    /** And one handed in by the person whose card it is waits to be looked at. */
    public function test_what_somebody_hands_in_themselves_waits_to_be_looked_at()
    {
        $owner = $this->owner();

        $this->actingAs($owner)
            ->post("/employees/{$owner->id}/documents/{$this->passport->id}", ['files' => [$this->scan()]])
            ->assertSessionHasNoErrors();

        $document = EmployeeDocument::sole();

        $this->assertSame(EmployeeDocument::PENDING, $document->status);
        $this->assertNull($document->reviewed_by);
    }

    /**
     * A second attempt replaces the first, down to the files: one row per
     * person per paper, because what is wanted is the copy that stands now.
     */
    public function test_a_second_attempt_replaces_the_first_and_takes_its_files_off_the_disk()
    {
        $this->actingAs($this->admin)->post($this->url(), ['files' => [$this->scan('first.pdf')]]);

        $first = EmployeeDocument::sole()->files()->sole()->path;

        $this->actingAs($this->admin)->post($this->url(), ['files' => [$this->scan('second.pdf')]])->assertSessionHasNoErrors();

        $this->assertSame(1, EmployeeDocument::count());
        $this->assertSame(['second.pdf'], EmployeeDocument::sole()->files()->pluck('name')->all());
        Storage::disk('documents')->assertMissing($first);
    }

    /**
     * One document is as many files as the paper itself takes: a passport is
     * two pages and a list of certificates is as long as somebody's courses
     * were. No number of them is too many, and they are one answer rather than
     * a row each.
     */
    public function test_one_document_is_as_many_files_as_it_takes()
    {
        $certificates = DocumentType::create([
            'name' => 'Сертификаты',
            'on_paper' => false,
            'is_required' => false,
        ]);

        $this->actingAs($this->admin)
            ->post("/employees/{$this->employee->id}/documents/{$certificates->id}", [
                'files' => array_map(fn (int $i) => $this->scan("course-{$i}.pdf"), range(1, 6)),
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(6, EmployeeDocument::where('document_type_id', $certificates->id)->sole()->files()->count());

        // And a paper that is sent at all is sent with something: an empty
        // window is somebody who has not handed the thing in yet.
        $this->actingAs($this->admin)
            ->post($this->url(), ['files' => []])
            ->assertSessionHasErrors(['files' => 'Приложите хотя бы один файл.']);
    }

    /**
     * A document arrives as a PDF and as nothing else.
     *
     * The office prints, signs and scans, and a page photographed on a
     * telephone arrives as a picture that cannot be printed back out at the
     * size it was — so the photograph is refused where the scan of it is taken.
     */
    public function test_a_document_arrives_as_a_pdf_and_nothing_else()
    {
        $this->actingAs($this->admin)
            ->post($this->url(), ['files' => [UploadedFile::fake()->image('passport.jpg')]])
            ->assertSessionHasErrors(['files.0' => 'Документ прикладывают файлом PDF.']);

        $this->assertSame(0, EmployeeDocument::count());

        $this->actingAs($this->admin)
            ->post($this->url(), ['files' => [$this->scan()]])
            ->assertSessionHasNoErrors();
    }

    /**
     * A paper kept on paper is ticked rather than sent: the office says it is
     * holding the original, and that word is the whole answer — a row with no
     * files at all, passed by the person who gave it.
     */
    public function test_a_paper_kept_on_paper_is_ticked_with_no_files_at_all()
    {
        $book = $this->book();

        $this->actingAs($this->admin)
            ->post("/employees/{$this->employee->id}/documents/{$book->id}")
            ->assertSessionHasNoErrors();

        $document = EmployeeDocument::where('document_type_id', $book->id)->sole();

        $this->assertSame(EmployeeDocument::APPROVED, $document->status);
        $this->assertSame($this->admin->id, $document->reviewed_by);
        $this->assertNotNull($document->reviewed_at);
        $this->assertSame(0, $document->files()->count());
    }

    /**
     * And nobody ticks their own: «оригинал у нас» is a sentence about a paper
     * in the office's safe rather than about anything its owner can send, so
     * somebody who may attach every other document to their own card is refused
     * this one — and refused taking it off again, which is the same sentence
     * withdrawn.
     */
    public function test_the_owner_of_a_card_neither_ticks_nor_unticks_a_paper_kept_on_paper()
    {
        $owner = $this->owner();
        $book = $this->book();
        $url = "/employees/{$owner->id}/documents/{$book->id}";

        // The same hand, the same card, an ordinary paper: attaching is theirs.
        $this->actingAs($owner)
            ->post("/employees/{$owner->id}/documents/{$this->passport->id}", ['files' => [$this->scan()]])
            ->assertSessionHasNoErrors();

        $this->actingAs($owner)->post($url)->assertForbidden();
        $this->assertSame(0, EmployeeDocument::where('document_type_id', $book->id)->count());

        $this->actingAs($this->admin)->post($url)->assertSessionHasNoErrors();
        $document = EmployeeDocument::where('document_type_id', $book->id)->sole();

        $this->actingAs($owner)
            ->delete("/employees/{$owner->id}/documents/{$document->id}")
            ->assertForbidden();
        $this->assertNotNull($document->fresh());
    }

    /**
     * A scan of the original may still be clipped to a ticked paper — the
     * office photocopies what it is holding — and clipping one changes nothing
     * about the tick: the original is where it was, and the row stays passed.
     */
    public function test_a_scan_clipped_to_a_ticked_paper_is_kept_and_it_stays_passed()
    {
        $book = $this->book();
        $url = "/employees/{$this->employee->id}/documents/{$book->id}";

        $this->actingAs($this->admin)->post($url)->assertSessionHasNoErrors();

        $this->actingAs($this->admin)
            ->post($url, ['files' => [$this->scan('book.pdf')]])
            ->assertSessionHasNoErrors();

        $document = EmployeeDocument::where('document_type_id', $book->id)->sole();
        $file = $document->files()->sole();

        $this->assertSame(EmployeeDocument::APPROVED, $document->status);
        $this->assertSame('book.pdf', $file->name);
        Storage::disk('documents')->assertExists($file->path);
    }

    /**
     * Saving a paper again without sending any files leaves the ones on file
     * where they are: a tick is not an emptying. The window that takes files
     * away sends what is left of them, so an empty list arrives as an empty
     * list and is told apart from no list at all.
     */
    public function test_a_save_that_carries_no_files_leaves_the_ones_on_file_alone()
    {
        $book = $this->book();
        $url = "/employees/{$this->employee->id}/documents/{$book->id}";

        $this->actingAs($this->admin)->post($url, ['files' => [$this->scan('book.pdf')]])->assertSessionHasNoErrors();

        $document = EmployeeDocument::where('document_type_id', $book->id)->sole();
        $path = $document->files()->sole()->path;

        $this->actingAs($this->admin)->post($url)->assertSessionHasNoErrors();

        $this->assertSame(['book.pdf'], $document->fresh()->files()->pluck('name')->all());
        Storage::disk('documents')->assertExists($path);

        // And an empty list is the window emptied on purpose, which takes the
        // scan off the card and off the disk.
        $this->actingAs($this->admin)->post($url, ['files' => []])->assertSessionHasNoErrors();

        $this->assertSame(0, $document->fresh()->files()->count());
        Storage::disk('documents')->assertMissing($path);
    }

    /**
     * Sending one back takes a reason, and the next attempt answers it: the
     * reason belongs to the files it was about and goes with them.
     */
    public function test_turning_a_document_down_takes_a_reason()
    {
        $owner = $this->owner();
        $this->actingAs($owner)->post("/employees/{$owner->id}/documents/{$this->passport->id}", ['files' => [$this->scan()]]);

        $document = EmployeeDocument::sole();
        $reject = "/employees/{$owner->id}/documents/{$document->id}/reject";

        $this->actingAs($this->admin)->put($reject)->assertSessionHasErrors('comment');

        $this->actingAs($this->admin)->put($reject, ['comment' => 'Не читается'])->assertSessionHasNoErrors();
        $this->assertSame([EmployeeDocument::REJECTED, 'Не читается'], [$document->fresh()->status, $document->fresh()->comment]);

        // Handed in again: the reason it was turned down is answered by the new
        // attempt, so it goes with the files it was about.
        $this->actingAs($owner)->post("/employees/{$owner->id}/documents/{$this->passport->id}", ['files' => [$this->scan()]]);
        $this->assertSame([EmployeeDocument::PENDING, null], [$document->fresh()->status, $document->fresh()->comment]);

        $this->actingAs($this->admin)
            ->put("/employees/{$owner->id}/documents/{$document->id}/approve")
            ->assertSessionHasNoErrors();
        $this->assertSame(EmployeeDocument::APPROVED, $document->fresh()->status);
    }

    /**
     * Attaching a document and passing one are two rights. Somebody who may
     * only attach cannot pass their own work, which is the whole point of
     * asking twice.
     */
    public function test_passing_a_document_is_a_right_of_its_own()
    {
        $owner = $this->owner();
        $this->actingAs($owner)->post("/employees/{$owner->id}/documents/{$this->passport->id}", ['files' => [$this->scan()]]);

        $document = EmployeeDocument::sole();

        $this->actingAs($owner)->put("/employees/{$owner->id}/documents/{$document->id}/approve")->assertForbidden();
        $this->actingAs($owner)->put("/employees/{$owner->id}/documents/{$document->id}/reject", ['comment' => 'Сойдёт'])->assertForbidden();
        $this->assertSame(EmployeeDocument::PENDING, $document->fresh()->status);
    }

    /** And attaching one to somebody else's card takes the right to change that card. */
    public function test_a_colleague_attaches_nothing_to_a_card_that_is_not_theirs()
    {
        $this->actingAs($this->colleague())
            ->post($this->url(), ['files' => [$this->scan()]])
            ->assertForbidden();

        $this->assertSame(0, EmployeeDocument::count());
    }

    /**
     * A file answers only somebody who may read this card's documents — and
     * only at the address of the card it belongs to.
     */
    public function test_a_file_answers_only_a_reader_of_this_cards_documents()
    {
        $this->actingAs($this->admin)->post($this->url(), ['files' => [$this->scan()]]);

        $document = EmployeeDocument::sole();
        $file = $document->files()->sole();
        $url = "/employees/{$this->employee->id}/documents/{$document->id}/files/{$file->id}";

        $this->actingAs($this->colleague())->get($url)->assertForbidden();
        $this->actingAs($this->admin)->get($url)->assertOk();

        // The same document asked for under somebody else's card: the two ids
        // must agree, or one person's address would reach another's papers.
        $stranger = User::factory()->create();
        $this->actingAs($this->admin)
            ->get("/employees/{$stranger->id}/documents/{$document->id}/files/{$file->id}")
            ->assertNotFound();
    }

    /** Taken off the card, the paper goes back to being one nobody has handed in. */
    public function test_taking_a_document_off_the_card_takes_its_files_too()
    {
        $this->actingAs($this->admin)->post($this->url(), ['files' => [$this->scan()]]);

        $document = EmployeeDocument::sole();
        $path = $document->files()->sole()->path;

        $this->actingAs($this->admin)->delete($this->url('destroy', $document))->assertSessionHasNoErrors();

        $this->assertSame(0, EmployeeDocument::count());
        Storage::disk('documents')->assertMissing($path);
    }

    /**
     * The card reads the whole list — what is handed in and what is still
     * wanted — because a list of what is attached says nothing about what is
     * missing.
     */
    public function test_the_card_reads_every_paper_asked_for_with_this_persons_answer()
    {
        DocumentType::create(['name' => 'ИНН', 'on_paper' => false, 'is_required' => false]);

        $this->actingAs($this->admin)->post($this->url(), ['files' => [$this->scan()]]);

        $this->actingAs($this->admin)
            ->get("/employees/{$this->employee->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('documents.canEdit', true)
                ->where('documents.canReview', true)
                ->has('documents.items', 2)
                ->where('documents.items.0.name', 'ИНН')
                ->where('documents.items.0.status', null)
                ->where('documents.items.1.name', 'Копия паспорта')
                ->where('documents.items.1.status', EmployeeDocument::APPROVED)
                ->has('documents.items.1.files', 1)
            );

        // And a colleague who may not read them is told nothing about them.
        $this->actingAs($this->colleague())
            ->get("/employees/{$this->employee->id}")
            ->assertInertia(fn (Assert $page) => $page->where('documents', null));
    }
}
