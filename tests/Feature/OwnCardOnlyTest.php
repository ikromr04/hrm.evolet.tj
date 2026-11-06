<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\DocumentType;
use App\Models\EmployeeDocument;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The site for a head of department and a specialist: their own card, and that
 * is the whole of it.
 *
 * It used to answer these two with one screen saying the work was under way.
 * The work is done as far as it goes: they sign in, land on their own card and
 * read every line of it — the passport, the family, the equipment they hold —
 * and the one thing on it that is theirs to do is hand in the documents the
 * company asks them for. Everything else is personnel's to correct, and every
 * other page is somebody else's: no staff list, no fleet, no reference lists,
 * no queue of anybody's papers.
 *
 * Which is where it stands for now rather than for ever: what either of them
 * should see of the company is a decision nobody has taken yet, and the rule
 * that says so is one list in App\Support\Access.
 */
class OwnCardOnlyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    }

    /** Somebody holding one of the two roles the site shows one card to. */
    private function person(string $role = 'specialist'): User
    {
        return User::factory()->create()->assignRole($role);
    }

    /**
     * The front page is the company's, so it is not theirs; what they get
     * instead is the card the front page would have sent them to anyway.
     */
    public function test_the_front_page_sends_them_to_their_own_card()
    {
        foreach (['head', 'specialist'] as $role) {
            $this->actingAs($this->person($role))->get('/')->assertRedirect('/profile');
        }
    }

    /** And the card itself opens, whole, with the three tabs it is read in. */
    public function test_their_own_card_opens_and_every_line_of_it_is_readable()
    {
        $person = $this->person();

        $this->actingAs($person)
            ->get('/profile')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('employees/show')
                ->where('isSelf', true)
                ->where('employee.id', $person->id)
                // The lines a card is read by, each under a right of its own —
                // and this person holds every one of them for their own card.
                ->where('visibleFields', fn ($fields) => $fields->contains('passport_number')
                    && $fields->contains('children')
                    && $fields->contains('equipment')
                    && $fields->contains('documents')
                    // Bar one: which role they hold is the system talking about
                    // itself, and the card says nothing of it.
                    && ! $fields->contains('role'))
                // Reading is the whole of it, bar the documents they hand in
                // themselves: a card is personnel's to correct.
                ->where('editableFields', ['documents'])
            );
    }

    /** The menu is told to hold one group, because the rest is not theirs to open. */
    public function test_the_page_says_the_menu_holds_one_group()
    {
        $this->actingAs($this->person())
            ->get('/profile')
            ->assertInertia(fn (Assert $page) => $page->where('auth.ownCardOnly', true)->where('auth.everything', false));

        $this->actingAs(User::factory()->create()->assignRole('sysadmin'))
            ->get('/profile')
            ->assertInertia(fn (Assert $page) => $page->where('auth.ownCardOnly', false)->where('auth.everything', true));
    }

    /**
     * Every other corner of the application is refused — the staff list, a
     * colleague's card, the fleet, the reference lists, the queue of documents.
     *
     * The fleet is the one worth naming: they hold a right that shows the units
     * on their own card, and the section opens for whoever may see any part of
     * the fleet. Without a rule that refuses outright, that one right would
     * have handed them the page.
     */
    public function test_every_other_page_is_refused()
    {
        $colleague = User::factory()->create();
        // A department of its own: a page about the company, which is the
        // thing these two are not shown.
        $unit = Department::create(['name' => 'Отдел Дизайна']);

        $addresses = [
            '/employees',
            "/employees/{$colleague->id}",
            '/org-chart',
            '/departments',
            // A department's own page, which carried no gate at all until these
            // two were let in: the company is open to everybody who works with
            // the company, and that is the sentence they fall outside of.
            "/departments/{$unit->id}",
            '/positions',
            '/inventory',
            '/directories',
        ];

        foreach ($addresses as $address) {
            $this->actingAs($this->person())->get($address)->assertForbidden();
        }
    }

    /**
     * Their own card by its id is still their own card: the address leads
     * where it leads for everybody, to /profile, rather than being refused the
     * way a colleague's id is.
     */
    public function test_their_own_card_is_not_refused_by_its_id()
    {
        $person = $this->person();

        $this->actingAs($person)->get("/employees/{$person->id}")->assertRedirect('/profile');
    }

    /**
     * The one thing they do rather than read: hand in a document, which then
     * waits for the office to look at it.
     */
    public function test_they_hand_in_their_own_documents_and_those_wait_to_be_looked_at()
    {
        Storage::fake('documents');

        $person = $this->person();
        $passport = DocumentType::create(['name' => 'Копия паспорта', 'on_paper' => false, 'is_required' => true]);

        $this->actingAs($person)
            ->post("/employees/{$person->id}/documents/{$passport->id}", ['files' => [UploadedFile::fake()->create('passport.pdf', 120, 'application/pdf')]])
            ->assertSessionHasNoErrors();

        $document = EmployeeDocument::sole();

        $this->assertSame(EmployeeDocument::PENDING, $document->status);

        // And passing it is not theirs: a person who could accept their own
        // document would be the whole of the checking.
        $this->actingAs($person)->put("/employees/{$person->id}/documents/{$document->id}/approve")->assertForbidden();
    }

    /** Nobody else's card is theirs to touch, documents and all. */
    public function test_a_colleagues_documents_are_not_theirs_to_touch()
    {
        $colleague = User::factory()->create();
        $passport = DocumentType::create(['name' => 'Копия паспорта', 'on_paper' => false, 'is_required' => true]);

        $this->actingAs($this->person())
            ->post("/employees/{$colleague->id}/documents/{$passport->id}", ['files' => [UploadedFile::fake()->create('passport.pdf', 120, 'application/pdf')]])
            ->assertForbidden();

        $this->assertSame(0, EmployeeDocument::count());
    }

    /** Signing out is still one press, as it was while the site was shut. */
    public function test_they_can_sign_out()
    {
        $this->actingAs($this->person())->post('/logout')->assertRedirect('/');
        $this->assertGuest();
    }
}
