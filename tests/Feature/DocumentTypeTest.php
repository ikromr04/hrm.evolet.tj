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
 * What a record of it says is how its answer may arrive — how many files,
 * chosen from a disk or photographed on the spot — and whether everybody is
 * asked for it. A paper that can arrive no way at all cannot be asked for, and
 * the list refuses to hold one.
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
            'min_files' => 1,
            'max_files' => 3,
            'accepts_files' => true,
            'accepts_camera' => true,
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
        $this->assertSame(3, $passport->max_files);
        $this->assertTrue($passport->accepts_camera);
        $this->assertTrue($passport->is_required);

        $this->get('/directories/document_types')->assertInertia(fn (Assert $page) => $page
            ->component('directories/document-types')
            ->where('items.0.name', 'Копия паспорта')
            ->where('items.0.max_files', 3)
            ->where('items.0.accepts_camera', true)
            ->where('items.0.documents_count', 0)
            ->where('canEdit', true)
        );

        // One paper, one name: two rows called «Копия паспорта» would be two
        // lines on every card asking for the same thing.
        $this->post('/directories/document_types', $this->payload())->assertSessionHasErrors('name');

        $this->put("/directories/document_types/{$passport->id}", $this->payload(['name' => 'Паспорт', 'max_files' => 1]))
            ->assertSessionHasNoErrors();
        $this->assertSame(['Паспорт', 1], [$passport->fresh()->name, $passport->fresh()->max_files]);

        $this->delete("/directories/document_types/{$passport->id}")->assertSessionHasNoErrors();
        $this->assertSame(0, DocumentType::count());
    }

    /**
     * A paper that can be neither chosen nor photographed cannot be handed in,
     * so the list will not hold one. Either way on its own is enough.
     */
    public function test_a_paper_that_can_arrive_no_way_at_all_is_refused()
    {
        $this->actingAs($this->admin);

        $this->post('/directories/document_types', $this->payload(['accepts_files' => false, 'accepts_camera' => false]))
            ->assertSessionHasErrors('accepts_files');

        $this->post('/directories/document_types', $this->payload(['name' => 'Трудовая книжка', 'accepts_files' => false]))
            ->assertSessionHasNoErrors();

        $this->assertFalse(DocumentType::firstWhere('name', 'Трудовая книжка')->accepts_files);
    }

    /**
     * How many files one answer is made of: two numbers, and the second of them
     * may be left out altogether.
     *
     * A paper that names no upper bound is one no number of files is too many
     * for — a list of certificates is as long as somebody's courses were — and
     * that is said by leaving the box empty rather than by typing a number
     * large enough to be no limit in practice.
     */
    public function test_how_many_files_are_wanted_is_a_pair_whose_second_half_may_be_left_out()
    {
        $this->actingAs($this->admin);

        $this->post('/directories/document_types', $this->payload(['max_files' => 0]))->assertSessionHasErrors('max_files');
        $this->post('/directories/document_types', $this->payload(['max_files' => 21]))->assertSessionHasErrors('max_files');
        $this->post('/directories/document_types', $this->payload(['min_files' => 0]))->assertSessionHasErrors('min_files');
        // Fewer at most than at least is not a range at all.
        $this->post('/directories/document_types', $this->payload(['min_files' => 3, 'max_files' => 2]))->assertSessionHasErrors('max_files');

        $this->post('/directories/document_types', $this->payload(['name' => 'Сертификаты', 'max_files' => null]))->assertSessionHasNoErrors();
        $this->assertNull(DocumentType::firstWhere('name', 'Сертификаты')->max_files);

        // Exactly two, which is what «две фотографии» means and what a single
        // upper bound could never say.
        $this->post('/directories/document_types', $this->payload(['name' => 'Фотографии', 'min_files' => 2, 'max_files' => 2]))
            ->assertSessionHasNoErrors();
        $this->assertSame([2, 2], [
            DocumentType::firstWhere('name', 'Фотографии')->min_files,
            DocumentType::firstWhere('name', 'Фотографии')->max_files,
        ]);
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
