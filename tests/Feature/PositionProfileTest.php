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
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * «Профиль должности» — the sheet the company calls СФД — on a position's page:
 * the nine standard sections of its contents page, what one position keeps of
 * its own wording of them, the table at the top that nobody types, the heading
 * it is printed under, and who may write any of it.
 *
 * It is the same machinery «Положение о работе» is kept by, one step further
 * down the structure: a template everybody reads, an override that replaces one
 * section for one owner, an arrangement of the owner's own, a heading with a
 * default. So the rules those tests pin are not pinned again here — what is
 * tested is what the profile adds: that its owner is a position, that its first
 * section is read from the directory rather than written, and that the two
 * documents cannot reach into each other.
 */
class PositionProfileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
    }

    /**
     * The document the company laid out: nine headings, in the order its own
     * contents page lists them, with nothing underneath any of them.
     */
    public function test_the_nine_sections_are_the_document_and_are_seeded_empty()
    {
        $this->seed(PositionProfileTemplateSeeder::class);

        $template = RegulationSection::forPosition(null);

        $this->assertSame([
            'ОБЩАЯ ИНФОРМАЦИЯ',
            'ДОЛЖНОСТНЫЕ ОБЯЗАННОСТИ',
            'ОБРАЗОВАНИЕ И ОПЫТ РАБОТЫ',
            'ЗНАНИЕ ЯЗЫКОВ',
            'ЖЕСТКИЕ НАВЫКИ',
            'МЯГКИЕ НАВЫКИ',
            'КРИТЕРИИ ОЦЕНКИ ЭФФЕКТИВНОСТИ РАБОТЫ',
            'ОТВЕТСТВЕННОСТЬ ЗА ДОКУМЕНТЫ ФАЙЛЫ',
            'ОЗНАКОМЛЕНИЕ',
        ], $template->pluck('title')->all());

        // Empty on purpose. What belongs under «МЯГКИЕ НАВЫКИ» is different for
        // every job in the company, and a seeded paragraph of it would be words
        // the company never wrote on a sheet somebody signs.
        $this->assertSame([], $template->pluck('body')->filter()->all());

        // And the first section is not empty but unwritable: it is the table
        // read from the directory.
        $this->assertSame([true, false, false, false, false, false, false, false, false], $template->pluck('computed')->all());

        // Somebody has corrected a heading and dropped a section since the
        // first day. A second run leaves both alone: the document belongs to
        // them now.
        $template->first()->update(['title' => 'ОБЩИЕ СВЕДЕНИЯ']);
        $template->last()->delete();

        $this->seed(PositionProfileTemplateSeeder::class);

        $again = RegulationSection::forPosition(null);

        $this->assertCount(8, $again);
        $this->assertSame('ОБЩИЕ СВЕДЕНИЯ', $again->first()->title);
    }

    /**
     * Each document's standard text is written although neither of them
     * belongs to anybody.
     *
     * The profile first, which is the order nothing runs in and the one that
     * catches it: a template section names no owner in either document, so a
     * seeder that asks only «is there a section with no department?» would read
     * the profile's nine as the regulation's four and write nothing at all.
     */
    public function test_each_template_is_written_although_neither_belongs_to_anybody()
    {
        $this->seed(PositionProfileTemplateSeeder::class);
        $this->seed(RegulationTemplateSeeder::class);

        $this->assertCount(9, RegulationSection::forPosition(null));
        $this->assertCount(4, RegulationSection::forDepartment(null));
    }

    /**
     * One position reads the whole of it, numbered by where each section is
     * read — the same shape the department's page sends its regulation in, so
     * one component draws either document.
     */
    public function test_a_position_reads_the_document_numbered_down_the_page()
    {
        $this->seed(PositionProfileTemplateSeeder::class);
        $position = $this->position();

        $profile = $this->profile($this->reader(), $position);

        $this->assertSame(['01', '02', '03', '04', '05', '06', '07', '08', '09'], array_column($profile['sections'], 'number'));
        $this->assertSame(array_fill(0, 9, 'template'), array_column($profile['sections'], 'origin'));
        $this->assertSame([null], array_unique(array_column($profile['sections'], 'template_section_id')));

        // Every key the page is built against, and nothing else.
        $this->assertSame(
            ['id', 'origin', 'template_section_id', 'number', 'title', 'order', 'body', 'body_written', 'computed'],
            array_keys($profile['sections'][0]),
        );
        $this->assertSame(['can_edit', 'title', 'general', 'sections'], array_keys($profile));
    }

    /**
     * «01 ОБЩАЯ ИНФОРМАЦИЯ» is answered from the directory: both steps of the
     * structure and the name of the job. As the fields themselves, so the page
     * draws the document's own table.
     */
    public function test_the_first_section_is_read_from_the_directory()
    {
        $this->seed(PositionProfileTemplateSeeder::class);

        $department = Department::create(['name' => 'Департамент аналитики и статистики', 'abbreviation' => 'ДАС']);
        $unit = Department::create(['name' => 'Отдел Дизайна', 'abbreviation' => 'ОД', 'parent_id' => $department->id]);
        $position = Position::create(['department_id' => $unit->id, 'name' => 'Графический дизайнер']);

        $profile = $this->profile($this->reader(), $position);
        $general = $profile['general'];

        $this->assertSame('ОД', $general['unit']['name']);
        $this->assertSame('Отдел Дизайна', $general['unit']['full_name']);
        $this->assertSame('ДАС', $general['department']['name']);
        $this->assertSame('Департамент аналитики и статистики', $general['department']['full_name']);
        $this->assertSame('Графический дизайнер', $general['position']);

        // The section itself carries no text and says it is drawn from data, so
        // the page reads the fields in its place.
        $this->assertTrue($profile['sections'][0]['computed']);
        $this->assertNull($profile['sections'][0]['body']);

        // A position attached to a department rather than to a unit inside one
        // names the department and leaves the отдел empty: the field would
        // otherwise read the same name twice.
        $head = Position::create(['department_id' => $department->id, 'name' => 'Руководитель']);
        $general = $this->profile($this->reader(), $head)['general'];

        $this->assertNull($general['unit']);
        $this->assertSame('ДАС', $general['department']['name']);

        // And one the data could not place names neither.
        $loose = Position::create(['name' => 'Уборщица']);
        $general = $this->profile($this->reader(), $loose)['general'];

        $this->assertNull($general['unit']);
        $this->assertNull($general['department']);
    }

    /**
     * What the application cannot answer it leaves empty rather than guessing.
     *
     * «ПОДЧИНЕНИЕ» and «ПОДЧИНЕННЫЕ» are a hierarchy of positions, and nothing
     * here keeps one: what is on file is which department sits inside which, and
     * which *people* lead a department. Reading a senior job out of that would
     * mean reading it off whoever holds the job today — and a holder may hold
     * two positions, a department may have several heads or none, and a position
     * nobody holds would answer nothing at all, which is the profile somebody
     * hires against.
     */
    public function test_a_hierarchy_of_positions_is_left_empty_because_nothing_keeps_one()
    {
        $this->seed(PositionProfileTemplateSeeder::class);

        $department = Department::create(['name' => 'Департамент аналитики и статистики']);
        $unit = Department::create(['name' => 'Отдел Дизайна', 'parent_id' => $department->id]);
        $position = Position::create(['department_id' => $unit->id, 'name' => 'Графический дизайнер']);

        // Everything the application does know about who leads whom, in place:
        // a head of the unit, a head of the department above it, and both of
        // them holding positions of their own.
        foreach ([[$unit, 'Руководитель отдела дизайна'], [$department, 'Руководитель департамента']] as [$where, $title]) {
            $head = User::factory()->create();
            $head->departments()->attach($where->id, ['is_head' => true]);
            $head->positions()->attach(Position::create(['department_id' => $where->id, 'name' => $title])->id);
        }

        $general = $this->profile($this->reader(), $position)['general'];

        $this->assertNull($general['reports_to']);
        $this->assertSame([], $general['subordinates']);
    }

    /**
     * A position's own wording of a standard section is read in that section's
     * place, for that position and for nobody else.
     */
    public function test_a_position_s_own_wording_is_read_there_and_nowhere_else()
    {
        $this->seed(PositionProfileTemplateSeeder::class);
        $position = $this->position();
        $other = Position::create(['department_id' => $position->department_id, 'name' => 'Переводчик']);
        $languages = RegulationSection::forPosition(null)->firstWhere('title', 'ЗНАНИЕ ЯЗЫКОВ');

        $this->actingAs($this->keeper())
            ->post("/positions/{$position->id}/profile/sections", [
                'template_section_id' => $languages->id,
                'body' => '<p>Английский — свободно.</p>',
            ])
            ->assertRedirect();

        $sections = collect($this->profile($this->reader(), $position)['sections'])->keyBy('title');

        $this->assertSame('override', $sections['ЗНАНИЕ ЯЗЫКОВ']['origin']);
        $this->assertSame($languages->id, $sections['ЗНАНИЕ ЯЗЫКОВ']['template_section_id']);
        $this->assertSame('<p>Английский — свободно.</p>', $sections['ЗНАНИЕ ЯЗЫКОВ']['body']);
        // The profile has no placeholders, so what was written is what is read.
        $this->assertSame($sections['ЗНАНИЕ ЯЗЫКОВ']['body'], $sections['ЗНАНИЕ ЯЗЫКОВ']['body_written']);
        // And it is read where the standard section was read, not at the end.
        $this->assertSame('04', $sections['ЗНАНИЕ ЯЗЫКОВ']['number']);

        $elsewhere = collect($this->profile($this->reader(), $other)['sections'])->keyBy('title');

        $this->assertSame('template', $elsewhere['ЗНАНИЕ ЯЗЫКОВ']['origin']);
        $this->assertNull($elsewhere['ЗНАНИЕ ЯЗЫКОВ']['body']);

        // Another position rewrites the very same standard section, and both
        // wordings stand: the row of an override names no department, and two
        // of them would collide on the pair the departments are kept unique by
        // if a null counted as a value there.
        $this->actingAs($this->keeper())
            ->post("/positions/{$other->id}/profile/sections", [
                'template_section_id' => $languages->id,
                'body' => '<p>Русский и таджикский.</p>',
            ])
            ->assertRedirect();

        $this->assertSame(
            '<p>Русский и таджикский.</p>',
            collect($this->profile($this->reader(), $other)['sections'])->keyBy('title')['ЗНАНИЕ ЯЗЫКОВ']['body'],
        );
        $this->assertSame(
            '<p>Английский — свободно.</p>',
            collect($this->profile($this->reader(), $position)['sections'])->keyBy('title')['ЗНАНИЕ ЯЗЫКОВ']['body'],
        );

        // And twice for one position is one job written down twice.
        $this->actingAs($this->keeper())
            ->post("/positions/{$other->id}/profile/sections", ['template_section_id' => $languages->id])
            ->assertSessionHasErrors('template_section_id');

        // Dropped, and the standard section is read again.
        $own = RegulationSection::where('position_id', $position->id)->firstOrFail();

        $this->actingAs($this->keeper())->delete("/positions/{$position->id}/profile/sections/{$own->id}")->assertRedirect();

        $this->assertCount(9, RegulationSection::forPosition($position));
        $this->assertSame('template', collect($this->profile($this->reader(), $position)['sections'])->keyBy('title')['ЗНАНИЕ ЯЗЫКОВ']['origin']);
    }

    /**
     * Nine sections, and nine is the whole of it: nothing is added to the
     * document and nothing is taken out of it.
     */
    public function test_a_position_adds_no_section_and_takes_none_out()
    {
        $this->seed(PositionProfileTemplateSeeder::class);
        $position = $this->position();
        $keeper = $this->keeper();

        $this->actingAs($keeper)
            ->post("/positions/{$position->id}/profile/sections", ['title' => 'СВОЙ РАЗДЕЛ', 'body' => '<p>Текст.</p>'])
            ->assertSessionHasErrors(['template_section_id' => 'Профиль должности состоит из девяти стандартных разделов — новые разделы в него не добавляются.']);

        $this->assertCount(9, RegulationSection::forPosition($position));

        // A standard section is not one position's to take out, and at a
        // position's address it is simply not there.
        $standard = RegulationSection::forPosition(null)->last();

        $this->actingAs($keeper)->delete("/positions/{$position->id}/profile/sections/{$standard->id}")->assertNotFound();

        // The one row a position can hold that is nobody's wording of a
        // standard section: the company dropped the section from under it. That
        // text is the position's to keep, not ours to delete by the button that
        // means «вернуть стандартный текст».
        $this->actingAs($keeper)
            ->post("/positions/{$position->id}/profile/sections", ['template_section_id' => $standard->id, 'body' => '<p>Своя.</p>'])
            ->assertRedirect();
        $this->actingAs($keeper)->delete("/positions/profile/sections/{$standard->id}")->assertRedirect();

        $left = RegulationSection::where('position_id', $position->id)->firstOrFail();

        $this->assertSame('own', $left->origin());
        $this->actingAs($keeper)
            ->delete("/positions/{$position->id}/profile/sections/{$left->id}")
            ->assertSessionHasErrors(['section' => 'Профиль должности состоит из девяти стандартных разделов: раздел нельзя удалить, можно только вернуть его стандартный текст.']);
        $this->assertNotNull($left->fresh());
    }

    /**
     * The section drawn from the directory is not a section anybody writes in:
     * a body typed under that heading would be a second answer to the same
     * question, and the week somebody is moved the two would disagree.
     */
    public function test_the_section_read_from_the_directory_is_never_written_in()
    {
        $this->seed(PositionProfileTemplateSeeder::class);
        $position = $this->position();
        $keeper = $this->keeper();
        $general = RegulationSection::forPosition(null)->firstWhere('computed', true);
        $refusal = 'Раздел «ОБЩАЯ ИНФОРМАЦИЯ» заполняется из системы — его текст не пишут и не переопределяют.';

        $this->actingAs($keeper)
            ->post("/positions/{$position->id}/profile/sections", ['template_section_id' => $general->id, 'body' => '<p>Отдел: ОД.</p>'])
            ->assertSessionHasErrors(['template_section_id' => $refusal]);

        $this->assertSame(0, RegulationSection::where('position_id', $position->id)->count());

        // Nor at the template's address, where the standard text is written.
        $this->actingAs($keeper)
            ->put("/positions/profile/sections/{$general->id}", ['body' => '<p>Отдел: ОД.</p>'])
            ->assertSessionHasErrors(['body' => $refusal]);

        $this->assertNull($general->fresh()->body);

        // Its heading is a heading like any other, and where it is read is the
        // company's to change.
        $this->actingAs($keeper)
            ->put("/positions/profile/sections/{$general->id}", ['title' => 'ОБЩИЕ СВЕДЕНИЯ'])
            ->assertRedirect();

        $this->assertSame('ОБЩИЕ СВЕДЕНИЯ', $general->fresh()->title);
        $this->assertTrue($general->fresh()->computed);
    }

    /**
     * A position arranges the whole of its own document, and the arrangement
     * reaches nobody else.
     */
    public function test_a_position_arranges_its_own_document()
    {
        $this->seed(PositionProfileTemplateSeeder::class);
        $position = $this->position();
        $other = Position::create(['department_id' => $position->department_id, 'name' => 'Переводчик']);
        $keeper = $this->keeper();

        $ids = RegulationSection::forPosition($position)->pluck('id')->all();
        $moved = [...array_slice($ids, 1), $ids[0]];

        $this->actingAs($keeper)->put("/positions/{$position->id}/profile/order", ['ids' => $moved])->assertRedirect();

        $this->assertSame($moved, RegulationSection::forPosition($position)->pluck('id')->all());
        $this->assertSame($ids, RegulationSection::forPosition($other)->pluck('id')->all());

        // The whole document or nothing: a list that leaves a section out is
        // not an order of the document.
        $this->actingAs($keeper)
            ->put("/positions/{$position->id}/profile/order", ['ids' => array_slice($ids, 0, 3)])
            ->assertSessionHasErrors(['ids' => 'Нужно перечислить все разделы профиля должности, каждый по одному разу.']);

        $this->assertSame($moved, RegulationSection::forPosition($position)->pluck('id')->all());
    }

    /** The heading of the printed sheet: a default that is almost right, typed over once. */
    public function test_the_heading_of_the_sheet_is_the_position_s_own()
    {
        $this->seed(PositionProfileTemplateSeeder::class);
        $position = $this->position();
        $keeper = $this->keeper();

        $this->assertSame(Profile::TITLE.' '.mb_strtoupper($position->name), $this->profile($this->reader(), $position)['title']);

        $this->actingAs($keeper)
            ->put("/positions/{$position->id}/profile/title", ['title' => 'ПРОФИЛЬ ДОЛЖНОСТИ ГРАФИЧЕСКОГО ДИЗАЙНЕРА'])
            ->assertRedirect();

        $this->assertSame('ПРОФИЛЬ ДОЛЖНОСТИ ГРАФИЧЕСКОГО ДИЗАЙНЕРА', $this->profile($this->reader(), $position)['title']);

        // Emptied means «back to the default», never «no heading».
        $this->actingAs($keeper)->put("/positions/{$position->id}/profile/title", ['title' => ''])->assertRedirect();

        $this->assertNull($position->fresh()->profile_title);
        $this->assertSame(Profile::TITLE.' '.mb_strtoupper($position->name), $this->profile($this->reader(), $position)['title']);

        $this->actingAs($keeper)
            ->put("/positions/{$position->id}/profile/title", ['title' => str_repeat('а', Document::TITLE_LENGTH + 1)])
            ->assertSessionHasErrors(['title' => 'Заголовок профиля должности не должен быть длиннее '.Document::TITLE_LENGTH.' символов.']);
    }

    /**
     * Keeping the profile is keeping the position, and reading it is reading
     * the list of job titles.
     */
    public function test_whoever_keeps_the_positions_writes_it_and_whoever_reads_the_list_reads_it()
    {
        $this->seed(PositionProfileTemplateSeeder::class);
        $position = $this->position();
        $standard = RegulationSection::forPosition(null)->last();

        // Signed in and holding nothing: a position's page is not open to
        // everybody the way a department's is, so neither is the document on it.
        $this->actingAs(User::factory()->create())->get("/positions/{$position->id}")->assertForbidden();

        // Reading the list is enough to read the document, and not enough to
        // write a word of it.
        $reader = $this->reader();

        $this->assertFalse($this->profile($reader, $position)['can_edit']);
        $this->actingAs($reader)
            ->post("/positions/{$position->id}/profile/sections", ['template_section_id' => $standard->id])
            ->assertForbidden();
        $this->actingAs($reader)->put("/positions/{$position->id}/profile/title", ['title' => 'ЧУЖОЕ'])->assertForbidden();
        $this->actingAs($reader)->put('/positions/profile/order', ['ids' => [$standard->id]])->assertForbidden();

        $keeper = $this->keeper();

        $this->assertTrue($this->profile($keeper, $position)['can_edit']);
        $this->actingAs($keeper)
            ->post("/positions/{$position->id}/profile/sections", ['template_section_id' => $standard->id])
            ->assertRedirect();
    }

    /**
     * Two documents, one piece of machinery, and no way for either to reach
     * into the other.
     */
    public function test_the_two_documents_do_not_mix()
    {
        $this->seed(PositionProfileTemplateSeeder::class);
        $position = $this->position();
        $department = Department::create(['name' => 'Департамент маркетинга']);
        $keeper = $this->keeper();

        $regulation = RegulationSection::create(['title' => 'ОБЩЕЕ ПОЛОЖЕНИЕ', 'body' => '<p>{подразделение}.</p>', 'order' => 100]);

        // Each document reads its own sections and only those.
        $this->assertCount(9, RegulationSection::forPosition(null));
        $this->assertCount(1, RegulationSection::forDepartment(null));
        $this->assertSame([$regulation->id], RegulationSection::forDepartment($department)->pluck('id')->all());

        // A section of one is not a standard section of the other, at either
        // address.
        $profileSection = RegulationSection::forPosition(null)->last();

        $this->actingAs($this->departmentKeeper())
            ->post("/departments/{$department->id}/regulation/sections", ['template_section_id' => $profileSection->id])
            ->assertSessionHasErrors(['template_section_id']);
        $this->actingAs($keeper)
            ->post("/positions/{$position->id}/profile/sections", ['template_section_id' => $regulation->id])
            ->assertSessionHasErrors(['template_section_id']);

        // Nor is it the other document's to edit or to drop.
        $this->actingAs($keeper)->put("/positions/profile/sections/{$regulation->id}", ['title' => 'ЧУЖОЕ'])->assertNotFound();
        $this->actingAs($keeper)->delete("/positions/profile/sections/{$regulation->id}")->assertNotFound();
        $this->assertSame('ОБЩЕЕ ПОЛОЖЕНИЕ', $regulation->fresh()->title);

        // And a row belongs to one owner. The model says so in Russian, and the
        // database says so whether anything goes through the model or not.
        try {
            RegulationSection::create([
                'document' => Document::PROFILE,
                'position_id' => $position->id,
                'department_id' => $department->id,
                'title' => 'ЧЕЙ-ТО',
            ]);
            $this->fail('A section of two owners was written.');
        } catch (InvalidArgumentException $refused) {
            $this->assertSame('Раздел документа принадлежит одному владельцу: подразделению или должности, но не обоим сразу.', $refused->getMessage());
        }

        $this->expectException(QueryException::class);

        RegulationSection::query()->getQuery()->insert([
            'document' => Document::PROFILE,
            'position_id' => $position->id,
            'department_id' => $department->id,
            'title' => 'ЧЕЙ-ТО',
            'order' => 0,
        ]);
    }

    /** The profile is part of the position, so it goes with it. */
    public function test_a_deleted_position_takes_its_profile_with_it()
    {
        $this->seed(PositionProfileTemplateSeeder::class);
        $position = $this->position();
        $keeper = $this->keeper();
        $standard = RegulationSection::forPosition(null)->last();

        $this->actingAs($keeper)
            ->post("/positions/{$position->id}/profile/sections", ['template_section_id' => $standard->id, 'body' => '<p>Своя.</p>'])
            ->assertRedirect();
        $this->actingAs($keeper)
            ->put("/positions/{$position->id}/profile/order", ['ids' => RegulationSection::forPosition($position)->pluck('id')->reverse()->values()->all()])
            ->assertRedirect();

        $own = RegulationSection::where('position_id', $position->id)->firstOrFail();

        $this->assertSame(9, RegulationOrder::where('position_id', $position->id)->count());

        $position->delete();

        $this->assertNull(RegulationSection::find($own->id));
        $this->assertSame(0, RegulationOrder::where('position_id', $position->id)->count());
        // And the company's own nine are exactly where they were.
        $this->assertCount(9, RegulationSection::forPosition(null));
    }

    /**
     * A position's profile travels with the position's page and with nothing
     * else. The department's page carries «СФД» of its own now — the same nine
     * sections written about the unit — and never a list of the jobs under it,
     * which is what stood in that tab before the company said what it wanted
     * there.
     */
    public function test_the_department_s_page_sends_its_own_sheet_and_no_position_s()
    {
        $this->seed(PositionProfileTemplateSeeder::class);
        $position = $this->position();

        $props = $this->page($this->reader(), $position->department);

        // The list is gone, and nothing on this page names a position's sheet.
        $this->assertArrayNotHasKey('profiles', $props);
        // What is there is the department's own, headed as a unit's rather
        // than as a job's, and its first section answers about the unit: there
        // is no «ДОЛЖНОСТЬ» in it to read.
        $this->assertSame(
            Profile::UNIT_TITLE.' '.mb_strtoupper($position->department->name),
            $props['profile']['title'],
        );
        $this->assertArrayNotHasKey('position', $props['profile']['general']);
    }

    /** A position filed in a unit of a department, which is how most of them are. */
    private function position(): Position
    {
        $department = Department::create(['name' => 'Департамент аналитики и статистики', 'abbreviation' => 'ДАС']);
        $unit = Department::create(['name' => 'Отдел Дизайна', 'abbreviation' => 'ОД', 'parent_id' => $department->id]);

        return Position::create(['department_id' => $unit->id, 'name' => 'Графический дизайнер']);
    }

    /** Somebody who reads the list of job titles and keeps it. */
    private function keeper(): User
    {
        return User::factory()->create()->givePermissionTo([
            Directories::viewPermission('positions'),
            Directories::editPermission('positions'),
        ]);
    }

    /** Somebody who only reads it. */
    /**
     * A position reads the regulation of the department it belongs to, and reads
     * it only: the document is kept on the department's page, and a position
     * that belongs nowhere has none to read.
     */
    public function test_a_position_reads_the_regulation_of_its_department()
    {
        $this->seed(RegulationTemplateSeeder::class);

        $department = Department::create(['name' => 'Отдел аналитики', 'abbreviation' => 'ОА']);
        $position = Position::create(['department_id' => $department->id, 'name' => 'Аналитик']);

        $regulation = $this->props($this->reader(), $position)['regulation'];

        $this->assertSame('ОА', $regulation['department']['name']);
        $this->assertSame('Отдел аналитики', $regulation['department']['full_name']);
        // The same document the department's own page reads, heading and all.
        $this->assertSame('ПОЛОЖЕНИЕ О РАБОТЕ ОТДЕЛ АНАЛИТИКИ', $regulation['paper']['title']);
        $this->assertCount(count(RegulationTemplateSeeder::SECTIONS), $regulation['paper']['sections']);
        $this->assertSame('01', $regulation['paper']['sections'][0]['number']);
        $this->assertStringContainsString('Отдел аналитики является', $regulation['paper']['sections'][0]['body']);

        // Nothing here offers to write: the page that writes it is one link
        // away, and one text editable from two places is how two texts begin.
        $this->assertArrayNotHasKey('can_edit', $regulation);

        // A position filed nowhere has no department, so there is no document.
        $unfiled = Position::create(['department_id' => null, 'name' => 'Уборщица']);

        $this->assertNull($this->props($this->reader(), $unfiled)['regulation']);
    }

    private function reader(): User
    {
        return User::factory()->create()->givePermissionTo(Directories::viewPermission('positions'));
    }

    /** Somebody who keeps the departments, for the regulation's half of a test. */
    private function departmentKeeper(): User
    {
        return User::factory()->create()->givePermissionTo([
            Directories::viewPermission('departments'),
            Directories::editPermission('departments'),
        ]);
    }

    /**
     * The profile as this person reads it on the position's page.
     *
     * @return array<string, mixed>
     */
    private function profile(User $user, Position $position): array
    {
        return $this->props($user, $position)['profile'];
    }

    /**
     * The whole of a position page's props, for the parts of it that are not
     * the profile.
     *
     * @return array<string, mixed>
     */
    private function props(User $user, Position $position): array
    {
        return $this->actingAs($user)
            ->get("/positions/{$position->id}")
            ->assertOk()
            ->viewData('page')['props'];
    }

    /**
     * The department's page as this person reads it.
     *
     * @return array<string, mixed>
     */
    private function page(User $user, Department $department): array
    {
        return $this->actingAs($user)
            ->get("/departments/{$department->id}")
            ->assertOk()
            ->viewData('page')['props'];
    }
}
