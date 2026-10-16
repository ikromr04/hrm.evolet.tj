<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Position;
use App\Models\RegulationOrder;
use App\Models\RegulationSection;
use App\Models\User;
use App\Support\Directories;
use App\Support\Document;
use App\Support\Profile;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\PositionProfileTemplateSeeder;
use Database\Seeders\RegulationTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * «СФД» as a department keeps it: the same nine sections a position's profile
 * is made of, read on the department's page and written at the department's own
 * address.
 *
 * The company asked for the tab to hold the sheet rather than the list of jobs
 * that stood there, and the sheet is the one the company already wrote: one
 * template, read by a department and by a position alike, each rewriting a
 * section for itself. So what is pinned here is what the second kind of owner
 * adds — that the template is still one, that an override is one owner's and
 * nobody else's, that «01 ОБЩАЯ ИНФОРМАЦИЯ» is answered about a unit, which
 * right writes it, and that the rules which refused a document to the wrong
 * owner still refuse what they always refused.
 */
class DepartmentProfileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
    }

    /**
     * A department reads the nine standard sections — the same nine, from the
     * same template, numbered down its own page.
     */
    public function test_a_department_reads_the_nine_standard_sections()
    {
        $this->seed(PositionProfileTemplateSeeder::class);
        $department = $this->department();

        $profile = $this->profile($this->anybody(), $department);

        $this->assertCount(count(PositionProfileTemplateSeeder::SECTIONS), $profile['sections']);
        $this->assertSame(PositionProfileTemplateSeeder::SECTIONS, array_column($profile['sections'], 'title'));
        $this->assertSame(['01', '02', '03', '04', '05', '06', '07', '08', '09'], array_column($profile['sections'], 'number'));
        $this->assertSame(array_fill(0, 9, 'template'), array_column($profile['sections'], 'origin'));

        // The same shape a position's page sends, so one component reads
        // either, and the first section says it is drawn from data.
        $this->assertSame(
            ['id', 'origin', 'template_section_id', 'number', 'title', 'order', 'body', 'body_written', 'computed'],
            array_keys($profile['sections'][0]),
        );
        $this->assertSame(['can_edit', 'title', 'general', 'sections'], array_keys($profile));
        $this->assertTrue($profile['sections'][0]['computed']);

        // One template and not two: the nine a department reads are the nine a
        // position reads, the very same rows.
        $this->assertSame(
            RegulationSection::forPosition(null)->pluck('id')->all(),
            array_column($profile['sections'], 'id'),
        );
    }

    /**
     * «01 ОБЩАЯ ИНФОРМАЦИЯ» of a unit's sheet is answered from what the
     * application knows about the unit: where it sits, who leads it, how many
     * work there and what is under it. Nothing in it is typed, and nothing in
     * it is guessed.
     */
    public function test_the_first_section_is_read_from_the_structure()
    {
        $this->seed(PositionProfileTemplateSeeder::class);

        $department = Department::create(['name' => 'Департамент аналитики и статистики', 'abbreviation' => 'ДАС']);
        $unit = Department::create(['name' => 'Отдел Дизайна', 'abbreviation' => 'ОД', 'parent_id' => $department->id]);

        $head = User::factory()->create(['name' => 'Шахло', 'surname' => 'Назарова']);
        $head->departments()->attach($unit->id, ['is_head' => true]);
        $worker = User::factory()->create(['name' => 'Пётр', 'surname' => 'Иванов']);
        $worker->departments()->attach($unit->id, ['is_head' => false]);

        $general = $this->profile($this->anybody(), $unit)['general'];

        // Both steps of the structure, read the way a position's sheet reads
        // them: the unit itself, and the department it is part of.
        $this->assertSame('ОД', $general['unit']['name']);
        $this->assertSame('Отдел Дизайна', $general['unit']['full_name']);
        $this->assertSame('ДАС', $general['department']['name']);
        $this->assertSame('Департамент аналитики и статистики', $general['department']['full_name']);
        // Whoever is flagged as head among the people who still work here.
        $this->assertSame([['id' => $head->id, 'name' => 'Назарова Шахло']], $general['heads']);
        // Everybody who works there, heads among them.
        $this->assertSame(['own' => 2, 'total' => 2], $general['staff']);
        $this->assertSame([], $general['children']);
        // And nothing of a job's sheet: a unit has no «ДОЛЖНОСТЬ», and the two
        // rows a position leaves empty are not rows here.
        $this->assertSame(['unit', 'department', 'heads', 'staff', 'children'], array_keys($general));

        // The department above it names no отдел — it is the департамент — and
        // counts the branch under it as well as its own people.
        $above = $this->profile($this->anybody(), $department)['general'];

        $this->assertNull($above['unit']);
        $this->assertSame('ДАС', $above['department']['name']);
        $this->assertSame(['own' => 0, 'total' => 2], $above['staff']);
        $this->assertSame([['id' => $unit->id, 'name' => 'ОД', 'full_name' => 'Отдел Дизайна']], $above['children']);

        // A department nobody leads says so with an empty list rather than with
        // a name worked out from something else.
        $this->assertSame([], $above['heads']);
    }

    /**
     * Somebody who has left is nobody's head and is counted nowhere: the sheet
     * says who works here now.
     */
    public function test_the_sheet_counts_the_people_who_still_work_here()
    {
        $this->seed(PositionProfileTemplateSeeder::class);
        $department = $this->department();

        $gone = User::factory()->create(['status' => 'fired']);
        $gone->departments()->attach($department->id, ['is_head' => true]);

        $general = $this->profile($this->anybody(), $department)['general'];

        $this->assertSame([], $general['heads']);
        $this->assertSame(['own' => 0, 'total' => 0], $general['staff']);
    }

    /**
     * A department's wording of a standard section is read in that section's
     * place, for that department and for nobody else — not for the template,
     * not for another department, and not for a position.
     */
    public function test_a_department_s_own_wording_is_read_there_and_nowhere_else()
    {
        $this->seed(PositionProfileTemplateSeeder::class);
        $department = $this->department();
        $other = Department::create(['name' => 'Отдел регистрации']);
        $position = Position::create(['department_id' => $department->id, 'name' => 'Аналитик']);
        $standard = RegulationSection::forPosition(null)->firstWhere('title', 'ЗНАНИЕ ЯЗЫКОВ');

        $this->actingAs($this->keeper())
            ->post("/departments/{$department->id}/profile/sections", [
                'template_section_id' => $standard->id,
                'body' => '<p>Английский для всего отдела.</p>',
            ])
            ->assertRedirect();

        $own = RegulationSection::profileForDepartment($department)->firstWhere('title', 'ЗНАНИЕ ЯЗЫКОВ');

        $this->assertSame('override', $own->origin());
        $this->assertSame('<p>Английский для всего отдела.</p>', $own->body);
        $this->assertSame($department->id, $own->department_id);
        $this->assertNull($own->position_id);
        // Read where the standard section is read, so the document is still
        // nine sections in the same order.
        $this->assertCount(9, RegulationSection::profileForDepartment($department));
        $this->assertSame(
            RegulationSection::forPosition(null)->pluck('title')->all(),
            RegulationSection::profileForDepartment($department)->pluck('title')->all(),
        );

        // Nobody else reads it: not the template, not the department next door,
        // not a position of this very department.
        $this->assertNull(RegulationSection::forPosition(null)->firstWhere('title', 'ЗНАНИЕ ЯЗЫКОВ')->body);
        $this->assertNull(RegulationSection::profileForDepartment($other)->firstWhere('title', 'ЗНАНИЕ ЯЗЫКОВ')->body);
        $this->assertNull(RegulationSection::forPosition($position)->firstWhere('title', 'ЗНАНИЕ ЯЗЫКОВ')->body);

        // And a department's «Положение о работе» is untouched by any of it:
        // the two documents share a column and nothing else.
        $this->seed(RegulationTemplateSeeder::class);
        $this->assertSame(
            RegulationSection::forDepartment(null)->pluck('id')->all(),
            RegulationSection::forDepartment($department)->pluck('id')->all(),
        );
    }

    /**
     * A department and a position may rewrite the same standard section, each
     * for itself: the two are different owners, and the rule that one owner
     * rewrites one section once is about one owner.
     */
    public function test_a_department_and_a_position_may_rewrite_the_same_section()
    {
        $this->seed(PositionProfileTemplateSeeder::class);
        $department = $this->department();
        $position = Position::create(['department_id' => $department->id, 'name' => 'Аналитик']);
        $standard = RegulationSection::forPosition(null)->last();

        $this->actingAs($this->keeper())
            ->post("/departments/{$department->id}/profile/sections", ['template_section_id' => $standard->id, 'body' => '<p>Отдел.</p>'])
            ->assertRedirect();

        $this->actingAs($this->positionKeeper())
            ->post("/positions/{$position->id}/profile/sections", ['template_section_id' => $standard->id, 'body' => '<p>Должность.</p>'])
            ->assertRedirect();

        $this->assertSame('<p>Отдел.</p>', RegulationSection::profileForDepartment($department)->last()->body);
        $this->assertSame('<p>Должность.</p>', RegulationSection::forPosition($position)->last()->body);

        // Twice for the same owner is still once: the second attempt is the
        // same section rewritten, which the document has no room for.
        $this->actingAs($this->keeper())
            ->post("/departments/{$department->id}/profile/sections", ['template_section_id' => $standard->id])
            ->assertSessionHasErrors('template_section_id');
    }

    /**
     * Dropping a department's wording puts the standard text back — the one
     * deletion an owner's page has — and the section it stood for is untouched.
     */
    public function test_a_department_puts_the_standard_text_back()
    {
        $this->seed(PositionProfileTemplateSeeder::class);
        $department = $this->department();
        $standard = RegulationSection::forPosition(null)->last();

        $this->actingAs($this->keeper())
            ->post("/departments/{$department->id}/profile/sections", ['template_section_id' => $standard->id, 'body' => '<p>Наше.</p>'])
            ->assertRedirect();

        $own = RegulationSection::profileForDepartment($department)->last();

        $this->actingAs($this->keeper())
            ->delete("/departments/{$department->id}/profile/sections/{$own->id}")
            ->assertRedirect();

        $this->assertNull(RegulationSection::find($own->id));
        $this->assertSame($standard->id, RegulationSection::profileForDepartment($department)->last()->id);
        $this->assertCount(9, RegulationSection::forPosition(null));
    }

    /**
     * The arrangement of one of a department's documents leaves the other
     * where it was: both are the department's and both are kept in the same
     * column, so the places are told apart by the sections they name.
     */
    public function test_arranging_one_document_leaves_the_other_alone()
    {
        $this->seed(PositionProfileTemplateSeeder::class);
        $this->seed(RegulationTemplateSeeder::class);
        $department = $this->department();
        $keeper = $this->keeper();

        $regulation = RegulationSection::forDepartment($department)->pluck('id')->reverse()->values()->all();
        $this->actingAs($keeper)->put("/departments/{$department->id}/regulation/order", ['ids' => $regulation])->assertRedirect();

        $profile = RegulationSection::profileForDepartment($department)->pluck('id')->reverse()->values()->all();
        $this->actingAs($keeper)->put("/departments/{$department->id}/profile/order", ['ids' => $profile])->assertRedirect();

        // Each document reads in the order it was given, and neither moved the
        // other.
        $this->assertSame($regulation, RegulationSection::forDepartment($department)->pluck('id')->all());
        $this->assertSame($profile, RegulationSection::profileForDepartment($department)->pluck('id')->all());
        // Two arrangements, one owner, and every row of each names a section of
        // its own document.
        $this->assertSame(
            count($regulation) + count($profile),
            RegulationOrder::where('department_id', $department->id)->count(),
        );
    }

    /**
     * The heading of the sheet is the department's own, with a default in the
     * spirit of every other heading here: the name of the document and the name
     * of the unit, one ending away from correct and corrected in the field.
     */
    public function test_the_heading_of_the_sheet_is_the_department_s_own()
    {
        $this->seed(PositionProfileTemplateSeeder::class);
        $department = $this->department();
        $keeper = $this->keeper();

        $this->assertSame(
            Profile::UNIT_TITLE.' '.mb_strtoupper($department->name),
            $this->profile($this->anybody(), $department)['title'],
        );

        $this->actingAs($keeper)
            ->put("/departments/{$department->id}/profile/title", ['title' => 'ПРОФИЛЬ ОТДЕЛА АНАЛИТИКИ'])
            ->assertRedirect();

        $this->assertSame('ПРОФИЛЬ ОТДЕЛА АНАЛИТИКИ', $this->profile($this->anybody(), $department)['title']);
        // The other document's heading is a line of its own and did not move.
        $this->assertNull($department->fresh()->regulation_title);

        // Emptied means «back to the default», never «no heading».
        $this->actingAs($keeper)->put("/departments/{$department->id}/profile/title", ['title' => ''])->assertRedirect();

        $this->assertNull($department->fresh()->profile_title);
        $this->assertSame(
            Profile::UNIT_TITLE.' '.mb_strtoupper($department->name),
            $this->profile($this->anybody(), $department)['title'],
        );

        $this->actingAs($keeper)
            ->put("/departments/{$department->id}/profile/title", ['title' => str_repeat('а', Document::TITLE_LENGTH + 1)])
            ->assertSessionHasErrors('title');
    }

    /**
     * Writing the department's sheet is writing the department: the right that
     * keeps the structure, and not the one that keeps the list of job titles.
     * Reading it is reading the page, which is open to everybody signed in.
     */
    public function test_the_sheet_is_written_by_whoever_keeps_the_departments()
    {
        $this->seed(PositionProfileTemplateSeeder::class);
        $department = $this->department();
        $standard = RegulationSection::forPosition(null)->last();

        // Somebody who keeps the job titles and nothing else is a reader here.
        $this->actingAs($this->positionKeeper())
            ->post("/departments/{$department->id}/profile/sections", ['template_section_id' => $standard->id])
            ->assertForbidden();

        $this->actingAs($this->positionKeeper())
            ->put("/departments/{$department->id}/profile/title", ['title' => 'ЧУЖОЕ'])
            ->assertForbidden();

        $this->actingAs(User::factory()->create())
            ->post("/departments/{$department->id}/profile/sections", ['template_section_id' => $standard->id])
            ->assertForbidden();

        // And whoever keeps the departments writes it.
        $this->actingAs($this->keeper())
            ->post("/departments/{$department->id}/profile/sections", ['template_section_id' => $standard->id])
            ->assertRedirect();

        // The page itself, and the document on it, are open to everybody.
        $this->assertFalse($this->profile(User::factory()->create(), $department)['can_edit']);
        $this->assertTrue($this->profile($this->keeper(), $department)['can_edit']);
    }

    /**
     * The nine sections are the whole document for a department too: nothing is
     * added to it, nothing is taken out of it, and the section that is drawn
     * from data is not rewritten.
     */
    public function test_the_document_is_the_nine_sections_and_nothing_else()
    {
        $this->seed(PositionProfileTemplateSeeder::class);
        $department = $this->department();
        $keeper = $this->keeper();
        $general = RegulationSection::forPosition(null)->firstWhere('computed', true);

        // A section of its own.
        $this->actingAs($keeper)
            ->post("/departments/{$department->id}/profile/sections", ['title' => 'НАШ РАЗДЕЛ'])
            ->assertSessionHasErrors('template_section_id');

        // The one that is read from the structure.
        $this->actingAs($keeper)
            ->post("/departments/{$department->id}/profile/sections", ['template_section_id' => $general->id, 'body' => '<p>Отдел: ОД.</p>'])
            ->assertSessionHasErrors('template_section_id');

        // A standard section taken out of the company's own document from a
        // department's address.
        $this->actingAs($keeper)
            ->delete("/departments/{$department->id}/profile/sections/{$general->id}")
            ->assertNotFound();

        $this->assertCount(9, RegulationSection::profileForDepartment($department));
    }

    /**
     * What the owner rules refused before, they refuse still: a regulation is a
     * department's and nobody else's, an override belongs to an owner, and a
     * row belongs to one owner at a time.
     */
    public function test_the_owner_rules_still_refuse_what_they_refused()
    {
        $this->seed(PositionProfileTemplateSeeder::class);
        $department = $this->department();
        $position = Position::create(['department_id' => $department->id, 'name' => 'Аналитик']);

        // A regulation of a position: the document has one kind of owner.
        $this->assertThrows(
            fn () => RegulationSection::create(['document' => Document::REGULATION, 'position_id' => $position->id, 'title' => 'ЧУЖОЕ']),
            InvalidArgumentException::class,
        );

        // A profile of both at once.
        $this->assertThrows(
            fn () => RegulationSection::create([
                'document' => Document::PROFILE,
                'department_id' => $department->id,
                'position_id' => $position->id,
                'title' => 'ЧУЖОЕ',
            ]),
            InvalidArgumentException::class,
        );

        // A template section that is also an override.
        $standard = RegulationSection::forPosition(null)->last();

        $this->assertThrows(
            fn () => RegulationSection::create(['document' => Document::PROFILE, 'template_section_id' => $standard->id, 'title' => 'ЧУЖОЕ']),
            InvalidArgumentException::class,
        );

        // And a department's wording of a section of the other document.
        $this->seed(RegulationTemplateSeeder::class);
        $clause = RegulationSection::forDepartment(null)->first();

        $this->assertThrows(
            fn () => RegulationSection::create([
                'document' => Document::PROFILE,
                'department_id' => $department->id,
                'template_section_id' => $clause->id,
                'title' => 'ЧУЖОЕ',
            ]),
            InvalidArgumentException::class,
        );
    }

    /**
     * One department's address is not another's, and a department's address is
     * not a position's: a section read at the wrong one is simply not there.
     */
    public function test_a_section_is_written_at_its_own_address()
    {
        $this->seed(PositionProfileTemplateSeeder::class);
        $department = $this->department();
        $other = Department::create(['name' => 'Отдел регистрации']);
        $position = Position::create(['department_id' => $department->id, 'name' => 'Аналитик']);
        $standard = RegulationSection::forPosition(null)->last();

        $this->actingAs($this->keeper())
            ->post("/departments/{$department->id}/profile/sections", ['template_section_id' => $standard->id, 'body' => '<p>Наше.</p>'])
            ->assertRedirect();

        $own = RegulationSection::profileForDepartment($department)->last();

        $this->actingAs($this->keeper())
            ->put("/departments/{$other->id}/profile/sections/{$own->id}", ['body' => '<p>Чужое.</p>'])
            ->assertNotFound();

        $this->actingAs($this->positionKeeper())
            ->put("/positions/{$position->id}/profile/sections/{$own->id}", ['body' => '<p>Чужое.</p>'])
            ->assertNotFound();

        $this->assertSame('<p>Наше.</p>', $own->fresh()->body);
    }

    /** A unit of a department, which is where most of the structure is. */
    private function department(): Department
    {
        $above = Department::create(['name' => 'Департамент аналитики и статистики', 'abbreviation' => 'ДАС']);

        return Department::create(['name' => 'Отдел аналитики', 'abbreviation' => 'ОА', 'parent_id' => $above->id]);
    }

    /** Somebody who keeps the structure, and so the department's documents. */
    private function keeper(): User
    {
        return User::factory()->create()->givePermissionTo([
            Directories::viewPermission('departments'),
            Directories::editPermission('departments'),
        ]);
    }

    /** Somebody who keeps the job titles, which is a different document. */
    private function positionKeeper(): User
    {
        return User::factory()->create()->givePermissionTo([
            Directories::viewPermission('positions'),
            Directories::editPermission('positions'),
        ]);
    }

    /** Anybody signed in: a department's page asks for nothing more. */
    private function anybody(): User
    {
        return User::factory()->create();
    }

    /**
     * The department's own sheet as this person reads it on the department's
     * page.
     *
     * @return array<string, mixed>
     */
    private function profile(User $user, Department $department): array
    {
        return $this->actingAs($user)
            ->get("/departments/{$department->id}")
            ->assertOk()
            ->viewData('page')['props']['profile'];
    }
}
