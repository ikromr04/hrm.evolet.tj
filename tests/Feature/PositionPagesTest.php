<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Position;
use App\Models\User;
use App\Support\Directories;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * "Должности": a section of its own, after the equipment.
 *
 * The list reads like the staff and the fleet read — a table of positions and
 * where each one sits, read along the structure: the department, the unit inside
 * it, the position and what it answers for. Who holds it is a page of its own,
 * which says what each holder is responsible for besides.
 *
 * Moving the list out of "Справочники" moved no rights: it is still read with
 * "directories.view.positions" and kept with "directories.edit.positions", and
 * both are still offered by the access table. Filing a position in a department
 * is part of keeping the position and asks for nothing more.
 *
 * A position belongs to one department, and its name is only taken within that
 * one: «Аналитик» exists in several departments, and a name alone no longer says
 * which job it is. The duties are one flat list per position, and which of that
 * list a holder carries is kept on the holder — holding a position hands nobody
 * the whole of it.
 */
class PositionPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    }

    /** Somebody holding exactly these rights and nothing else. */
    private function person(string ...$rights): User
    {
        $user = User::factory()->create();

        return $rights === [] ? $user : $user->givePermissionTo($rights);
    }

    /** Whoever keeps the list, and may read a card like any colleague. */
    private function keeper(): User
    {
        return $this->mayLookAround($this->person(
            Directories::viewPermission('positions'),
            Directories::editPermission('positions'),
        ));
    }

    /**
     * One row of the list, picked out by the position it is about.
     *
     * The server hands the list over in a settled order and the page reorders it
     * anyway, and names on it repeat now, so counting to a row would be counting
     * on an order nothing promises.
     *
     * @param  iterable<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function row(iterable $rows, Position $position): array
    {
        return collect($rows)->firstWhere('id', $position->id);
    }

    public function test_the_list_names_every_position_with_its_duties_and_counts_its_people()
    {
        $department = Department::create(['name' => 'Отдел правового регулирования', 'abbreviation' => 'ОПР']);
        $lawyer = Position::create(['name' => 'Юрист', 'department_id' => $department->id]);
        $lawyer->duties()->createMany([
            ['name' => 'Ведение договоров', 'order' => 0],
            ['name' => 'Арбитраж', 'order' => 1],
        ]);
        Position::create(['name' => 'Курьер', 'department_id' => $department->id]);

        // Two who work here and one who does not: the count is the staff list as
        // the staff list counts itself, of people who are still here.
        User::factory()->create(['surname' => 'Бобоев', 'name' => 'Фаррух'])->positions()->attach($lawyer);
        User::factory()->create(['surname' => 'Азимова', 'name' => 'Нигина'])->positions()->attach($lawyer);
        User::factory()->create(['surname' => 'Ашуров', 'name' => 'Далер', 'status' => 'fired'])
            ->positions()->attach($lawyer);

        $this->actingAs($this->mayLookAround($this->person(Directories::viewPermission('positions'))))
            ->get('/positions')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('positions/index')
                // By name, and the duties in the order somebody entered them.
                ->where('positions.0.name', 'Курьер')
                ->where('positions.0.duties', [])
                ->where('positions.0.employees_count', 0)
                ->where('positions.1.name', 'Юрист')
                ->where('positions.1.duties', ['Ведение договоров', 'Арбитраж'])
                // One list and nothing to group inside it: the position belongs
                // to one department, and what the job is elsewhere is another
                // position's business.
                ->missing('positions.1.department_duties')
                // The number the warning before a deletion counts. Who those two
                // are is the position's own page: the list stopped naming them,
                // so none of them travels to it any more, and neither do the
                // rights that decided whether they could be named.
                ->where('positions.1.employees_count', 2)
                ->missing('positions.1.employees')
                ->missing('showsEmployees')
                ->missing('canSeeEmployees')
                // The page opens the list newest first and puts it in order
                // itself, so a row carries the moment it was added; by name is
                // only the settled order the server hands it over in.
                ->where('positions.1.created_at', $lawyer->created_at->toIso8601String())
                // Reading the list is not keeping it.
                ->where('canEdit', false)
            );
    }

    public function test_the_list_carries_the_department_a_position_sits_in_and_the_one_above_it()
    {
        $analytics = Department::create(['name' => 'Департамент аналитики и статистики', 'abbreviation' => 'ДАС']);
        $europe = Department::create([
            'name' => 'Отдел мониторинга Эволет Европы',
            'abbreviation' => 'ОМФК Европа',
            'parent_id' => $analytics->id,
        ]);
        $regulation = Department::create([
            'name' => 'Отдел правового регулирования',
            'abbreviation' => 'ОПР',
            'parent_id' => $analytics->id,
        ]);

        // The same word and different work: two positions, not one position with
        // two sets of duties.
        $here = Position::create(['name' => 'Аналитик', 'department_id' => $regulation->id]);
        $there = Position::create(['name' => 'Аналитик', 'department_id' => $europe->id]);
        $here->duties()->create(['name' => 'Готовит ответы регулятору', 'order' => 0]);
        $there->duties()->create(['name' => 'Сверяет цены по Европе', 'order' => 0]);
        // One that hangs off the department itself with no unit between: several
        // departments are filed that way, and one of them has no units at all.
        $above = Position::create(['name' => 'Руководитель', 'department_id' => $analytics->id]);
        // And one the data could not place: three of them came out of the move
        // that way, and the list is where somebody has to find them.
        $loose = Position::create(['name' => 'Курьер']);

        $department = fn (Department $d) => ['id' => $d->id, 'name' => $d->abbreviation, 'full_name' => $d->name];

        $this->actingAs($this->mayLookAround($this->person(Directories::viewPermission('positions'))))
            ->get('/positions')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                // Named the way every screen but the directory names a
                // department, with the full name along for the hover — and with
                // the department above it, which the list reads in a column of
                // its own: the page has no tree to walk up, and the structure is
                // two deep, so the pair is the whole of where a position sits.
                ->where('positions', fn ($rows) => $this->row($rows, $here)['department']
                    === [...$department($regulation), 'parent' => $department($analytics)])
                ->where('positions', fn ($rows) => $this->row($rows, $there)['department']
                    === [...$department($europe), 'parent' => $department($analytics)])
                // A position of the department itself has nothing above it, which
                // is how the «Отдел» column knows to stay empty.
                ->where('positions', fn ($rows) => $this->row($rows, $above)['department']
                    === [...$department($analytics), 'parent' => null])
                // The plain name is still the plain name — it is what a column of
                // its own is beside — and the two spelled out together are what
                // the places with one line to spare read.
                ->where('positions', fn ($rows) => $this->row($rows, $here)['name'] === 'Аналитик')
                ->where('positions', fn ($rows) => collect($rows)->pluck('display_name')->sort()->values()->all()
                    === ['Аналитик (ОМФК Европа)', 'Аналитик (ОПР)', 'Курьер', 'Руководитель (ДАС)'])
                // An unplaced position travels as one: nothing where its
                // department would be, and no brackets after its name.
                ->where('positions', fn ($rows) => $this->row($rows, $loose)['department'] === null)
                ->where('positions', fn ($rows) => $this->row($rows, $loose)['display_name'] === 'Курьер')
                // And each position's duties are its own one list.
                ->where('positions', fn ($rows) => $this->row($rows, $here)['duties'] === ['Готовит ответы регулятору'])
                // The picker and the filter read the whole tree, parents before
                // their own, indented by the depth the staff list's filter is
                // already indented by.
                ->where('options.departments', [
                    ['id' => $analytics->id, 'name' => 'ДАС', 'full_name' => 'Департамент аналитики и статистики', 'depth' => 0],
                    ['id' => $europe->id, 'name' => 'ОМФК Европа', 'full_name' => 'Отдел мониторинга Эволет Европы', 'depth' => 1],
                    ['id' => $regulation->id, 'name' => 'ОПР', 'full_name' => 'Отдел правового регулирования', 'depth' => 1],
                ])
            );
    }

    public function test_a_positions_page_names_its_holders_with_the_duties_of_all_they_hold()
    {
        $department = Department::create(['name' => 'Отдел правового регулирования', 'abbreviation' => 'ОПР']);
        $lawyer = Position::create(['name' => 'Юрист', 'department_id' => $department->id]);
        $contracts = $lawyer->duties()->create(['name' => 'Ведение договоров', 'order' => 0]);
        $translator = Position::create(['name' => 'Переводчик', 'department_id' => $department->id]);
        $papers = $translator->duties()->create(['name' => 'Перевод документов', 'order' => 0]);

        $both = User::factory()->create(['surname' => 'Азимова', 'name' => 'Нигина']);
        $both->positions()->attach([$lawyer->id, $translator->id]);
        $both->duties()->attach([$contracts->id, $papers->id]);
        $only = User::factory()->create(['surname' => 'Бобоев', 'name' => 'Фаррух']);
        $only->positions()->attach($lawyer);
        $only->duties()->attach($contracts);
        $fired = User::factory()->create(['surname' => 'Ашуров', 'name' => 'Далер', 'status' => 'fired']);
        $fired->positions()->attach($lawyer);

        $this->actingAs($this->mayLookAround($this->person(Directories::viewPermission('positions'))))
            ->get("/positions/{$lawyer->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('positions/show')
                ->where('position.name', 'Юрист')
                ->where('position.display_name', 'Юрист (ОПР)')
                ->where('position.department', ['id' => $department->id, 'name' => 'ОПР', 'full_name' => 'Отдел правового регулирования'])
                ->where('position.duties', [['id' => $contracts->id, 'name' => 'Ведение договоров']])
                ->missing('position.department_duties')
                // Who still works here, by surname, named as every list names
                // somebody: "Фамилия Имя".
                ->where('employees', fn ($rows) => collect($rows)->pluck('name')->all() === ['Азимова Нигина', 'Бобоев Фаррух'])
                // Everything the person is responsible for, whichever position it
                // comes from — the positions as a card lists them, by name, each
                // with the department that tells it from a namesake; the page
                // picks this position's duties out by the position they belong
                // to.
                ->where('employees.0.positions', [
                    [
                        'id' => $translator->id,
                        'name' => 'Переводчик',
                        'display_name' => 'Переводчик (ОПР)',
                        // The department apart from the title, so the page can
                        // put its full name under the cursor on «ОПР».
                        'unit' => ['name' => 'ОПР', 'full_name' => 'Отдел правового регулирования'],
                    ],
                    [
                        'id' => $lawyer->id,
                        'name' => 'Юрист',
                        'display_name' => 'Юрист (ОПР)',
                        'unit' => ['name' => 'ОПР', 'full_name' => 'Отдел правового регулирования'],
                    ],
                ])
                ->where('employees.0.duties', [
                    ['id' => $contracts->id, 'name' => 'Ведение договоров', 'position_id' => $lawyer->id],
                    ['id' => $papers->id, 'name' => 'Перевод документов', 'position_id' => $translator->id],
                ])
                ->where('employees.1.duties', [
                    ['id' => $contracts->id, 'name' => 'Ведение договоров', 'position_id' => $lawyer->id],
                ])
                ->where('canOpenCards', true)
                ->where('showsEmployees', true)
                ->where('showsPositions', true)
                ->where('canEdit', false)
            );
    }

    public function test_a_holder_carries_the_duties_picked_out_for_them_and_not_the_whole_list()
    {
        $regulation = Department::create(['name' => 'Отдел правового регулирования', 'abbreviation' => 'ОПР']);
        $design = Department::create(['name' => 'Отдел Дизайна', 'abbreviation' => 'ОД']);

        $analyst = Position::create(['name' => 'Аналитик', 'department_id' => $regulation->id]);
        $market = $analyst->duties()->create(['name' => 'Готовит отчёт по рынку', 'order' => 0]);
        $answers = $analyst->duties()->create(['name' => 'Готовит ответы регулятору', 'order' => 1]);
        // Nobody was given this one: the list is what the position answers for
        // between its people, and a line of it can be waiting for somebody.
        $prices = $analyst->duties()->create(['name' => 'Сверяет цены по Европе', 'order' => 2]);

        $designer = Position::create(['name' => 'Дизайнер', 'department_id' => $design->id]);
        $covers = $designer->duties()->create(['name' => 'Рисует обложки отчётов', 'order' => 0]);

        // One carrying a line of each of two positions, one carrying a single
        // line here, and one who holds the position and carries nothing of it
        // yet.
        $both = User::factory()->create(['surname' => 'Азимова', 'name' => 'Нигина']);
        $both->positions()->attach([$analyst->id, $designer->id]);
        $both->duties()->attach([$market->id, $covers->id]);
        $one = User::factory()->create(['surname' => 'Бобоев', 'name' => 'Фаррух']);
        $one->positions()->attach($analyst);
        $one->duties()->attach($answers);
        $none = User::factory()->create(['surname' => 'Валиев', 'name' => 'Сухроб']);
        $none->positions()->attach($analyst);

        $this->actingAs($this->mayLookAround($this->person(Directories::viewPermission('positions'))))
            ->get("/positions/{$analyst->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                // The whole list the position answers for, as the page heads
                // itself with.
                ->where('position.duties', [
                    ['id' => $market->id, 'name' => 'Готовит отчёт по рынку'],
                    ['id' => $answers->id, 'name' => 'Готовит ответы регулятору'],
                    ['id' => $prices->id, 'name' => 'Сверяет цены по Европе'],
                ])
                // And beside each holder only what was picked out for them: the
                // position's own first, then what they carry elsewhere, which
                // names the position it came from.
                ->where('employees.0.duties', [
                    ['id' => $market->id, 'name' => 'Готовит отчёт по рынку', 'position_id' => $analyst->id],
                    ['id' => $covers->id, 'name' => 'Рисует обложки отчётов', 'position_id' => $designer->id],
                ])
                ->where('employees.1.duties', [
                    ['id' => $answers->id, 'name' => 'Готовит ответы регулятору', 'position_id' => $analyst->id],
                ])
                // Holding the position hands nobody the list: an empty one here
                // is the sharing out not having reached this person, which the
                // page tells from a position with nothing to share by the list
                // above.
                ->where('employees.2.duties', [])
                // And a line nobody was given is nobody's.
                ->where('employees', fn ($rows) => collect($rows)
                    ->pluck('duties')
                    ->flatten(1)
                    ->doesntContain('id', $prices->id))
            );
    }

    public function test_the_holders_cost_the_same_few_queries_however_many_there_are()
    {
        $department = Department::create(['name' => 'Отдел правового регулирования', 'abbreviation' => 'ОПР']);
        $position = Position::create(['name' => 'Юрист', 'department_id' => $department->id]);
        $contracts = $position->duties()->create(['name' => 'Ведение договоров', 'order' => 0]);
        $position->duties()->create(['name' => 'Арбитраж', 'order' => 1]);
        $reader = $this->mayLookAround($this->person(Directories::viewPermission('positions')));

        $count = function (int $holders) use ($position, $contracts, $reader) {
            User::factory($holders)->create()->each(function (User $u) use ($position, $contracts) {
                $u->positions()->attach($position);
                $u->duties()->attach($contracts);
            });

            return $this->reads(fn () => $this->actingAs($reader)->get("/positions/{$position->id}")->assertOk());
        };

        // A page of people is read with the relations rather than with a query
        // apiece, so five more holders — each with their own positions and the
        // duties they carry — cost nothing more than the first one.
        $this->assertSame($count(1), $count(5));
    }

    public function test_the_list_costs_the_same_few_queries_however_many_positions_there_are()
    {
        $reader = $this->mayLookAround($this->person(Directories::viewPermission('positions')));

        $count = function (int $positions) use ($reader) {
            foreach (range(1, $positions) as $ignored) {
                $department = Department::create(['name' => 'Отдел '.Department::count()]);
                $position = Position::create(['name' => 'Должность '.Position::count(), 'department_id' => $department->id]);
                $position->duties()->create(['name' => 'Ведение договоров', 'order' => 0]);
                $position->duties()->create(['name' => 'Арбитраж', 'order' => 1]);
                User::factory(2)->create()->each(fn (User $u) => $u->positions()->attach($position));
            }

            return $this->reads(fn () => $this->actingAs($reader)->get('/positions')->assertOk());
        };

        // The positions, their departments, their duties and the people holding
        // them are a query each for the whole table rather than per row, so a
        // company with seven positions reads the list in as many queries as one
        // with two.
        $this->assertSame($count(2), $count(5));
    }

    /**
     * How many queries a visit spends reading the tables these pages are built
     * from. What the rights themselves cost to look up is another matter and is
     * not counted.
     *
     * The quoting is stripped before the names are looked for: the suite runs on
     * SQLite and production on MySQL, and the two do not quote a table the same
     * way — counted by the one spelling, a page of a hundred queries would come
     * out as none at all.
     */
    private function reads(callable $visit): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $visit();
        $reads = collect(DB::getQueryLog())
            ->pluck('query')
            ->map(fn (string $query) => str_replace(['`', '"'], '', $query))
            ->filter(fn (string $query) => str_contains($query, 'from users')
                || str_contains($query, 'from positions')
                || str_contains($query, 'from position_duties')
                // A position names a department and a row is read under that
                // department's name, so the table is part of what a page costs.
                || str_contains($query, 'from departments'))
            ->count();
        DB::disableQueryLog();

        return $reads;
    }

    public function test_reading_the_list_and_keeping_it_are_the_two_rights_it_has_always_had()
    {
        $department = Department::create(['name' => 'Отдел правового регулирования', 'abbreviation' => 'ОПР']);
        $position = Position::create(['name' => 'Юрист', 'department_id' => $department->id]);
        $courier = ['department_id' => $department->id, 'name' => 'Курьер'];

        // Nobody signed in reads it at all.
        $this->get('/positions')->assertRedirect('/login');
        $this->get("/positions/{$position->id}")->assertRedirect('/login');

        // Neither right: the pages are shut and so is every form.
        $nobody = $this->person();
        $this->actingAs($nobody)->get('/positions')->assertForbidden();
        $this->actingAs($nobody)->get("/positions/{$position->id}")->assertForbidden();
        $this->actingAs($nobody)->post('/positions', $courier)->assertForbidden();
        $this->actingAs($nobody)->put("/positions/{$position->id}", $courier)->assertForbidden();
        $this->actingAs($nobody)->delete("/positions/{$position->id}")->assertForbidden();

        // Reading it is not changing it.
        $reader = $this->person(Directories::viewPermission('positions'));
        $this->actingAs($reader)->get('/positions')->assertOk();
        $this->actingAs($reader)->get("/positions/{$position->id}")->assertOk();
        $this->actingAs($reader)->post('/positions', $courier)->assertForbidden();
        $this->assertSame(1, Position::count());

        // And changing it takes reading it as well: a list one cannot open is not
        // a list one adds to.
        $editor = $this->person(Directories::editPermission('positions'));
        $this->actingAs($editor)->get('/positions')->assertForbidden();
        $this->actingAs($editor)->post('/positions', $courier)->assertForbidden();
        $this->assertSame(1, Position::count());

        $keeper = $this->keeper();
        $this->actingAs($keeper)->get('/positions')->assertInertia(fn (Assert $page) => $page->where('canEdit', true));
        $this->actingAs($keeper)->post('/positions', $courier)->assertRedirect();
        $this->assertSame(2, Position::count());

        // Filing a position in a department is part of keeping the position and
        // asks for nothing else: whoever keeps the list moves one, and whoever
        // only reads the list moves nothing at all.
        $europe = Department::create(['name' => 'Отдел мониторинга Эволет Европы', 'abbreviation' => 'ОМФК Европа']);
        $moved = ['department_id' => $europe->id, 'name' => 'Юрист', 'duties' => [['name' => 'Ведение договоров']]];

        $this->actingAs($reader)->put("/positions/{$position->id}", $moved)->assertForbidden();
        $this->actingAs($keeper)->put("/positions/{$position->id}", $moved)->assertRedirect();
        $this->assertSame($europe->id, $position->fresh()->department_id);
        $this->assertSame(['Ведение договоров'], $position->fresh()->duties->pluck('name')->all());
    }

    public function test_a_position_is_added_renamed_and_deleted_with_its_duties()
    {
        $this->actingAs($this->keeper());
        $department = Department::create(['name' => 'Отдел правового регулирования', 'abbreviation' => 'ОПР']);

        // Trimmed, the blank lines dropped, and the order kept as sent.
        $this->post('/positions', ['department_id' => $department->id, 'name' => 'Юрист', 'duties' => [
            ['name' => '  Ведение договоров '],
            ['name' => ''],
            ['name' => 'Арбитраж'],
        ]])->assertSessionHasNoErrors();
        $position = Position::firstWhere('name', 'Юрист');
        $this->assertSame($department->id, $position->department_id);
        $this->assertSame(['Ведение договоров', 'Арбитраж'], $position->duties->pluck('name')->all());
        $this->assertSame([0, 1], $position->duties->pluck('order')->all());

        // Saved again, the list replaces what was there rather than adding to it.
        $this->put("/positions/{$position->id}", ['department_id' => $department->id, 'name' => 'Юрисконсульт', 'duties' => [
            ['name' => 'Арбитраж'],
            ['name' => 'Претензии'],
        ]])->assertSessionHasNoErrors();
        $this->assertSame('Юрисконсульт', $position->fresh()->name);
        $this->assertSame(['Арбитраж', 'Претензии'], $position->fresh()->duties->pluck('name')->all());
        $this->assertSame(2, DB::table('position_duties')->count());

        // A rename that says nothing about them leaves them be; an empty list is
        // what clears them.
        $this->put("/positions/{$position->id}", ['department_id' => $department->id, 'name' => 'Юрист'])->assertSessionHasNoErrors();
        $this->assertCount(2, $position->fresh()->duties);
        $this->put("/positions/{$position->id}", ['department_id' => $department->id, 'name' => 'Юрист', 'duties' => []])
            ->assertSessionHasNoErrors();
        $this->assertCount(0, $position->fresh()->duties);

        $this->post('/positions', ['department_id' => $department->id, 'name' => 'Юрист'])->assertSessionHasErrors('name');
        $this->post('/positions', ['department_id' => $department->id, 'name' => ''])->assertSessionHasErrors('name');
        $this->post('/positions', ['department_id' => $department->id, 'name' => 'Курьер', 'duties' => [['name' => str_repeat('а', 301)]]])
            ->assertSessionHasErrors('duties.0.name');
        // Not a duty as the form writes one.
        $this->post('/positions', ['department_id' => $department->id, 'name' => 'Курьер', 'duties' => ['Ведение договоров']])
            ->assertSessionHasErrors('duties.0');
        $this->assertNull(Position::firstWhere('name', 'Курьер'));

        // The duties go with the position, and its holders keep their others.
        $employee = User::factory()->create();
        $employee->positions()->attach($position);
        $this->put("/positions/{$position->id}", ['department_id' => $department->id, 'name' => 'Юрист', 'duties' => [['name' => 'Арбитраж']]])
            ->assertSessionHasNoErrors();

        $this->delete("/positions/{$position->id}")->assertSessionHasNoErrors();
        $this->assertNull(Position::find($position->id));
        $this->assertSame(0, DB::table('position_duties')->count());
        $this->assertCount(0, $employee->fresh()->positions);
    }

    public function test_the_duties_are_saved_as_one_list_numbered_from_the_top()
    {
        $this->actingAs($this->keeper());
        $department = Department::create(['name' => 'Отдел правового регулирования', 'abbreviation' => 'ОПР']);

        // A duty used to name the department it was done in, because one
        // position served every department that held it. The position names one
        // itself now, so the column is gone and with it the groups a list was
        // numbered in.
        $this->assertFalse(Schema::hasColumn('position_duties', 'department_id'));

        $this->post('/positions', ['department_id' => $department->id, 'name' => 'Аналитик', 'duties' => [
            ['name' => 'Готовит отчёт по рынку'],
            ['name' => '  Готовит ответы регулятору '],
            ['name' => ''],
            ['name' => 'Проверяет документы на соответствие закону'],
            ['name' => 'Сверяет цены по Европе'],
        ]])->assertSessionHasNoErrors();

        // One list, numbered from the top, in the order it was typed: no two
        // lines share a place in it.
        $position = Position::firstWhere('name', 'Аналитик');
        $this->assertSame([
            ['Готовит отчёт по рынку', 0],
            ['Готовит ответы регулятору', 1],
            ['Проверяет документы на соответствие закону', 2],
            ['Сверяет цены по Европе', 3],
        ], $position->duties->map(fn ($duty) => [$duty->name, $duty->order])->all());

        // Saved again, the list replaces the lot: a line the form no longer
        // shows is one somebody deleted, and the rest are renumbered from the
        // top of what is left.
        $this->put("/positions/{$position->id}", ['department_id' => $department->id, 'name' => 'Аналитик', 'duties' => [
            ['name' => 'Сверяет цены по Европе'],
            ['name' => 'Готовит отчёт по рынку'],
        ]])->assertSessionHasNoErrors();

        $this->assertSame([
            ['Сверяет цены по Европе', 0],
            ['Готовит отчёт по рынку', 1],
        ], $position->fresh()->duties->map(fn ($duty) => [$duty->name, $duty->order])->all());

        // And an empty list clears it.
        $this->put("/positions/{$position->id}", ['department_id' => $department->id, 'name' => 'Аналитик', 'duties' => []])
            ->assertSessionHasNoErrors();
        $this->assertCount(0, $position->fresh()->duties);
    }

    public function test_a_name_is_taken_within_a_department_and_free_outside_it()
    {
        $this->actingAs($this->keeper());
        $regulation = Department::create(['name' => 'Отдел правового регулирования', 'abbreviation' => 'ОПР']);
        $europe = Department::create(['name' => 'Отдел мониторинга Эволет Европы', 'abbreviation' => 'ОМФК Европа']);

        $this->post('/positions', ['department_id' => $regulation->id, 'name' => 'Аналитик'])->assertSessionHasNoErrors();
        // The same name in another department is the whole point of the move:
        // the two are the same word and different work.
        $this->post('/positions', ['department_id' => $europe->id, 'name' => 'Аналитик'])->assertSessionHasNoErrors();
        $this->assertSame(2, Position::where('name', 'Аналитик')->count());

        // Twice in one department is one job written down twice, and the form
        // says so instead of letting the model throw.
        $this->post('/positions', ['department_id' => $regulation->id, 'name' => 'Аналитик'])->assertSessionHasErrors('name');
        $this->assertSame(2, Position::where('name', 'Аналитик')->count());

        // A position nobody has placed is not one the form adds: the column
        // allows it only for the few the move could not place, and those are
        // there to be filed rather than added to.
        $this->post('/positions', ['name' => 'Курьер'])->assertSessionHasErrors('department_id');
        $this->post('/positions', ['department_id' => 9999, 'name' => 'Курьер'])->assertSessionHasErrors('department_id');
        $this->assertNull(Position::firstWhere('name', 'Курьер'));

        $analyst = Position::where(['name' => 'Аналитик', 'department_id' => $regulation->id])->firstOrFail();

        // A position keeps its own name where it stands.
        $this->put("/positions/{$analyst->id}", ['department_id' => $regulation->id, 'name' => 'Аналитик'])->assertSessionHasNoErrors();
        // Moved into a department that already has the name, it is refused.
        $this->put("/positions/{$analyst->id}", ['department_id' => $europe->id, 'name' => 'Аналитик'])->assertSessionHasErrors('name');
        $this->assertSame($regulation->id, $analyst->fresh()->department_id);
        // Moved where the name is free, it goes, and reads under the new
        // department from then on.
        $this->put("/positions/{$analyst->id}", ['department_id' => $europe->id, 'name' => 'Юрист'])->assertSessionHasNoErrors();
        $this->assertSame($europe->id, $analyst->fresh()->department_id);
        $this->assertSame('Юрист (ОМФК Европа)', $analyst->fresh()->display_name);
    }

    public function test_an_unplaced_position_reads_as_unplaced_on_both_pages()
    {
        // Three came out of the move with nowhere to sit, so both pages have to
        // say so rather than leave a blank where a department goes.
        $loose = Position::create(['name' => 'Курьер']);
        $loose->duties()->create(['name' => 'Доставляет документы', 'order' => 0]);

        $reader = $this->mayLookAround($this->person(Directories::viewPermission('positions')));

        $this->actingAs($reader)
            ->get('/positions')
            ->assertInertia(fn (Assert $page) => $page
                ->where('positions.0.department', null)
                // No empty brackets: the name is all there is to read.
                ->where('positions.0.display_name', 'Курьер')
            );

        $this->actingAs($reader)
            ->get("/positions/{$loose->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('position.department', null)
                ->where('position.display_name', 'Курьер')
                ->has('position.duties', 1)
            );
    }

    public function test_the_holders_are_a_line_of_a_card_and_are_shown_as_such()
    {
        $department = Department::create(['name' => 'Отдел правового регулирования', 'abbreviation' => 'ОПР']);
        $position = Position::create(['name' => 'Юрист', 'department_id' => $department->id]);
        $contracts = $position->duties()->create(['name' => 'Ведение договоров', 'order' => 0]);
        $holder = User::factory()->create(['surname' => 'Азимова', 'name' => 'Нигина']);
        $holder->positions()->attach($position);
        $holder->duties()->attach($contracts);

        // Whoever may not read the staff list reads the position and nobody in it.
        $this->actingAs($this->person(Directories::viewPermission('positions')))
            ->get("/positions/{$position->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('position.name', 'Юрист')
                ->has('position.duties', 1)
                ->where('employees', [])
                ->where('showsEmployees', false)
                ->where('canOpenCards', false)
            );

        // And whoever may read the list but not that line of a card sees the
        // holders without what any of them is carrying.
        $this->actingAs($this->person(Directories::viewPermission('positions'), 'employees.view'))
            ->get("/positions/{$position->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('showsEmployees', true)
                ->where('showsPositions', false)
                ->where('employees.0.name', 'Азимова Нигина')
                ->where('employees.0.positions', [])
                ->where('employees.0.duties', [])
            );

        // The list of positions is about the positions: it named the holders of
        // every row and no longer does, so nobody travels to it — not even to
        // whoever may read the whole staff list — and the rights that used to
        // decide whether a row could name them are not asked there at all. The
        // count stays, because the warning before a deletion counts it.
        foreach ([
            $this->person(Directories::viewPermission('positions')),
            $this->person(Directories::viewPermission('positions'), 'employees.view'),
            $this->person(Directories::viewPermission('positions'), 'employees.view', 'employees.field.positions'),
        ] as $viewer) {
            $this->actingAs($viewer)
                ->get('/positions')
                ->assertInertia(fn (Assert $page) => $page
                    ->where('positions.0.employees_count', 1)
                    ->missing('positions.0.employees')
                    ->missing('showsEmployees')
                    ->missing('canSeeEmployees')
                );
        }
    }

    public function test_the_reference_lists_no_longer_offer_the_job_titles_but_the_rights_stay()
    {
        $sysadmin = User::factory()->create()->assignRole('sysadmin');

        // Not a tab any more, and not an address of the section either.
        $this->assertNotContains('positions', Directories::TABS);
        $this->assertNotContains('positions', Directories::visibleTo($sysadmin));
        $this->assertNotContains('positions', Directories::editableBy($sysadmin));
        $this->actingAs($sysadmin)->get('/directories/positions')->assertNotFound();
        $this->actingAs($sysadmin)->get('/directories')->assertRedirect('/directories/roles');

        // The section is shut for somebody who may read nothing but the job
        // titles — that list is elsewhere now and answers for itself.
        $reader = $this->person(Directories::viewPermission('positions'));
        $this->actingAs($reader)->get('/directories')->assertForbidden();
        $this->actingAs($reader)->get('/positions')->assertOk();

        // The two rights are still the two rights, and the access table still
        // offers both: the production table is keyed by these very strings.
        $this->assertContains('directories.view.positions', Directories::permissions());
        $this->assertContains('directories.edit.positions', Directories::permissions());
        $this->assertArrayHasKey('positions', Directories::LISTS);

        $props = $this->actingAs($sysadmin)->get('/directories/access')->assertOk()->viewData('page')['props'];

        $this->assertContains('directories.view.positions', array_column($props['directoryLists'], 'key'));
        $this->assertContains('directories.edit.positions', array_column($props['directoryEdits'], 'key'));
    }
}
