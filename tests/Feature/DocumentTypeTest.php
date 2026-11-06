<?php

namespace Tests\Feature;

use App\Models\DocumentType;
use App\Models\EmployeeDocument;
use App\Models\EmployeeDocumentFile;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The list of papers the company asks everybody for.
 *
 * A reference list like the languages and the citizenships, kept by the same
 * people: what a personnel office asks for changes with the rules it works
 * under, and a card answers the list rather than carrying a column per paper.
 *
 * What a record of it says is four things: what the paper is called, what is
 * wanted of it in a word, whether the original is handed over across a desk
 * instead of being sent, and whether everybody is asked for it. How it arrives
 * is no question any more — a document that is sent at all is one or more PDFs.
 */
class DocumentTypeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('sysadmin');
    }

    /** @return array<string, mixed> */
    private function payload(array $extra = []): array
    {
        return array_merge([
            'name' => 'Копия паспорта',
            'hint' => 'Разворот с фотографией и страница с пропиской',
            'on_paper' => false,
            'is_required' => true,
        ], $extra);
    }

    public function test_only_admins_keep_the_list_of_papers_the_company_asks_for()
    {
        $this->actingAs($this->colleague())->get('/directories/document_types')->assertForbidden();
        $this->actingAs($this->colleague())->post('/directories/document_types', $this->payload())->assertForbidden();

        $this->actingAs($this->admin);
        $this->post('/directories/document_types', $this->payload())->assertSessionHasNoErrors();

        $passport = DocumentType::firstWhere('name', 'Копия паспорта');
        $this->assertFalse($passport->on_paper);
        $this->assertTrue($passport->is_required);

        $this->get('/directories/document_types')->assertInertia(fn (Assert $page) => $page
            ->component('directories/document-types')
            ->where('items.0.name', 'Копия паспорта')
            ->where('items.0.on_paper', false)
            ->where('items.0.is_required', true)
            ->where('items.0.documents_count', 0)
            ->where('canEdit', true)
        );

        // One paper, one name: two rows called «Копия паспорта» would be two
        // lines on every card asking for the same thing.
        $this->post('/directories/document_types', $this->payload())->assertSessionHasErrors('name');

        $this->put("/directories/document_types/{$passport->id}", $this->payload(['name' => 'Паспорт', 'on_paper' => true]))
            ->assertSessionHasNoErrors();
        $this->assertSame(['Паспорт', true], [$passport->fresh()->name, $passport->fresh()->on_paper]);

        $this->delete("/directories/document_types/{$passport->id}")->assertSessionHasNoErrors();
        $this->assertSame(0, DocumentType::count());
    }

    /**
     * Where the original is kept is a question with two answers and no third
     * one, so it is asked rather than guessed: a row that says nothing about it
     * would be a paper nobody knows whether to wait for a scan of.
     */
    public function test_a_paper_says_whether_its_original_is_handed_over()
    {
        $this->actingAs($this->admin);

        $this->post('/directories/document_types', $this->payload(['on_paper' => null]))
            ->assertSessionHasErrors('on_paper');

        $this->post('/directories/document_types', $this->payload(['name' => 'Трудовая книжка', 'on_paper' => true]))
            ->assertSessionHasNoErrors();

        $this->assertTrue(DocumentType::firstWhere('name', 'Трудовая книжка')->on_paper);
    }

    /**
     * The count beside a paper is the staff list's own: the people still
     * working here who have handed it in. What somebody who has left handed in
     * three years ago says nothing about what the office is waiting for now.
     */
    public function test_the_count_is_of_working_staff_who_handed_it_in()
    {
        $passport = DocumentType::create($this->payload());

        foreach ([User::factory()->create(), User::factory()->create(['status' => 'fired'])] as $employee) {
            EmployeeDocument::create([
                'user_id' => $employee->id,
                'document_type_id' => $passport->id,
                'status' => EmployeeDocument::APPROVED,
            ]);
        }

        $this->actingAs($this->admin)
            ->get('/directories/document_types')
            ->assertInertia(fn (Assert $page) => $page->where('items.0.documents_count', 1));
    }

    /**
     * A paper the company stops asking for takes with it what was handed in
     * against it — the rows by the database's own cascade, and the files off
     * the disk, which that cascade knows nothing about.
     */
    public function test_dropping_a_paper_takes_the_files_handed_in_with_it()
    {
        Storage::fake('documents');

        $employee = User::factory()->create();
        $passport = DocumentType::create($this->payload());
        $handed = EmployeeDocument::create([
            'user_id' => $employee->id,
            'document_type_id' => $passport->id,
            'status' => EmployeeDocument::APPROVED,
        ]);

        $path = "employees/{$employee->id}/passport.pdf";
        Storage::disk('documents')->put($path, 'скан');
        $handed->files()->create(['path' => $path, 'name' => 'Паспорт.pdf', 'size' => 10, 'mime' => 'application/pdf']);

        $this->actingAs($this->admin)->delete("/directories/document_types/{$passport->id}")->assertSessionHasNoErrors();

        $this->assertSame(0, EmployeeDocument::count());
        $this->assertSame(0, EmployeeDocumentFile::count());
        Storage::disk('documents')->assertMissing($path);
    }
}
