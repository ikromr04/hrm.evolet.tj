<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\RegulationOrder;
use App\Models\RegulationSection;
use App\Models\User;
use App\Support\Directories;
use App\Support\Document;
use App\Support\Regulation;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RegulationTemplateSeeder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * «Положение о работе» on a department's page: the four sections of the
 * standard text, written in an editor and so written in HTML, what one
 * department keeps of its own wording of them, the heading it prints them
 * under, and who may write any of it.
 *
 * The standard text is seeded, and one test is about exactly that. The rest
 * start from an empty document and write whatever template they need, because
 * what they are each about is one rule of the machinery rather than the
 * company's wording of a clause.
 */
class DepartmentRegulationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
    }

    /**
     * A section is a heading and a body, and nothing is left of the three kinds
     * of section or of the rows they used to be kept as.
     */
    public function test_a_section_is_a_heading_and_a_body()
    {
        $this->assertTrue(Schema::hasColumn('regulation_sections', 'body'));
        $this->assertFalse(Schema::hasColumn('regulation_sections', 'kind'));
        $this->assertFalse(Schema::hasTable('regulation_items'));

        // And the number is not kept anywhere: one row of the template is read
        // at a different place in every department's document, so the only
        // thing a number can be is that place.
        $this->assertFalse(Schema::hasColumn('regulation_sections', 'number'));
    }

    /**
     * The company's own document, transcribed once and theirs from then on: the
     * numbered clauses of «Положение о работе», written as the template, which
     * is what makes one text serve every department.
     */
    public function test_the_standard_text_is_written_once_and_is_then_the_company_s_own()
    {
        $this->seed(RegulationTemplateSeeder::class);

        $template = RegulationSection::forDepartment(null);

        // Only the parts of the file that are numbered clauses: the two-column
        // table of tasks is each department's to fill in and the signature sheet
        // carries no text.
        $this->assertSame(['ОБЩЕЕ ПОЛОЖЕНИЕ', 'ПРАВА', 'ОБЯЗАННОСТИ', 'ОТВЕТСТВЕННОСТЬ'], $template->pluck('title')->all());

        // A heading with nothing under it is not a section of a document, and a
        // count of rows would not have noticed — so every clause of every
        // section is read back here, word for word and in order.
        foreach ($template as $section) {
            $clauses = RegulationTemplateSeeder::SECTIONS[$section->title];

            $this->assertNotEmpty($clauses);
            $this->assertSame(
                '<ol>'.implode('', array_map(fn (string $clause) => "<li><p>{$clause}</p></li>", $clauses)).'</ol>',
                $section->body,
            );

            // Which is one numbered list to a section and one paragraph to a
            // clause — what the editor writes, and already in the shape the
            // sanitizer leaves a body in, so the first person to correct a
            // sentence is not handed a document that rewrites itself as they
            // save.
            $this->assertSame($section->body, Document::sanitize($section->body));
        }

        // And the document itself, as the company's file has it.
        $this->assertStringStartsWith(
            '<ol><li><p>{подразделение} является самостоятельным структурным подразделением Представительства «Evolet Healthcare»',
            $template->first()->body,
        );
        $this->assertSame([7, 6, 6, 2], $template->map(fn (RegulationSection $section) => substr_count($section->body, '<li><p>'))->all());

        // Every department reads it, with its own name where the shared text
        // leaves a placeholder — which is the whole reason the placeholders are
        // there and the whole reason this is one text and not twenty-six.
        $marketing = Department::create(['name' => 'Департамент маркетинга']);
        $design = Department::create(['name' => 'Отдел Дизайна', 'parent_id' => $marketing->id]);

        $sections = $this->regulation($this->colleague(), $design)['sections'];

        $this->assertSame(['template', 'template', 'template', 'template'], array_column($sections, 'origin'));
        $this->assertSame(['01', '02', '03', '04'], array_column($sections, 'number'));
        $this->assertStringContainsString('<li><p>Отдел Дизайна является самостоятельным', $sections[0]['body']);
        $this->assertStringContainsString('подразделения «Отдел Дизайна» определяется', $sections[0]['body']);
        $this->assertStringNotContainsString('{подразделение}', $sections[0]['body']);

        // And the same text as it was written, braces and all, which is what the
        // editor has to load.
        $this->assertStringContainsString('<li><p>{подразделение} является самостоятельным', $sections[0]['body_written']);

        // Somebody has corrected a heading and dropped a section since the first
        // day. A second run leaves both alone: the text belongs to them now, and
        // an update that put our wording back would undo their work.
        $template->first()->update(['title' => 'ОБЩИЕ ПОЛОЖЕНИЯ']);
        $template->last()->delete();

        $this->seed(RegulationTemplateSeeder::class);

        $this->assertSame(count(RegulationTemplateSeeder::SECTIONS) - 1, RegulationSection::whereNull('department_id')->count());
        $this->assertSame('ОБЩИЕ ПОЛОЖЕНИЯ', RegulationSection::find($template->first()->id)->title);
    }

    /** And until it is seeded, or where it has been dropped, nothing is read. */
    public function test_a_department_starts_with_an_empty_document()
    {
        $department = Department::create(['name' => 'Отдел Дизайна']);

        $this->assertSame(0, RegulationSection::count());
        $this->assertSame([], $this->regulation($this->colleague(), $department)['sections']);
    }

    /**
     * A department's own wording of a standard section, which is the whole of
     * what a department writes into the document.
     */
    public function test_a_department_s_own_wording_is_read_there_and_nowhere_else()
    {
        $template = $this->template();
        $general = $template->first();
        $marketing = Department::create(['name' => 'Департамент маркетинга', 'abbreviation' => 'ДМ']);
        $design = Department::create(['name' => 'Отдел Дизайна', 'abbreviation' => 'ОД', 'parent_id' => $marketing->id]);
        $design->users()->attach(User::factory()->create(['surname' => 'Азимова', 'name' => 'Нигина']), ['is_head' => true]);
        $analytics = Department::create(['name' => 'Отдел аналитики']);

        $this->actingAs($this->keeper())
            ->post("/departments/{$design->id}/regulation/sections", [
                'template_section_id' => $general->id,
                'body' => '<p>{подразделение} является самостоятельным структурным подразделением.</p>',
            ])
            ->assertRedirect();

        $regulation = $this->regulation($this->colleague(), $design);
        $section = $regulation['sections'][0];

        // The document is the same document: one section of it is this
        // department's now, in the place that section was already read in, and
        // it carries the standard heading it was copied from.
        $this->assertCount($template->count(), $regulation['sections']);
        $this->assertSame('override', $section['origin']);
        $this->assertSame($general->id, $section['template_section_id']);
        $this->assertSame('ОБЩЕЕ ПОЛОЖЕНИЕ', $section['title']);

        // The department's own name where the text has a placeholder, and the
        // text as it was typed beside it, for the editor.
        $this->assertSame('<p>Отдел Дизайна является самостоятельным структурным подразделением.</p>', $section['body']);
        $this->assertSame('<p>{подразделение} является самостоятельным структурным подразделением.</p>', $section['body_written']);
        $this->assertSame('Отдел Дизайна', $regulation['names']['nominative']);

        // Whoever edits the text is told what each placeholder reads as here.
        $this->assertSame('Отдел Дизайна', collect($regulation['placeholders'])->firstWhere('token', '{подразделение}')['value']);
        $this->assertSame('ОД', collect($regulation['placeholders'])->firstWhere('token', '{аббревиатура}')['value']);
        $this->assertSame('Азимова Нигина', collect($regulation['placeholders'])->firstWhere('token', '{руководитель}')['value']);
        $this->assertSame('Департамент маркетинга', collect($regulation['placeholders'])->firstWhere('token', '{департамент}')['value']);

        // The shared text itself is read with the braces showing: there is no
        // department to name in the template.
        $this->assertSame('{подразделение}', Regulation::values(null)['{подразделение}']);

        // And nobody else reads a word of it: in that place the other
        // department reads the company's text, as the company wrote it.
        $theirs = $this->regulation($this->colleague(), $analytics)['sections'][0];

        $this->assertSame('template', $theirs['origin']);
        $this->assertSame($general->body, $theirs['body_written']);
    }

    public function test_a_rewritten_section_replaces_exactly_one_section_for_exactly_one_department()
    {
        $template = $this->template();
        $design = Department::create(['name' => 'Отдел Дизайна']);
        $analytics = Department::create(['name' => 'Отдел аналитики']);
        $general = $template->first();
        $keeper = $this->keeper();

        // Rewriting starts from the standard text as it stands.
        $this->actingAs($keeper)
            ->post("/departments/{$design->id}/regulation/sections", ['template_section_id' => $general->id])
            ->assertRedirect();

        $own = RegulationSection::where('department_id', $design->id)->firstOrFail();

        $this->assertSame($general->title, $own->title);
        $this->assertSame($general->body, $own->body);

        $this->actingAs($keeper)
            ->put("/departments/{$design->id}/regulation/sections/{$own->id}", [
                'title' => 'ОБЩЕЕ ПОЛОЖЕНИЕ',
                'body' => '<p>{подразделение} работает по собственному положению.</p>',
            ])
            ->assertRedirect();

        $rewritten = $this->regulation($this->colleague(), $design);
        $standard = $this->regulation($this->colleague(), $analytics);

        // One section replaced, in its own place, and the document is still the
        // same document.
        $this->assertCount($template->count(), $rewritten['sections']);
        $this->assertSame('override', $rewritten['sections'][0]['origin']);
        $this->assertSame($general->id, $rewritten['sections'][0]['template_section_id']);
        $this->assertSame('<p>Отдел Дизайна работает по собственному положению.</p>', $rewritten['sections'][0]['body']);
        $this->assertSame(['template', 'template'], array_column(array_slice($rewritten['sections'], 1), 'origin'));

        // Nobody else's document moved, and neither did the template.
        $this->assertSame('template', $standard['sections'][0]['origin']);
        $this->assertSame($general->body, $standard['sections'][0]['body_written']);
        $this->assertSame($general->body, $general->fresh()->body);

        // Dropping its own wording puts the department back on the standard text.
        $this->actingAs($keeper)->delete("/departments/{$design->id}/regulation/sections/{$own->id}")->assertRedirect();

        $this->assertSame('template', $this->regulation($this->colleague(), $design)['sections'][0]['origin']);
        $this->assertSame($general->body, $this->regulation($this->colleague(), $design)['sections'][0]['body_written']);
    }

    /**
     * The document is the four sections of the standard text, and a department
     * neither adds a section to it nor takes one out: it rewrites a section and
     * puts the standard text back, and that is the whole of its half.
     *
     * Both of those are one route each, and each route still does the half that
     * stays. A create names the standard section it is rewriting or it is
     * refused; a delete drops this department's wording of a standard section
     * or it is refused.
     */
    public function test_a_department_adds_no_section_to_the_document_and_takes_none_out()
    {
        $template = $this->template();
        $department = Department::create(['name' => 'Отдел Дизайна']);
        $other = Department::create(['name' => 'Отдел аналитики']);
        $keeper = $this->keeper();

        // A section of this department's own, which is what the page used to
        // write: refused, and refused in so many words.
        $this->actingAs($keeper)
            ->post("/departments/{$department->id}/regulation/sections", [
                'title' => 'ВЗАИМОДЕЙСТВИЕ',
                'body' => '<p>Работает с типографией.</p>',
            ])
            ->assertSessionHasErrors(['template_section_id' => 'Положение состоит из четырёх стандартных разделов — новые разделы в него не добавляются.']);

        $this->assertSame(0, RegulationSection::where('department_id', $department->id)->count());
        $this->assertSame($template->count(), count($this->titles($department)));

        // Naming the standard section it rewrites is the same route, still
        // works, and still reaches this department and nobody else.
        $this->actingAs($keeper)
            ->post("/departments/{$department->id}/regulation/sections", [
                'template_section_id' => $template->first()->id,
                'body' => '<p>Своими словами.</p>',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $own = RegulationSection::where('department_id', $department->id)->firstOrFail();

        $this->assertSame('override', $this->regulation($this->colleague(), $department)['sections'][0]['origin']);
        $this->assertSame('template', $this->regulation($this->colleague(), $other)['sections'][0]['origin']);

        // A standard section is not one department's to take out of the
        // document: at a department's address there is nothing of the
        // template's to delete.
        $this->actingAs($keeper)
            ->delete("/departments/{$department->id}/regulation/sections/{$template->last()->id}")
            ->assertNotFound();

        $this->assertSame($template->count(), count($this->titles($department)));

        // The deletion a department does have is its own wording, which is
        // «Вернуть стандартный текст» — the way back to the shared wording, and
        // the document is whole again afterwards.
        $this->actingAs($keeper)->delete("/departments/{$department->id}/regulation/sections/{$own->id}")->assertRedirect();

        $this->assertNull(RegulationSection::find($own->id));
        $this->assertSame('template', $this->regulation($this->colleague(), $department)['sections'][0]['origin']);
        $this->assertSame($template->first()->body, $this->regulation($this->colleague(), $department)['sections'][0]['body_written']);

        // A row of a department's that is nobody's wording of a standard
        // section can still appear: the company drops a section while this
        // department had rewritten it, and the department keeps the sentences
        // it wrote. That text is theirs, so the button meaning «вернуть
        // стандартный текст» is not the one that throws it away.
        $this->actingAs($keeper)
            ->post("/departments/{$department->id}/regulation/sections", [
                'template_section_id' => $template->first()->id,
                'body' => '<p>Своими словами.</p>',
            ])
            ->assertRedirect();

        $kept = RegulationSection::where('department_id', $department->id)->firstOrFail();

        $this->actingAs($keeper)->delete("/departments/regulation/sections/{$template->first()->id}")->assertRedirect();

        $this->assertSame('own', $kept->fresh()->origin());

        $this->actingAs($keeper)
            ->delete("/departments/{$department->id}/regulation/sections/{$kept->id}")
            ->assertSessionHasErrors(['section' => 'Положение состоит из четырёх стандартных разделов: раздел нельзя удалить, можно только вернуть его стандартный текст.']);

        $this->assertNotNull(RegulationSection::find($kept->id));
        $this->assertSame('<p>Своими словами.</p>', $kept->fresh()->body);
    }

    /**
     * What a department reads is the four sections of the standard text,
     * numbered 01 to 04, in the order that department reads them in — however
     * much of it it has rearranged or rewritten.
     */
    public function test_a_department_reads_exactly_the_four_standard_sections()
    {
        $this->seed(RegulationTemplateSeeder::class);

        $department = Department::create(['name' => 'Отдел Дизайна']);
        $keeper = $this->keeper();

        $sections = $this->regulation($this->colleague(), $department)['sections'];
        $ids = array_column($sections, 'id');

        $this->assertCount(4, $sections);
        $this->assertSame(['01', '02', '03', '04'], array_column($sections, 'number'));
        $this->assertSame(['ОБЩЕЕ ПОЛОЖЕНИЕ', 'ПРАВА', 'ОБЯЗАННОСТИ', 'ОТВЕТСТВЕННОСТЬ'], array_column($sections, 'title'));

        // Read back to front, which is this department's arrangement and still
        // four sections numbered 01 to 04.
        $this->actingAs($keeper)
            ->put("/departments/{$department->id}/regulation/order", ['ids' => array_reverse($ids)])
            ->assertRedirect();

        $arranged = $this->regulation($this->colleague(), $department)['sections'];

        $this->assertCount(4, $arranged);
        $this->assertSame(['01', '02', '03', '04'], array_column($arranged, 'number'));
        $this->assertSame(['ОТВЕТСТВЕННОСТЬ', 'ОБЯЗАННОСТИ', 'ПРАВА', 'ОБЩЕЕ ПОЛОЖЕНИЕ'], array_column($arranged, 'title'));

        // And with one of them rewritten, which is read where that section was
        // already read: still four, still 01 to 04, still that order.
        $this->actingAs($keeper)
            ->post("/departments/{$department->id}/regulation/sections", ['template_section_id' => $ids[1], 'body' => '<p>Своими словами.</p>'])
            ->assertRedirect();

        $rewritten = $this->regulation($this->colleague(), $department)['sections'];

        $this->assertCount(4, $rewritten);
        $this->assertSame(['01', '02', '03', '04'], array_column($rewritten, 'number'));
        $this->assertSame(['ОТВЕТСТВЕННОСТЬ', 'ОБЯЗАННОСТИ', 'ПРАВА', 'ОБЩЕЕ ПОЛОЖЕНИЕ'], array_column($rewritten, 'title'));
        $this->assertSame('override', $rewritten[2]['origin']);
        $this->assertSame('<p>Своими словами.</p>', $rewritten[2]['body']);

        // Four in the company's own order for everybody who has arranged
        // nothing.
        $other = Department::create(['name' => 'Отдел аналитики']);

        $this->assertSame(['ОБЩЕЕ ПОЛОЖЕНИЕ', 'ПРАВА', 'ОБЯЗАННОСТИ', 'ОТВЕТСТВЕННОСТЬ'], $this->titles($other));
        $this->assertSame(['01', '02', '03', '04'], $this->numbers($other));
    }

    /**
     * The heading of the printed sheet belongs to the department and is typed
     * by hand: «ПОЛОЖЕНИЕ О РАБОТЕ ДЕПАРТАМЕНТА АНАЛИТИКИ И СТАТИСТИКИ» wants
     * the name in the genitive, and nothing here declines a Russian name.
     *
     * What is read while nobody has typed anything is those words and the name
     * upper-cased as it stands, which is one ending away from right and
     * visibly so. That is the point of it: the ending is corrected in the field
     * once, by somebody who knows, instead of guessed at by a rule that would
     * get the next department's name wrong.
     */
    public function test_the_heading_of_the_sheet_is_the_department_s_own_and_is_typed_by_hand()
    {
        $analytics = Department::create(['name' => 'Департамент аналитики и статистики']);
        $design = Department::create(['name' => 'Отдел Дизайна']);
        $keeper = $this->keeper();

        $this->assertNull($analytics->regulation_title);
        $this->assertSame('ПОЛОЖЕНИЕ О РАБОТЕ ДЕПАРТАМЕНТ АНАЛИТИКИ И СТАТИСТИКИ', $this->regulation($this->colleague(), $analytics)['title']);

        // Typed once, and theirs from then on — ending and all.
        $this->actingAs($keeper)
            ->put("/departments/{$analytics->id}/regulation/title", ['title' => '  ПОЛОЖЕНИЕ О РАБОТЕ ДЕПАРТАМЕНТА АНАЛИТИКИ И СТАТИСТИКИ  '])
            ->assertRedirect();

        $this->assertSame('ПОЛОЖЕНИЕ О РАБОТЕ ДЕПАРТАМЕНТА АНАЛИТИКИ И СТАТИСТИКИ', $analytics->fresh()->regulation_title);
        $this->assertSame('ПОЛОЖЕНИЕ О РАБОТЕ ДЕПАРТАМЕНТА АНАЛИТИКИ И СТАТИСТИКИ', $this->regulation($this->colleague(), $analytics)['title']);

        // One department's heading reaches that department's sheet and no
        // other.
        $this->assertSame('ПОЛОЖЕНИЕ О РАБОТЕ ОТДЕЛ ДИЗАЙНА', $this->regulation($this->colleague(), $design)['title']);

        // The placeholders are answered here as in any other text of the
        // document: it would be strange if the heading were the one place
        // somebody was told they do not work.
        $this->actingAs($keeper)
            ->put("/departments/{$design->id}/regulation/title", ['title' => 'ПОЛОЖЕНИЕ О РАБОТЕ {подразделение}'])
            ->assertRedirect();

        $this->assertSame('ПОЛОЖЕНИЕ О РАБОТЕ Отдел Дизайна', $this->regulation($this->colleague(), $design)['title']);

        // Cleared, the column is cleared and the default comes back: a heading
        // is never stored as nothing, because a sheet is never printed without
        // one.
        $this->actingAs($keeper)->put("/departments/{$analytics->id}/regulation/title", ['title' => '   '])->assertRedirect();

        $this->assertNull($analytics->fresh()->regulation_title);
        $this->assertSame('ПОЛОЖЕНИЕ О РАБОТЕ ДЕПАРТАМЕНТ АНАЛИТИКИ И СТАТИСТИКИ', $this->regulation($this->colleague(), $analytics)['title']);

        // And the name the rest of the wording is written around is still sent
        // beside it.
        $this->assertSame('Департамент аналитики и статистики', $this->regulation($this->colleague(), $analytics)['names']['nominative']);

        // A heading longer than the line it is printed on is refused, and what
        // was there stays.
        $this->actingAs($keeper)
            ->put("/departments/{$design->id}/regulation/title", ['title' => str_repeat('а', Document::TITLE_LENGTH + 1)])
            ->assertSessionHasErrors(['title' => 'Заголовок положения не должен быть длиннее '.Document::TITLE_LENGTH.' символов.']);

        $this->assertSame('ПОЛОЖЕНИЕ О РАБОТЕ {подразделение}', $design->fresh()->regulation_title);
    }

    /**
     * Nobody types the number and nothing stores it: it is the place the section
     * is read in this department's document, counted over the whole of it.
     *
     * Which is the only thing it can be. One row of the template is read in
     * every department's document at once, and a department may read those rows
     * in an order of its own, so the number belongs to the place and not to the
     * row: kept on the row, two departments would print the same section as
     * «02» and as «03» and only one of them would be right.
     *
     * So: a department reading the template as it stands, the same document
     * arranged its own way, one with a standard section rewritten, and the same
     * document after the company added a section to the standard text and
     * dropped another.
     */
    public function test_the_document_is_numbered_by_where_each_section_is_read()
    {
        $template = $this->template();
        $department = Department::create(['name' => 'Отдел Дизайна']);
        $other = Department::create(['name' => 'Отдел аналитики']);
        $keeper = $this->keeper();

        // Nothing arranged and nothing rewritten: the template's three, as the
        // template reads.
        $this->assertSame(['01', '02', '03'], $this->numbers($department));
        $this->assertSame(['ОБЩЕЕ ПОЛОЖЕНИЕ', 'ЗАДАЧИ И ЦЕННЫЙ КОНЕЧНЫЙ ПРОДУКТ', 'ПРАВА'], $this->titles($department));

        // The last section read first, where it is «01» and pushes the rest
        // down — for this department, and for nobody else.
        $ids = array_column($this->regulation($this->colleague(), $department)['sections'], 'id');
        $rights = array_pop($ids);

        $this->actingAs($keeper)
            ->put("/departments/{$department->id}/regulation/order", ['ids' => [$rights, ...$ids]])
            ->assertRedirect();

        $this->assertSame(['01', '02', '03'], $this->numbers($department));
        $this->assertSame(['ПРАВА', 'ОБЩЕЕ ПОЛОЖЕНИЕ', 'ЗАДАЧИ И ЦЕННЫЙ КОНЕЧНЫЙ ПРОДУКТ'], $this->titles($department));
        $this->assertSame(['01', '02', '03'], $this->numbers($other));
        $this->assertSame('ОБЩЕЕ ПОЛОЖЕНИЕ', $this->titles($other)[0]);

        // A rewritten standard section is read exactly where that section was,
        // so nothing shifts: «02» stays «02» and stays that heading.
        $this->actingAs($keeper)
            ->post("/departments/{$department->id}/regulation/sections", [
                'template_section_id' => $template->first()->id,
                'body' => '<p>Своими словами.</p>',
            ])
            ->assertRedirect();

        $rewritten = $this->regulation($this->colleague(), $department)['sections'];

        $this->assertSame(['01', '02', '03'], array_column($rewritten, 'number'));
        $this->assertSame(['ПРАВА', 'ОБЩЕЕ ПОЛОЖЕНИЕ', 'ЗАДАЧИ И ЦЕННЫЙ КОНЕЧНЫЙ ПРОДУКТ'], array_column($rewritten, 'title'));
        $this->assertSame('override', $rewritten[1]['origin']);
        $this->assertSame('<p>Своими словами.</p>', $rewritten[1]['body']);

        // A section the company adds to the standard text lands at the end of a
        // document somebody has already arranged, and is numbered after
        // everything in it.
        $this->actingAs($keeper)->post('/departments/regulation/sections', ['title' => 'ОТВЕТСТВЕННОСТЬ'])->assertRedirect();

        $this->assertSame(['01', '02', '03', '04'], $this->numbers($department));
        $this->assertSame('ОТВЕТСТВЕННОСТЬ', $this->titles($department)[3]);

        // And the one in the middle is dropped: the document closes up after it
        // rather than printing 01, 02, 04.
        $this->actingAs($keeper)->delete("/departments/regulation/sections/{$template->get(1)->id}")->assertRedirect();

        $this->assertSame(['01', '02', '03'], $this->numbers($department));
        $this->assertSame(['ПРАВА', 'ОБЩЕЕ ПОЛОЖЕНИЕ', 'ОТВЕТСТВЕННОСТЬ'], $this->titles($department));
    }

    /**
     * A department arranges the whole of its document — the standard sections
     * are the whole of it, and it reads them in whatever order suits it — and
     * the arrangement is its own and nobody else's.
     *
     * Position and wording are separate things, which is the whole of why this
     * is possible: dragging a standard section says where this department reads
     * it and nothing about what it says, so the text stays the company's and
     * stays shared.
     */
    public function test_a_department_arranges_its_whole_document_and_nobody_else_s_moves()
    {
        $template = $this->template();
        $design = Department::create(['name' => 'Отдел Дизайна']);
        $analytics = Department::create(['name' => 'Отдел аналитики']);
        $keeper = $this->keeper();

        // Nobody has arranged anything, so everybody reads the template's order
        // and nothing is written down about it.
        $this->assertSame(['ОБЩЕЕ ПОЛОЖЕНИЕ', 'ЗАДАЧИ И ЦЕННЫЙ КОНЕЧНЫЙ ПРОДУКТ', 'ПРАВА'], $this->titles($design));
        $this->assertSame($this->titles($design), $this->titles($analytics));
        $this->assertSame(0, RegulationOrder::count());

        // «ПРАВА» to the top, which is a standard section.
        $ids = array_column($this->regulation($this->colleague(), $design)['sections'], 'id');
        $rights = array_pop($ids);

        $this->actingAs($keeper)
            ->put("/departments/{$design->id}/regulation/order", ['ids' => [$rights, ...$ids]])
            ->assertRedirect();

        $arranged = $this->regulation($this->colleague(), $design)['sections'];

        $this->assertSame(['ПРАВА', 'ОБЩЕЕ ПОЛОЖЕНИЕ', 'ЗАДАЧИ И ЦЕННЫЙ КОНЕЧНЫЙ ПРОДУКТ'], array_column($arranged, 'title'));
        $this->assertSame(['01', '02', '03'], array_column($arranged, 'number'));

        // Moved, not rewritten: it is the same row of the company's text, read
        // as it is written, and the template itself did not move.
        $this->assertSame($rights, $arranged[0]['id']);
        $this->assertSame('template', $arranged[0]['origin']);
        $this->assertSame($template->last()->body, $arranged[0]['body_written']);
        $this->assertSame(['ОБЩЕЕ ПОЛОЖЕНИЕ', 'ЗАДАЧИ И ЦЕННЫЙ КОНЕЧНЫЙ ПРОДУКТ', 'ПРАВА'], RegulationSection::forDepartment(null)->pluck('title')->all());

        // And the other department reads exactly what it read before: its own
        // «01» is still «ОБЩЕЕ ПОЛОЖЕНИЕ».
        $this->assertSame(['ОБЩЕЕ ПОЛОЖЕНИЕ', 'ЗАДАЧИ И ЦЕННЫЙ КОНЕЧНЫЙ ПРОДУКТ', 'ПРАВА'], $this->titles($analytics));

        // Rewriting a standard section does not move it: the wording is this
        // department's now, the place is the one it was already read in.
        $this->actingAs($keeper)
            ->post("/departments/{$design->id}/regulation/sections", [
                'template_section_id' => $rights,
                'body' => '<p>Своими словами.</p>',
            ])
            ->assertRedirect();

        $rewritten = $this->regulation($this->colleague(), $design)['sections'];

        $this->assertSame(['ПРАВА', 'ОБЩЕЕ ПОЛОЖЕНИЕ', 'ЗАДАЧИ И ЦЕННЫЙ КОНЕЧНЫЙ ПРОДУКТ'], array_column($rewritten, 'title'));
        $this->assertSame('override', $rewritten[0]['origin']);
        $this->assertSame('<p>Своими словами.</p>', $rewritten[0]['body']);

        // A standard section written after a department arranged its document
        // lands at the end of it — somewhere said out loud, where it can be
        // found and moved, rather than somewhere that depends on a row id.
        $this->actingAs($keeper)->post('/departments/regulation/sections', ['title' => 'ОТВЕТСТВЕННОСТЬ'])->assertRedirect();

        $this->assertSame(['ПРАВА', 'ОБЩЕЕ ПОЛОЖЕНИЕ', 'ЗАДАЧИ И ЦЕННЫЙ КОНЕЧНЫЙ ПРОДУКТ', 'ОТВЕТСТВЕННОСТЬ'], $this->titles($design));
        $this->assertSame(['01', '02', '03', '04'], $this->numbers($design));

        // For a department that has arranged nothing it is simply the last of
        // the template, which is where the template has it.
        $this->assertSame(['ОБЩЕЕ ПОЛОЖЕНИЕ', 'ЗАДАЧИ И ЦЕННЫЙ КОНЕЧНЫЙ ПРОДУКТ', 'ПРАВА', 'ОТВЕТСТВЕННОСТЬ'], $this->titles($analytics));

        // A standard section dropped from the template leaves no gap in an
        // arrangement and no row pointing at nothing.
        $this->actingAs($keeper)->delete("/departments/regulation/sections/{$template->get(1)->id}")->assertRedirect();

        $this->assertSame(['ПРАВА', 'ОБЩЕЕ ПОЛОЖЕНИЕ', 'ОТВЕТСТВЕННОСТЬ'], $this->titles($design));
        $this->assertSame(['01', '02', '03'], $this->numbers($design));
        $this->assertSame(0, RegulationOrder::whereNotIn('regulation_section_id', RegulationSection::pluck('id'))->count());

        // And a department that had rewritten the section being dropped keeps
        // its wording — and keeps its place, rather than walking to the end of
        // the document because somebody tidied the template.
        $this->actingAs($keeper)->delete("/departments/regulation/sections/{$rights}")->assertRedirect();

        $kept = $this->regulation($this->colleague(), $design)['sections'];

        $this->assertSame(['ПРАВА', 'ОБЩЕЕ ПОЛОЖЕНИЕ', 'ОТВЕТСТВЕННОСТЬ'], array_column($kept, 'title'));
        $this->assertSame('01', $kept[0]['number']);
        $this->assertSame('own', $kept[0]['origin']);
        $this->assertSame('<p>Своими словами.</p>', $kept[0]['body']);
        $this->assertSame(['ОБЩЕЕ ПОЛОЖЕНИЕ', 'ОТВЕТСТВЕННОСТЬ'], $this->titles($analytics));

        // Its own document and no further: an id out of another department's,
        // or one that is in no document at all, is not an order of this one.
        $theirs = array_column($this->regulation($keeper, $analytics)['sections'], 'id');

        $this->actingAs($keeper)
            ->put("/departments/{$analytics->id}/regulation/order", ['ids' => [...$theirs, $kept[0]['id']]])
            ->assertSessionHasErrors('ids');

        $this->actingAs($keeper)
            ->put("/departments/{$analytics->id}/regulation/order", ['ids' => [...array_slice($theirs, 1), 999999]])
            ->assertSessionHasErrors('ids');

        $this->assertSame(['ОБЩЕЕ ПОЛОЖЕНИЕ', 'ОТВЕТСТВЕННОСТЬ'], $this->titles($analytics));
    }

    /** In the template there is one order and it is the company's. */
    public function test_the_template_s_own_order_is_the_one_every_department_starts_from()
    {
        $template = $this->template();
        $department = Department::create(['name' => 'Отдел Дизайна']);
        $keeper = $this->keeper();

        $order = $template->pluck('id')->all();
        [$order[0], $order[1]] = [$order[1], $order[0]];

        $this->actingAs($keeper)->put('/departments/regulation/order', ['ids' => $order])->assertRedirect();

        $this->assertSame($order, RegulationSection::forDepartment(null)->pluck('id')->all());
        $this->assertSame('ЗАДАЧИ И ЦЕННЫЙ КОНЕЧНЫЙ ПРОДУКТ', $this->titles($department)[0]);
        $this->assertSame(0, RegulationOrder::count());

        // A department that has arranged its own document has already answered
        // this question for itself, so the company rearranging the standard text
        // does not undo that arrangement.
        $this->actingAs($keeper)
            ->put("/departments/{$department->id}/regulation/order", ['ids' => array_reverse($order)])
            ->assertRedirect();

        $this->actingAs($keeper)->put('/departments/regulation/order', ['ids' => $template->pluck('id')->all()])->assertRedirect();

        $this->assertSame(array_reverse($order), array_column($this->regulation($this->colleague(), $department)['sections'], 'id'));
    }

    /**
     * The body comes from an editor in a browser, which means it comes as HTML,
     * which means it is HTML somebody can also write by hand. So it is cleaned
     * to an allowlist where it is written — and the allowlist is the toolbar,
     * because a button the server would undo is worse than no button.
     */
    public function test_the_body_keeps_everything_the_toolbar_writes()
    {
        $body = $this->written(
            '<h2>Общее</h2><h3>Подробнее</h3>'
            .'<p>Даёт <strong>обязательные</strong> указания, запрашивает <em>документы</em> и <u>сведения</u>, <s>не все</s>.</p>'
            .'<blockquote>Как сказано в уставе.</blockquote>'
            .'<hr>'
            .'<ol start="7"><li>Седьмое</li><li>Восьмое</li></ol>'
            .'<ul><li>Ещё</li></ul>'
            .'<p>Смотри <a href="https://example.com">порядок</a> и напиши на <a href="mailto:hr@evolet.tj">адрес</a>.</p>'
            .'<p><mark>выделено</mark> и <span>обычное</span></p>'
            .'<table><thead><tr><th colspan="2" rowspan="1">Задача</th></tr></thead>'
            .'<tbody><tr><td>Макет</td><td>Готовый макет</td></tr></tbody></table>'
        );

        $this->assertStringContainsString('<h2>Общее</h2>', $body);
        $this->assertStringContainsString('<h3>Подробнее</h3>', $body);
        $this->assertStringContainsString('<strong>обязательные</strong>', $body);
        $this->assertStringContainsString('<em>документы</em>', $body);
        $this->assertStringContainsString('<u>сведения</u>', $body);
        $this->assertStringContainsString('<s>не все</s>', $body);
        $this->assertStringContainsString('<blockquote>Как сказано в уставе.</blockquote>', $body);
        $this->assertStringContainsString('<hr', $body);
        $this->assertStringContainsString('<mark>выделено</mark>', $body);
        $this->assertStringContainsString('<span>обычное</span>', $body);

        // A list pasted from the middle of another document goes on counting
        // from where it was rather than starting again at one.
        $this->assertStringContainsString('<ol start="7">', $body);
        $this->assertStringContainsString('<ul><li>Ещё</li></ul>', $body);

        // A table, with the two attributes a cell needs to join itself to its
        // neighbours.
        $this->assertStringContainsString('<table><thead><tr><th colspan="2" rowspan="1">Задача</th>', $body);
        $this->assertStringContainsString('<tbody><tr><td>Макет</td><td>Готовый макет</td></tr></tbody>', $body);

        // A link keeps where it points — and is given, not asked for, the two
        // attributes that make it open beside the document and hand the page it
        // opens nothing about this one.
        $this->assertStringContainsString('<a href="https://example.com" rel="noopener noreferrer" target="_blank">порядок</a>', $body);
        $this->assertStringContainsString('rel="noopener noreferrer" target="_blank">адрес</a>', $body);
        $this->assertStringContainsString('mailto:hr', $body);
    }

    /**
     * A tag nobody asked for loses the tag and keeps the words. Losing
     * somebody's sentence because of the wrapper it was pasted inside is the
     * worst thing this could do, and the complaint is unanswerable afterwards.
     */
    public function test_an_unknown_tag_goes_and_the_words_inside_it_stay()
    {
        $body = $this->written(
            '<div><p>Внутри обёртки</p></div>'
            .'<font color="red">Покрашенное слово</font>'
            .'<center><p>По середине</p></center>'
            .'<p onclick="alert(1)" class="x" id="y">Со своими атрибутами</p>'
        );

        $this->assertStringContainsString('<p>Внутри обёртки</p>', $body);
        $this->assertStringContainsString('Покрашенное слово', $body);
        $this->assertStringContainsString('<p>По середине</p>', $body);
        $this->assertStringContainsString('<p>Со своими атрибутами</p>', $body);

        $this->assertStringNotContainsString('<div', $body);
        $this->assertStringNotContainsString('<font', $body);
        $this->assertStringNotContainsString('<center', $body);
        $this->assertStringNotContainsString('onclick', $body);
        $this->assertStringNotContainsString('class=', $body);
        $this->assertStringNotContainsString('id=', $body);
    }

    /**
     * Except where the text is itself the danger — what a script says is what it
     * does — and then the tag and everything in it go together, leaving nothing
     * at all behind.
     */
    public function test_the_body_keeps_nothing_that_could_run_or_point_at_anything_but_a_page()
    {
        $body = $this->written(
            '<p>До</p>'
            .'<script>alert(1)</script>'
            .'<style>p{display:none}</style>'
            .'<iframe src="https://example.com/x">в рамке</iframe>'
            .'<object data="https://example.com/x">в объекте</object>'
            .'<embed src="https://example.com/x">'
            .'<template><p>в шаблоне</p></template>'
            .'<noscript>без скриптов</noscript>'
            .'<head><title>в заголовке</title></head>'
            .'<svg><text>в рисунке</text></svg>'
            .'<math><mi>в формуле</mi></math>'
            .'<img src="https://example.com/x.png">'
            .'<p>После</p>'
        );

        // Nothing at all: not the tag, and not the text it held.
        $this->assertSame('<p>До</p><p>После</p>', $body);

        foreach (['script', 'style', 'iframe', 'object', 'embed', 'template', 'noscript', 'head', 'title', 'svg', 'math', 'img'] as $tag) {
            $this->assertStringNotContainsString("<{$tag}", $body);
        }

        foreach (['alert(1)', 'display:none', 'в рамке', 'в объекте', 'в шаблоне', 'без скриптов', 'в заголовке', 'в рисунке', 'в формуле', 'example.com'] as $gone) {
            $this->assertStringNotContainsString($gone, $body);
        }
    }

    /**
     * A link the sanitizer would not follow keeps its words and loses the tag: an
     * `<a>` with no address is not a link but a tag that does nothing, and
     * anybody reading the stored markup afterwards would take it for something
     * the application had kept.
     */
    public function test_a_link_that_points_nowhere_is_not_kept_as_a_link()
    {
        $body = $this->written(
            '<p>Вот <a href="javascript:alert(1)">это</a> слово.</p>'
            .'<p>И <a>это</a> тоже.</p>'
            .'<p>А <a href="https://example.com">это</a> ссылка.</p>'
        );

        $this->assertStringContainsString('<p>Вот это слово.</p>', $body);
        $this->assertStringContainsString('<p>И это тоже.</p>', $body);
        $this->assertStringNotContainsString('javascript', $body);
        $this->assertStringNotContainsString('alert(1)', $body);

        // And the one that does point somewhere is still a link.
        $this->assertStringContainsString('<p>А <a href="https://example.com" rel="noopener noreferrer" target="_blank">это</a> ссылка.</p>', $body);
        $this->assertSame(1, substr_count($body, '<a '));
    }

    /**
     * A numbered list that goes on counting from somewhere counts from a number.
     * Nothing today would be harmed by a start that says something else, but that
     * is a property of today's renderer rather than of the data.
     */
    public function test_a_numbered_list_counts_from_a_number_or_from_nothing()
    {
        $body = $this->written(
            '<ol start="7"><li>Седьмое</li></ol>'
            .'<ol start=" 3 "><li>Третье</li></ol>'
            .'<ol start="abc"><li>Никакое</li></ol>'
            .'<ol start="0"><li>Нулевое</li></ol>'
            .'<ol start="-2"><li>Минусовое</li></ol>'
        );

        $this->assertStringContainsString('<ol start="7"><li>Седьмое</li></ol>', $body);
        $this->assertStringContainsString('<ol start="3"><li>Третье</li></ol>', $body);
        $this->assertStringContainsString('<ol><li>Никакое</li></ol>', $body);
        $this->assertStringContainsString('<ol><li>Нулевое</li></ol>', $body);
        $this->assertStringContainsString('<ol><li>Минусовое</li></ol>', $body);
    }

    /**
     * The sanitizer allows a style attribute or forbids it and has no opinion
     * about the CSS inside one, so the declarations are read through separately:
     * the alignment and the colour the toolbar writes stay, and whatever else
     * was in the same attribute does not.
     */
    public function test_a_style_attribute_is_cut_down_to_the_alignment_and_the_colour()
    {
        $body = $this->written(
            '<p style="text-align:center;position:fixed">По центру</p>'
            .'<h2 style="text-align: right">Справа</h2>'
            .'<p style="position:absolute">Обычный</p>'
            .'<p>Вот <span style="color:#b42318;background:url(http://example.com/x)">красное</span> слово.</p>'
            .'<p><span style="background-color: rgb(255, 240, 200)">подложка</span></p>'
            .'<p><mark style="color:red">красный</mark></p>'
            .'<p><span style="text-align:center">не для выравнивания</span></p>'
        );

        $this->assertStringContainsString('<p style="text-align: center">По центру</p>', $body);
        $this->assertStringContainsString('<h2 style="text-align: right">Справа</h2>', $body);
        $this->assertStringContainsString('<span style="color: #b42318">красное</span>', $body);
        $this->assertStringContainsString('<span style="background-color: rgb(255, 240, 200)">подложка</span>', $body);
        $this->assertStringContainsString('<mark style="color: red">красный</mark>', $body);

        // Everything else that was written in a style attribute, including the
        // one declaration that was simply on the wrong tag. An attribute with
        // nothing left in it goes rather than being written out empty.
        $this->assertStringNotContainsString('position', $body);
        $this->assertStringNotContainsString('url(', $body);
        $this->assertStringNotContainsString('background:', $body);
        $this->assertStringContainsString('<p>Обычный</p>', $body);
        $this->assertStringContainsString('<span>не для выравнивания</span>', $body);
    }

    /**
     * Setting a line in from the margin is the toolbar's two indent buttons
     * walking a ladder of four steps, and the ladder is the whole of what may be
     * stored: a measurement somebody wrote themselves is not a step of it.
     */
    public function test_a_line_is_set_in_from_the_margin_only_by_the_steps_the_toolbar_offers()
    {
        $body = $this->written(
            '<p style="margin-left: 4em">Сдвинутый</p>'
            .'<ul><li style="margin-left: 2em">Сдвинутая строка</li></ul>'
            .'<p style="text-align:center;margin-left:2em">И то и другое</p>'
            .'<p style="margin-left: 0">У самого края</p>'
            .'<p style="margin-left: -4em">Левее края</p>'
            .'<p style="margin-left: 90%">Доля</p>'
            .'<p style="margin-left: calc(100% - 2px)">Вычисленное</p>'
            .'<p style="margin-left: 30px">В пикселях</p>'
        );

        $this->assertStringContainsString('<p style="margin-left: 4em">Сдвинутый</p>', $body);
        $this->assertStringContainsString('<li style="margin-left: 2em">Сдвинутая строка</li>', $body);
        $this->assertStringContainsString('<p style="text-align: center; margin-left: 2em">И то и другое</p>', $body);

        // The first step is where the line already is, so nothing is written
        // down for it and the attribute goes with the declaration.
        $this->assertStringContainsString('<p>У самого края</p>', $body);

        // And nothing off the ladder is a step on it.
        $this->assertStringContainsString('<p>Левее края</p>', $body);
        $this->assertStringContainsString('<p>Доля</p>', $body);
        $this->assertStringContainsString('<p>Вычисленное</p>', $body);
        $this->assertStringContainsString('<p>В пикселях</p>', $body);
        $this->assertStringNotContainsString('-4em', $body);
        $this->assertStringNotContainsString('90%', $body);
        $this->assertStringNotContainsString('calc(', $body);
        $this->assertStringNotContainsString('30px', $body);
    }

    /** A body longer than a document is refused, and said to be refused. */
    public function test_a_body_longer_than_the_field_is_refused()
    {
        $template = $this->template();
        $department = Department::create(['name' => 'Отдел Дизайна']);

        $this->actingAs($this->keeper())
            ->post("/departments/{$department->id}/regulation/sections", [
                'template_section_id' => $template->first()->id,
                'body' => '<p>'.str_repeat('а', Document::BODY_LENGTH).'</p>',
            ])
            ->assertSessionHasErrors(['body' => 'Текст раздела не должен быть длиннее '.Document::BODY_LENGTH.' символов.']);

        $this->assertSame(0, RegulationSection::where('department_id', $department->id)->count());
    }

    /**
     * The document is read by whoever may open the department's page, which is
     * everybody signed in, and written by whoever keeps the departments — the
     * same pair of rights as the list of units, for the template as much as for
     * one department's own wording and its heading.
     */
    public function test_everybody_reads_it_and_whoever_keeps_the_departments_writes_it()
    {
        $template = $this->template();
        $department = Department::create(['name' => 'Отдел Дизайна']);
        // What a department writes is its wording of a standard section; what
        // the template's address writes is a standard section itself.
        $own = ['template_section_id' => $template->first()->id, 'body' => '<p>Своими словами.</p>'];
        $standard = ['title' => 'ОТВЕТСТВЕННОСТЬ'];
        $heading = ['title' => 'ПОЛОЖЕНИЕ О РАБОТЕ ЧУЖОГО ОТДЕЛА'];

        $colleague = $this->colleague();
        $this->assertFalse($this->regulation($colleague, $department)['can_edit']);
        $this->actingAs($colleague)->post("/departments/{$department->id}/regulation/sections", $own)->assertForbidden();
        $this->actingAs($colleague)->post('/departments/regulation/sections', $standard)->assertForbidden();
        $this->actingAs($colleague)->put("/departments/{$department->id}/regulation/title", $heading)->assertForbidden();

        // Reading the list of units is not keeping it.
        $reader = User::factory()->create()->givePermissionTo(Directories::viewPermission('departments'));
        $this->assertFalse($this->regulation($reader, $department)['can_edit']);
        $this->actingAs($reader)->post("/departments/{$department->id}/regulation/sections", $own)->assertForbidden();
        $this->actingAs($reader)->put("/departments/{$department->id}/regulation/title", $heading)->assertForbidden();

        $this->assertSame(0, RegulationSection::where('department_id', $department->id)->count());
        $this->assertNull($department->fresh()->regulation_title);

        $keeper = $this->keeper();
        $this->assertTrue($this->regulation($keeper, $department)['can_edit']);
        $this->actingAs($keeper)->post("/departments/{$department->id}/regulation/sections", $own)->assertRedirect();
        $this->actingAs($keeper)->post('/departments/regulation/sections', $standard)->assertRedirect();
        $this->actingAs($keeper)->put("/departments/{$department->id}/regulation/title", $heading)->assertRedirect();

        $this->assertSame(1, RegulationSection::where('department_id', $department->id)->count());
        $this->assertSame($template->count() + 1, RegulationSection::whereNull('department_id')->count());
        $this->assertSame('ПОЛОЖЕНИЕ О РАБОТЕ ЧУЖОГО ОТДЕЛА', $department->fresh()->regulation_title);
    }

    /**
     * Tidying the shared document is no reason to throw away what a department
     * wrote by hand: its wording stays, as a section of its own.
     */
    public function test_dropping_a_standard_section_leaves_a_department_its_own_wording()
    {
        $template = $this->template();
        $department = Department::create(['name' => 'Отдел Дизайна']);
        $rights = $template->last();
        $keeper = $this->keeper();

        $this->actingAs($keeper)
            ->post("/departments/{$department->id}/regulation/sections", [
                'template_section_id' => $rights->id,
                'body' => '<p>Утверждает фирменный стиль.</p>',
            ])
            ->assertRedirect();

        $this->actingAs($keeper)->delete("/departments/regulation/sections/{$rights->id}")->assertRedirect();

        $sections = $this->regulation($this->colleague(), $department)['sections'];
        $kept = end($sections);

        $this->assertCount($template->count(), $sections);
        $this->assertSame('own', $kept['origin']);
        $this->assertNull($kept['template_section_id']);
        $this->assertSame('ПРАВА', $kept['title']);
        $this->assertSame('<p>Утверждает фирменный стиль.</p>', $kept['body']);

        // Nobody else reads that section any more.
        $other = Department::create(['name' => 'Отдел аналитики']);
        $this->assertCount($template->count() - 1, $this->regulation($this->colleague(), $other)['sections']);
    }

    public function test_a_department_takes_its_own_wording_with_it_and_reaches_nobody_else_s()
    {
        $template = $this->template();
        $design = Department::create(['name' => 'Отдел Дизайна']);
        $analytics = Department::create(['name' => 'Отдел аналитики']);
        $keeper = $this->keeper();

        $this->actingAs($keeper)
            ->post("/departments/{$design->id}/regulation/sections", [
                'template_section_id' => $template->first()->id,
                'body' => '<p>Работает с типографией.</p>',
            ])
            ->assertRedirect();

        $own = RegulationSection::where('department_id', $design->id)->firstOrFail();

        // One department's id does not reach another's section, nor the
        // template's address another department's.
        $this->actingAs($keeper)->put("/departments/{$analytics->id}/regulation/sections/{$own->id}", ['title' => 'ЧУЖОЕ'])->assertNotFound();
        $this->actingAs($keeper)->delete("/departments/regulation/sections/{$own->id}")->assertNotFound();
        $this->assertSame('ОБЩЕЕ ПОЛОЖЕНИЕ', $own->fresh()->title);
        $this->assertSame('<p>Работает с типографией.</p>', $own->fresh()->body);

        // The regulation is part of the department, so it goes with it — and
        // the company's own text stays exactly where it was.
        $design->delete();

        $this->assertNull(RegulationSection::find($own->id));
        $this->assertSame($template->count(), RegulationSection::count());
        $this->assertSame($template->pluck('title')->all(), RegulationSection::forDepartment(null)->pluck('title')->all());
    }

    /**
     * A standard text, written by the test: nothing seeds one any more, and the
     * template is still what an override overrides.
     *
     * @return Collection<int, RegulationSection>
     */
    private function template(): Collection
    {
        foreach (['ОБЩЕЕ ПОЛОЖЕНИЕ', 'ЗАДАЧИ И ЦЕННЫЙ КОНЕЧНЫЙ ПРОДУКТ', 'ПРАВА'] as $place => $title) {
            RegulationSection::create([
                'title' => $title,
                'body' => "<p>{подразделение}: {$title}.</p>",
                'order' => ($place + 1) * 100,
            ]);
        }

        return RegulationSection::forDepartment(null);
    }

    /**
     * The numbers, and the headings, of the document this department reads.
     *
     * @return list<string>
     */
    private function numbers(Department $department): array
    {
        return array_column($this->regulation($this->colleague(), $department)['sections'], 'number');
    }

    /** @return list<string> */
    private function titles(Department $department): array
    {
        return array_column($this->regulation($this->colleague(), $department)['sections'], 'title');
    }

    /**
     * A body as it ends up on file: written through the page by somebody who
     * may, because the page is where it is cleaned.
     *
     * Written as a department's wording of a standard section, which is the one
     * way a body is written at a department's address — so the markup these
     * tests send goes through exactly the save the page performs.
     */
    private function written(string $html): string
    {
        $template = $this->template();
        $department = Department::create(['name' => 'Отдел Дизайна']);

        $this->actingAs($this->keeper())
            ->post("/departments/{$department->id}/regulation/sections", [
                'template_section_id' => $template->last()->id,
                'body' => $html,
            ])
            ->assertRedirect();

        return RegulationSection::where('department_id', $department->id)->firstOrFail()->body;
    }

    /** Somebody who both reads the list of units and keeps it. */
    private function keeper(): User
    {
        return User::factory()->create()->givePermissionTo([
            Directories::viewPermission('departments'),
            Directories::editPermission('departments'),
        ]);
    }

    /**
     * The regulation as this person reads it on the department's page.
     *
     * @return array<string, mixed>
     */
    private function regulation(User $user, Department $department): array
    {
        return $this->actingAs($user)
            ->get("/departments/{$department->id}")
            ->assertOk()
            ->viewData('page')['props']['regulation'];
    }
}
