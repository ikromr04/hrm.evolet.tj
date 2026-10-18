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
use Illuminate\Support\Facades\Schema;
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
     * The document the company laid out, as one text: its own name first, then
     * a heading for each part its contents page lists, with nothing underneath
     * any of them.
     *
     * «01 ОБЩАЯ ИНФОРМАЦИЯ» is not among them. It was never text — it is where
     * the thing sits, who leads it and how many work there — and it is read in
     * the column beside the document now.
     */
    public function test_the_document_is_one_text_with_its_parts_as_headings()
    {
        $this->seed(PositionProfileTemplateSeeder::class);

        $template = RegulationSection::forPosition(null);

        $this->assertCount(1, $template);
        $this->assertSame(Profile::TITLE, $template->first()->title);
        $this->assertFalse($template->first()->computed);

        // The name of the sheet first, written as the one placeholder this
        // document has: a job and a unit read the same template and each has to
        // read its own opening there.
        $this->assertSame([
            '{заголовок}',
            '01 ДОЛЖНОСТНЫЕ ОБЯЗАННОСТИ',
            '02 ОБРАЗОВАНИЕ И ОПЫТ РАБОТЫ',
            '03 ЗНАНИЕ ЯЗЫКОВ',
            '04 ЖЕСТКИЕ НАВЫКИ',
            '05 МЯГКИЕ НАВЫКИ',
            '06 КРИТЕРИИ ОЦЕНКИ ЭФФЕКТИВНОСТИ РАБОТЫ',
            '07 ОТВЕТСТВЕННОСТЬ ЗА ДОКУМЕНТЫ ФАЙЛЫ',
            '08 ОЗНАКОМЛЕНИЕ',
        ], $this->headings($template->first()->body));

        // Headings and nothing else. What belongs under «МЯГКИЕ НАВЫКИ» is
        // different for every job in the company, and a seeded paragraph of it
        // would be words the company never wrote on a sheet somebody signs.
        $this->assertSame('', trim(strip_tags(preg_replace('/<h2[^>]*>.*?<\/h2>/su', '', $template->first()->body))));
        $this->assertSame($template->first()->body, Document::sanitize($template->first()->body));

        // Somebody has rewritten it since the first day. A second run leaves
        // the text exactly as it stands: it belongs to them now.
        $template->first()->update(['body' => '<h2>{заголовок}</h2><p>Своими словами.</p>']);

        $this->seed(PositionProfileTemplateSeeder::class);

        $this->assertCount(1, RegulationSection::forPosition(null));
        $this->assertSame('<h2>{заголовок}</h2><p>Своими словами.</p>', RegulationSection::find($template->first()->id)->body);
    }

    /**
     * Each document's standard text is written although neither of them
     * belongs to anybody.
     *
     * The profile first, which is the order nothing runs in and the one that
     * catches it: a template section names no owner in either document, so a
     * seeder that asks only «is there a section with no department?» would read
     * the profile's own text as the regulation's and write nothing at all.
     */
    public function test_each_template_is_written_although_neither_belongs_to_anybody()
    {
        $this->seed(PositionProfileTemplateSeeder::class);
        $this->seed(RegulationTemplateSeeder::class);

        // One each: the company writes both documents as one file, and the
        // parts of each are headings in its body.
        $this->assertCount(1, RegulationSection::forPosition(null));
        $this->assertCount(1, RegulationSection::forDepartment(null));
    }

    /**
     * One position reads the whole of it — the same shape the department's page
     * sends its regulation in, so one component draws either document.
     */
    public function test_a_position_reads_the_document_as_one_text()
    {
        $this->seed(PositionProfileTemplateSeeder::class);
        $position = $this->position();

        $profile = $this->profile($this->reader(), $position);

        $this->assertCount(1, $profile['sections']);
        $this->assertSame('template', $profile['sections'][0]['origin']);
        $this->assertNull($profile['sections'][0]['template_section_id']);

        // The opening line is answered for this position; what the editor opens
        // on is the token, because the next position reads its own name there.
        $this->assertStringStartsWith('<h2>ПРОФИЛЬ ДОЛЖНОСТИ Графический дизайнер</h2>', $profile['sections'][0]['body']);
        $this->assertStringStartsWith('<h2>{заголовок}</h2>', $profile['sections'][0]['body_written']);

        // Every key the page is built against, and nothing else. No heading
        // travels beside the document: the sheet names itself in its own first
        // line, and what is drawn from the directory travels as `general`, for
        // the column at the side of the page rather than for the document.
        $this->assertSame(
            ['id', 'origin', 'template_section_id', 'number', 'title', 'order', 'body', 'body_written', 'computed'],
            array_keys($profile['sections'][0]),
        );
        $this->assertSame(['can_edit', 'general', 'sections'], array_keys($profile));
    }

    /**
     * What «01 ОБЩАЯ ИНФОРМАЦИЯ» used to open the sheet with is answered from
     * the directory: both steps of the structure and the name of the job. As
     * the fields themselves, and beside the document rather than inside it —
     * the column at the side of the page reads them now, where the page was
     * already saying most of them.
     */
    public function test_what_the_directory_answers_is_sent_beside_the_document()
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

        // And none of it is a section of the document any more: what is left
        // there is the text, which opens with the name of the sheet.
        $this->assertCount(1, $profile['sections']);
        $this->assertFalse($profile['sections'][0]['computed']);
        $this->assertSame([], array_filter(array_column($profile['sections'], 'computed')));

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
     * A position's own wording is read in the place of the standard text, for
     * that position and for nobody else.
     */
    public function test_a_position_s_own_wording_is_read_there_and_nowhere_else()
    {
        $this->seed(PositionProfileTemplateSeeder::class);
        $position = $this->position();
        $other = Position::create(['department_id' => $position->department_id, 'name' => 'Переводчик']);
        $standard = RegulationSection::forPosition(null)->first();

        $this->actingAs($this->keeper())
            ->post("/positions/{$position->id}/profile/sections", [
                'template_section_id' => $standard->id,
                'body' => '<h2>{заголовок}</h2><h2>01 ЗНАНИЕ ЯЗЫКОВ</h2><p>Английский — свободно.</p>',
            ])
            ->assertRedirect();

        $own = $this->profile($this->reader(), $position)['sections'][0];

        $this->assertSame('override', $own['origin']);
        $this->assertSame($standard->id, $own['template_section_id']);
        $this->assertSame(['ПРОФИЛЬ ДОЛЖНОСТИ Графический дизайнер', '01 ЗНАНИЕ ЯЗЫКОВ'], $this->headings($own['body']));
        // The token is what the editor opens on, answered only where it is read.
        $this->assertStringStartsWith('<h2>{заголовок}</h2>', $own['body_written']);

        $elsewhere = $this->profile($this->reader(), $other)['sections'][0];

        $this->assertSame('template', $elsewhere['origin']);
        $this->assertSame('ПРОФИЛЬ ДОЛЖНОСТИ Переводчик', $this->headings($elsewhere['body'])[0]);

        // Another position rewrites the very same standard text, and both
        // wordings stand: the row of an override names no department, and two
        // of them would collide on the pair the departments are kept unique by
        // if a null counted as a value there.
        $this->actingAs($this->keeper())
            ->post("/positions/{$other->id}/profile/sections", [
                'template_section_id' => $standard->id,
                'body' => '<p>Русский и таджикский.</p>',
            ])
            ->assertRedirect();

        $this->assertSame('<p>Русский и таджикский.</p>', $this->profile($this->reader(), $other)['sections'][0]['body']);
        $this->assertStringContainsString('Английский — свободно.', $this->profile($this->reader(), $position)['sections'][0]['body']);

        // And twice for one position is one job written down twice.
        $this->actingAs($this->keeper())
            ->post("/positions/{$other->id}/profile/sections", ['template_section_id' => $standard->id])
            ->assertSessionHasErrors('template_section_id');

        // Dropped, and the standard text is read again. The page offers no way
        // to do it — the tab is the document and the pencil — but the address
        // is still there and still does what it did.
        $wording = RegulationSection::where('position_id', $position->id)->firstOrFail();

        $this->actingAs($this->keeper())->delete("/positions/{$position->id}/profile/sections/{$wording->id}")->assertRedirect();

        $this->assertCount(1, RegulationSection::forPosition($position));
        $this->assertSame('template', $this->profile($this->reader(), $position)['sections'][0]['origin']);
    }

    /**
     * One text, and that one text is the whole of it: nothing is added to the
     * document and nothing is taken out of it.
     */
    public function test_a_position_adds_no_section_and_takes_none_out()
    {
        $this->seed(PositionProfileTemplateSeeder::class);
        $position = $this->position();
        $keeper = $this->keeper();

        $this->actingAs($keeper)
            ->post("/positions/{$position->id}/profile/sections", ['title' => 'СВОЙ РАЗДЕЛ', 'body' => '<p>Текст.</p>'])
            ->assertSessionHasErrors(['template_section_id' => 'Профиль должности — один текст: разделы в него не добавляются.']);

        $this->assertCount(1, RegulationSection::forPosition($position));

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
            ->assertSessionHasErrors(['section' => 'Профиль должности — один текст: его нельзя удалить, можно только вернуть стандартный.']);
        $this->assertNotNull($left->fresh());
    }

    /**
     * A section drawn from the directory is not a section anybody writes in:
     * a body typed under that heading would be a second answer to the same
     * question, and the week somebody is moved the two would disagree.
     *
     * No document has such a section any more — «01 ОБЩАЯ ИНФОРМАЦИЯ» left the
     * profile for the column beside it — so this writes one by hand. The rule
     * is kept because it is still the right rule the day a drawn section comes
     * back, and a rule nobody can reach is worth knowing still works.
     */
    public function test_a_section_read_from_the_directory_is_never_written_in()
    {
        $this->seed(PositionProfileTemplateSeeder::class);
        $position = $this->position();
        $keeper = $this->keeper();
        $general = RegulationSection::create([
            'document' => Document::PROFILE,
            'title' => 'ОБЩАЯ ИНФОРМАЦИЯ',
            'computed' => true,
            'order' => 50,
        ]);
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

        // A document of one section has one order, and naming it is
        // accepted and changes nothing. The page offers no way to arrange
        // anything any more; the address is still there and still answers.
        $ids = RegulationSection::forPosition($position)->pluck('id')->all();

        $this->actingAs($keeper)->put("/positions/{$position->id}/profile/order", ['ids' => $ids])->assertRedirect();

        $this->assertSame($ids, RegulationSection::forPosition($position)->pluck('id')->all());
        $this->assertSame($ids, RegulationSection::forPosition($other)->pluck('id')->all());

        // The whole document or nothing: a list that names something the
        // document does not read is not an order of it.
        $this->actingAs($keeper)
            ->put("/positions/{$position->id}/profile/order", ['ids' => [$ids[0], $ids[0] + 1000]])
            ->assertSessionHasErrors(['ids' => 'Нужно перечислить все разделы профиля должности, каждый по одному разу.']);
    }

    /**
     * The sheet is named in the first line of its own text, and the one
     * template names both kinds of owner through a token.
     *
     * There is no field and no address for a heading any more: «ПРОФИЛЬ
     * ДОЛЖНОСТИ ГРАФИЧЕСКОГО ДИЗАЙНЕРА» wants the genitive, nothing here
     * declines a name, and whoever wants the ending right writes the word they
     * want into the document.
     */
    public function test_the_sheet_is_named_in_the_first_line_of_its_own_text()
    {
        $this->seed(PositionProfileTemplateSeeder::class);
        $position = $this->position();
        $keeper = $this->keeper();

        $profile = $this->profile($this->reader(), $position);

        $this->assertArrayNotHasKey('title', $profile);
        $this->assertSame(Profile::TITLE.' '.$position->name, $this->headings($profile['sections'][0]['body'])[0]);

        // The position that wants the ending right writes it, in the text.
        $this->actingAs($keeper)
            ->post("/positions/{$position->id}/profile/sections", [
                'template_section_id' => $profile['sections'][0]['id'],
                'body' => '<h2>ПРОФИЛЬ ДОЛЖНОСТИ ГРАФИЧЕСКОГО ДИЗАЙНЕРА</h2>',
            ])
            ->assertRedirect();

        $this->assertSame(
            'ПРОФИЛЬ ДОЛЖНОСТИ ГРАФИЧЕСКОГО ДИЗАЙНЕРА',
            $this->headings($this->profile($this->reader(), $position)['sections'][0]['body'])[0],
        );

        // There is no column behind one either, on either kind of owner.
        $this->assertFalse(Schema::hasColumn('positions', 'profile_title'));
        $this->assertFalse(Schema::hasColumn('departments', 'profile_title'));
        $this->actingAs($keeper)
            ->put("/positions/{$position->id}/profile/title", ['title' => 'ПРОФИЛЬ ДОЛЖНОСТИ ГРАФИЧЕСКОГО ДИЗАЙНЕРА'])
            ->assertNotFound();
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
        $this->assertCount(1, RegulationSection::forPosition(null));
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

        $this->assertSame(1, RegulationOrder::where('position_id', $position->id)->count());

        $position->delete();

        $this->assertNull(RegulationSection::find($own->id));
        $this->assertSame(0, RegulationOrder::where('position_id', $position->id)->count());
        // And the company's own text is exactly where it was.
        $this->assertCount(1, RegulationSection::forPosition(null));
    }

    /**
     * A position's profile travels with the position's page and with nothing
     * else. The department's page carries «СФД» of its own now — the same one
     * text written about the unit — and never a list of the jobs under it,
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
        // What is there is the department's own, opening as a unit's sheet
        // rather than as a job's — one template, read through the token — and
        // what travels beside it answers about the unit: there is no
        // «ДОЛЖНОСТЬ» in it to read.
        $this->assertSame(
            Profile::UNIT_TITLE.' '.$position->department->name,
            $this->headings($props['profile']['sections'][0]['body'])[0],
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

    /**
     * A position keeps a «Положение о работе» of its own, read and written on
     * its own page.
     *
     * It used to read the department's, read-only, under a line saying where to
     * go and change it. The company asked for a job to keep one — «у должности
     * свои Положение и СФД» — so the same template serves both kinds of owner,
     * and the one line that cannot serve both is answered by the placeholder
     * that stands for the whole opening of the sheet.
     */
    public function test_a_position_keeps_a_regulation_of_its_own()
    {
        $this->seed(RegulationTemplateSeeder::class);

        $department = Department::create(['name' => 'Отдел аналитики', 'abbreviation' => 'ОА']);
        $position = Position::create(['department_id' => $department->id, 'name' => 'Аналитик']);

        $regulation = $this->props($this->reader(), $position)['regulation'];

        // The same document a department reads, from the same template, opening
        // with this position's own name rather than the unit's: the job and the
        // unit it is filed in, because two departments may each have an
        // «Аналитик».
        $this->assertArrayNotHasKey('title', $regulation);
        $this->assertCount(1, $regulation['sections']);
        $this->assertSame('template', $regulation['sections'][0]['origin']);
        $this->assertStringStartsWith(
            '<h2>ПОЛОЖЕНИЕ О РАБОТЕ Аналитик (ОА)</h2>',
            $regulation['sections'][0]['body'],
        );
        $this->assertStringStartsWith('<h2>{заголовок}</h2>', $regulation['sections'][0]['body_written']);

        // The company's text is written about a подразделение, and a job filed
        // in a unit reads it about that unit until somebody writes the job its
        // own. That wording is the company's to change.
        $this->assertStringContainsString('Отдел аналитики является', $regulation['sections'][0]['body']);

        foreach (array_keys(RegulationTemplateSeeder::SECTIONS) as $place => $part) {
            $this->assertStringContainsString(
                '<h2>'.str_pad((string) ($place + 1), 2, '0', STR_PAD_LEFT).' '.$part.'</h2>',
                $regulation['sections'][0]['body'],
            );
        }

        // And it is written here: whoever keeps the list of job titles writes
        // it, and the page says so.
        $this->assertFalse($regulation['can_edit']);
        $this->assertTrue($this->props($this->keeper(), $position)['regulation']['can_edit']);

        $this->actingAs($this->keeper())
            ->post("/positions/{$position->id}/regulation/sections", [
                'template_section_id' => $regulation['sections'][0]['id'],
                'body' => '<h2>ПОЛОЖЕНИЕ О РАБОТЕ АНАЛИТИКА</h2><p>Своими словами.</p>',
            ])
            ->assertRedirect();

        $own = $this->props($this->reader(), $position)['regulation']['sections'][0];

        $this->assertSame('override', $own['origin']);
        $this->assertStringStartsWith('<h2>ПОЛОЖЕНИЕ О РАБОТЕ АНАЛИТИКА</h2>', $own['body']);

        // Nobody else's moved: the department it is filed in still reads the
        // company's text, and so does the job next door.
        $elsewhere = Position::create(['department_id' => $department->id, 'name' => 'Переводчик']);

        $this->assertSame('template', RegulationSection::forDepartment($department)->first()->origin());
        $this->assertStringStartsWith(
            '<h2>ПОЛОЖЕНИЕ О РАБОТЕ Переводчик (ОА)</h2>',
            $this->props($this->reader(), $elsewhere)['regulation']['sections'][0]['body'],
        );

        // A position filed nowhere keeps one all the same: the document no
        // longer depends on having a unit, although the sentences in the
        // standard text are about one.
        $unfiled = Position::create(['department_id' => null, 'name' => 'Уборщица']);
        $loose = $this->props($this->reader(), $unfiled)['regulation'];

        $this->assertCount(1, $loose['sections']);
        $this->assertStringStartsWith('<h2>ПОЛОЖЕНИЕ О РАБОТЕ Уборщица</h2>', $loose['sections'][0]['body']);
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
     * The headings a body is divided by, which is how the parts of the document
     * are read now that it is one text: nothing stores them and nothing counts
     * them, they are lines of the text like any other.
     *
     * @return list<string>
     */
    private function headings(?string $body): array
    {
        preg_match_all('/<h2>(.*?)<\/h2>/su', (string) $body, $found);

        return $found[1];
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
