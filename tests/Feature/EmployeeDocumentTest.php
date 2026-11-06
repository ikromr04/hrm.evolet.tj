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
            'min_files' => 1,
            'max_files' => 2,
            'accepts_files' => true,
            'accepts_camera' => true,
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

    /** A paper takes no more files than its own entry in the list allows. */
    public function test_a_paper_takes_no_more_files_than_it_was_told_to()
    {
        $this->actingAs($this->admin)
            ->post($this->url(), ['files' => [$this->scan('one.pdf'), $this->scan('two.pdf'), $this->scan('three.pdf')]])
            ->assertSessionHasErrors('files');

        $this->assertSame(0, EmployeeDocument::count());
    }

    /**
     * And as few as it asks for: «две фотографии» is not answered by one of
     * them, and a paper that names no upper bound takes as many as it is given.
     */
    public function test_a_paper_takes_as_few_files_as_it_asks_for_and_as_many_as_it_allows()
    {
        $photographs = DocumentType::create([
            'name' => 'Фотографии 3×4',
            'min_files' => 2,
            'max_files' => 2,
            'accepts_files' => true,
            'accepts_camera' => true,
            'is_required' => true,
        ]);

        $certificates = DocumentType::create([
            'name' => 'Сертификаты',
            'min_files' => 1,
            'max_files' => null,
            'accepts_files' => true,
            'accepts_camera' => false,
            'is_required' => false,
        ]);

        $this->actingAs($this->admin)
            ->post("/employees/{$this->employee->id}/documents/{$photographs->id}", ['files' => [UploadedFile::fake()->image('one.jpg')]])
            ->assertSessionHasErrors('files');

        $this->actingAs($this->admin)
            ->post("/employees/{$this->employee->id}/documents/{$photographs->id}", [
                'files' => [UploadedFile::fake()->image('one.jpg'), UploadedFile::fake()->image('two.jpg')],
            ])
            ->assertSessionHasNoErrors();

        // No number of certificates is too many, so six are six and not a refusal.
        $this->actingAs($this->admin)
            ->post("/employees/{$this->employee->id}/documents/{$certificates->id}", [
                'files' => array_map(fn (int $i) => $this->scan("course-{$i}.pdf"), range(1, 6)),
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(6, EmployeeDocument::where('document_type_id', $certificates->id)->sole()->files()->count());
    }

    /**
     * A paper the office will only take photographed takes images alone: a
     * photograph is an image and never a PDF, and the rule is the only part of
     * «сфотографировать» a server can tell apart from «выбрать файл».
     */
    public function test_a_paper_that_is_only_photographed_takes_no_pdf()
    {
        $book = DocumentType::create([
            'name' => 'Трудовая книжка',
            'min_files' => 1,
            'max_files' => 2,
            'accepts_files' => false,
            'accepts_camera' => true,
            'is_required' => false,
        ]);

        $this->actingAs($this->admin)
            ->post("/employees/{$this->employee->id}/documents/{$book->id}", ['files' => [$this->scan()]])
            ->assertSessionHasErrors('files.0');

        $this->actingAs($this->admin)
            ->post("/employees/{$this->employee->id}/documents/{$book->id}", ['files' => [UploadedFile::fake()->image('book.jpg')]])
            ->assertSessionHasNoErrors();
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
        DocumentType::create(['name' => 'ИНН', 'max_files' => 1, 'accepts_files' => true, 'accepts_camera' => false, 'is_required' => false]);

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

    /**
     * The queue is everything waiting to be looked at, across every card, and
     * it belongs to whoever passes documents.
     */
    public function test_the_queue_holds_what_is_waiting_and_opens_for_whoever_passes()
    {
        $owner = $this->owner();
        $this->actingAs($owner)->post("/employees/{$owner->id}/documents/{$this->passport->id}", ['files' => [$this->scan()]]);

        // Passed already, so nobody is waiting on it.
        $this->actingAs($this->admin)->post($this->url(), ['files' => [$this->scan()]]);

        // And handed in by somebody who has left, which the office is not waiting on either.
        $gone = User::factory()->create(['status' => 'fired']);
        $this->actingAs($this->admin)->post("/employees/{$gone->id}/documents/{$this->passport->id}", ['files' => [$this->scan()]]);
        EmployeeDocument::where('user_id', $gone->id)->update(['status' => EmployeeDocument::PENDING]);

        $this->actingAs($this->colleague())->get('/documents')->assertForbidden();
        $this->actingAs($owner)->get('/documents')->assertForbidden();

        $this->actingAs($this->admin)
            ->get('/documents')
            ->assertInertia(fn (Assert $page) => $page
                ->component('documents')
                ->has('items', 1)
                ->where('items.0.employee.id', $owner->id)
                ->where('items.0.name', 'Копия паспорта')
                ->has('items.0.files', 1)
            );
    }
}
