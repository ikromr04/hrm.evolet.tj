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
use Illuminate\Support\Facades\Schema;
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
     * A department reads the standard text — the same text, from the same
     * template, opening with its own kind of heading.
     */
    public function test_a_department_reads_the_standard_text_as_one_sheet()
    {
        $this->seed(PositionProfileTemplateSeeder::class);
        $department = $this->department();

        $profile = $this->profile($this->anybody(), $department);

        $this->assertCount(1, $profile['sections']);
        $this->assertSame('template', $profile['sections'][0]['origin']);
        $this->assertSame(
            [
                Profile::UNIT_TITLE.' '.$department->name,
                '01 ДОЛЖНОСТНЫЕ ОБЯЗАННОСТИ',
                '02 ОБРАЗОВАНИЕ И ОПЫТ РАБОТЫ',
                '03 ЗНАНИЕ ЯЗЫКОВ',
                '04 ЖЕСТКИЕ НАВЫКИ',
                '05 МЯГКИЕ НАВЫКИ',
                '06 КРИТЕРИИ ОЦЕНКИ ЭФФЕКТИВНОСТИ РАБОТЫ',
                '07 ОТВЕТСТВЕННОСТЬ ЗА ДОКУМЕНТЫ ФАЙЛЫ',
                '08 ОЗНАКОМЛЕНИЕ',
            ],
            $this->headings($profile['sections'][0]['body']),
        );

        // The same shape a position's page sends, so one component reads
        // either — and no heading beside the document, because the sheet names
        // itself in its own first line.
        $this->assertSame(
            ['id', 'origin', 'template_section_id', 'number', 'title', 'order', 'body', 'body_written', 'computed'],
            array_keys($profile['sections'][0]),
        );
        $this->assertSame(['can_edit', 'general', 'sections'], array_keys($profile));
        $this->assertFalse($profile['sections'][0]['computed']);

        // One template and not two: the text a department reads is the text a
        // position reads, the very same row.
        $this->assertSame(
            RegulationSection::forPosition(null)->pluck('id')->all(),
            array_column($profile['sections'], 'id'),
        );
    }

    /**
     * What a unit's sheet used to open with is answered from what the
     * application knows about the unit: where it sits, who leads it, how many
     * work there and what is under it. Nothing in it is typed, and nothing in
     * it is guessed.
     *
     * It is not a section of the document any anymore — on this page it was the
     * column beside it written out a second time — so it travels as `general`
     * for that column to read.
     */
    public function test_what_the_structure_answers_is_sent_beside_the_document()
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
        $standard = RegulationSection::forPosition(null)->first();

        $this->actingAs($this->keeper())
            ->post("/departments/{$department->id}/profile/sections", [
                'template_section_id' => $standard->id,
                'body' => '<h2>{заголовок}</h2><p>Английский для всего отдела.</p>',
            ])
            ->assertRedirect();

        $own = RegulationSection::profileForDepartment($department)->first();

        $this->assertSame('override', $own->origin());
        $this->assertStringContainsString('Английский для всего отдела.', $own->body);
        $this->assertSame($department->id, $own->department_id);
        $this->assertNull($own->position_id);
        // Read in the place of the standard text, so the document is still one
        // text.
        $this->assertCount(1, RegulationSection::profileForDepartment($department));

        // Nobody else reads it: not the template, not the department next door,
        // not a position of this very department.
        foreach ([RegulationSection::forPosition(null), RegulationSection::profileForDepartment($other), RegulationSection::forPosition($position)] as $elsewhere) {
            $this->assertStringNotContainsString('Английский для всего отдела.', (string) $elsewhere->first()->body);
        }

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

        $this->assertSame('<p>Отдел.</p>', RegulationSection::profileForDepartment($department)->first()->body);
        $this->assertSame('<p>Должность.</p>', RegulationSection::forPosition($position)->first()->body);

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

        $own = RegulationSection::profileForDepartment($department)->first();

        $this->actingAs($this->keeper())
            ->delete("/departments/{$department->id}/profile/sections/{$own->id}")
            ->assertRedirect();

        $this->assertNull(RegulationSection::find($own->id));
        $this->assertSame($standard->id, RegulationSection::profileForDepartment($department)->first()->id);
        $this->assertCount(1, RegulationSection::forPosition(null));
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
     * The sheet is named in the first line of its own text, and a unit's sheet
     * opens as a unit's: one template, read through the one token this document
     * has, answering «ПРОФИЛЬ ПОДРАЗДЕЛЕНИЯ» here and «ПРОФИЛЬ ДОЛЖНОСТИ» on a
     * position's page.
     */
    public function test_the_sheet_is_named_in_the_first_line_of_its_own_text()
    {
        $this->seed(PositionProfileTemplateSeeder::class);
        $department = $this->department();
        $keeper = $this->keeper();

        $profile = $this->profile($this->anybody(), $department);

        $this->assertArrayNotHasKey('title', $profile);
        $this->assertSame(Profile::UNIT_TITLE.' '.$department->name, $this->headings($profile['sections'][0]['body'])[0]);

        // The department that wants the ending right writes it, in the text.
        $this->actingAs($keeper)
            ->post("/departments/{$department->id}/profile/sections", [
                'template_section_id' => $profile['sections'][0]['id'],
                'body' => '<h2>ПРОФИЛЬ ОТДЕЛА АНАЛИТИКИ</h2>',
            ])
            ->assertRedirect();

        $this->assertSame(
            'ПРОФИЛЬ ОТДЕЛА АНАЛИТИКИ',
            $this->headings($this->profile($this->anybody(), $department)['sections'][0]['body'])[0],
        );

        // Neither document this department keeps has a column for a heading any
        // more, and neither has an address for one.
        $this->assertFalse(Schema::hasColumn('departments', 'regulation_title'));
        $this->assertFalse(Schema::hasColumn('departments', 'profile_title'));
        $this->actingAs($keeper)
            ->put("/departments/{$department->id}/profile/title", ['title' => 'ПРОФИЛЬ ОТДЕЛА АНАЛИТИКИ'])
            ->assertNotFound();
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
     * One text is the whole document for a department too: nothing is added to
     * it and nothing is taken out of it.
     */
    public function test_the_document_is_one_text_and_nothing_else()
    {
        $this->seed(PositionProfileTemplateSeeder::class);
        $department = $this->department();
        $keeper = $this->keeper();
        $standard = RegulationSection::forPosition(null)->first();

        // A section of its own.
        $this->actingAs($keeper)
            ->post("/departments/{$department->id}/profile/sections", ['title' => 'НАШ РАЗДЕЛ'])
            ->assertSessionHasErrors('template_section_id');

        // The company's own text taken out of the document from a department's
        // address.
        $this->actingAs($keeper)
            ->delete("/departments/{$department->id}/profile/sections/{$standard->id}")
            ->assertNotFound();

        $this->assertCount(1, RegulationSection::profileForDepartment($department));
    }

    /**
     * The owner rules, now that both documents take both kinds of owner: a row
     * belongs to one owner at a time, an override belongs to an owner, and an
     * override stands for a standard section of its own document.
     *
     * The one rule that went is the one that said a regulation is a
     * department's and nobody else's. What is left is about shape rather than
     * about which document it is.
     */
    public function test_the_owner_rules_still_refuse_what_they_refused()
    {
        $this->seed(PositionProfileTemplateSeeder::class);
        $department = $this->department();
        $position = Position::create(['department_id' => $department->id, 'name' => 'Аналитик']);

        // A regulation of a position is no longer one of them: the company
        // asked for a job to keep one of its own, so both documents take both
        // kinds of owner now.
        $this->assertNotNull(
            RegulationSection::create(['document' => Document::REGULATION, 'position_id' => $position->id, 'title' => 'СВОЁ'])->fresh(),
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
    /**
     * The headings a body is divided by, which is how the parts of the document
     * are read now that it is one text.
     *
     * @return list<string>
     */
    private function headings(?string $body): array
    {
        preg_match_all('/<h2>(.*?)<\/h2>/su', (string) $body, $found);

        return $found[1];
    }

    private function profile(User $user, Department $department): array
    {
        return $this->actingAs($user)
            ->get("/departments/{$department->id}")
            ->assertOk()
            ->viewData('page')['props']['profile'];
    }
}
