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
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * "Должности": a section of its own, after the equipment.
 *
 * The list reads like the staff and the fleet read — a table of positions, what
 * each answers for and how many people hold it — and a position's own page says
 * who holds it and what each of them is responsible for, this position's duties
 * picked out among the rest.
 *
 * Moving the list out of "Справочники" moved no rights: it is still read with
 * "directories.view.positions" and kept with "directories.edit.positions", and
 * both are still offered by the access table. Naming a department on a duty is
 * part of keeping the position and asks for nothing more.
 *
 * A duty may name a department, because an analyst in one department does not
 * answer for what an analyst in another answers for: both pages read the duties
 * of a position in groups — the common ones first, then a group per department
 * that adds something of its own.
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

    public function test_the_list_names_every_position_with_its_duties_and_its_people()
    {
        $lawyer = Position::create(['name' => 'Юрист']);
        $lawyer->duties()->createMany([
            ['name' => 'Ведение договоров', 'order' => 0],
            ['name' => 'Арбитраж', 'order' => 1],
        ]);
        Position::create(['name' => 'Курьер']);

        // Two who work here and one who does not: the holders and the count are
        // the staff list the number links to, and that list is of people who are
        // still here.
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
                ->where('positions.0.employees', [])
                ->where('positions.0.department_duties', [])
                ->where('positions.1.name', 'Юрист')
                ->where('positions.1.duties', ['Ведение договоров', 'Арбитраж'])
                // Nothing here belongs to one department rather than another,
                // so there are no groups to head with one.
                ->where('positions.1.department_duties', [])
                ->where('positions.1.employees_count', 2)
                // The page opens the list newest first and puts it in order
                // itself, so a row carries the moment it was added; by name is
                // only the settled order the server hands it over in.
                ->where('positions.1.created_at', $lawyer->created_at->toIso8601String())
                // The holders themselves, by surname and named "Фамилия Имя", the
                // same people the position's own page lists.
                ->where('positions.1.employees', fn ($rows) => collect($rows)->pluck('name')->all()
                    === ['Азимова Нигина', 'Бобоев Фаррух'])
                ->where('positions.1.employees.0.id', User::firstWhere('surname', 'Азимова')->id)
                ->where('showsEmployees', true)
                // Reading the list is not keeping it.
                ->where('canEdit', false)
                ->where('canSeeEmployees', true)
            );
    }

    public function test_the_list_keeps_what_a_position_answers_for_in_one_department_in_a_group_of_its_own()
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
        // A department nobody holds the position in, with a duty of its own.
        $design = Department::create(['name' => 'Отдел Дизайна', 'abbreviation' => 'ОД']);

        $analyst = Position::create(['name' => 'Аналитик']);
        $analyst->duties()->createMany([
            ['name' => 'Ведёт закреплённые за ним препараты', 'order' => 0],
            ['name' => 'Готовит отчёт по рынку', 'order' => 1],
            ['name' => 'Готовит ответы регулятору', 'department_id' => $regulation->id, 'order' => 0],
            ['name' => 'Проверяет документы на соответствие закону', 'department_id' => $regulation->id, 'order' => 1],
            ['name' => 'Сверяет цены по Европе', 'department_id' => $europe->id, 'order' => 0],
            ['name' => 'Рисует обложки отчётов', 'department_id' => $design->id, 'order' => 0],
        ]);

        $this->actingAs($this->mayLookAround($this->person(Directories::viewPermission('positions'))))
            ->get('/positions')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                // What the position answers for wherever it is held, and only
                // that.
                ->where('positions.0.duties', ['Ведёт закреплённые за ним препараты', 'Готовит отчёт по рынку'])
                // Then a group per department, each named the way every screen
                // but the directory names a department, in the order of that
                // name, and each read from the top of its own list. A group is
                // sent whether or not anybody holds the position there: somebody
                // wrote it down for that department.
                ->where('positions.0.department_duties', [
                    [
                        'department' => ['id' => $design->id, 'name' => 'ОД', 'full_name' => 'Отдел Дизайна'],
                        'duties' => ['Рисует обложки отчётов'],
                    ],
                    [
                        'department' => ['id' => $europe->id, 'name' => 'ОМФК Европа', 'full_name' => 'Отдел мониторинга Эволет Европы'],
                        'duties' => ['Сверяет цены по Европе'],
                    ],
                    [
                        'department' => ['id' => $regulation->id, 'name' => 'ОПР', 'full_name' => 'Отдел правового регулирования'],
                        'duties' => ['Готовит ответы регулятору', 'Проверяет документы на соответствие закону'],
                    ],
                ])
                // The editor picks the department from the whole tree, parents
                // before their own, indented by the depth the staff list's
                // filter is already indented by.
                ->where('options.departments', [
                    ['id' => $analytics->id, 'name' => 'ДАС', 'full_name' => 'Департамент аналитики и статистики', 'depth' => 0],
                    ['id' => $europe->id, 'name' => 'ОМФК Европа', 'full_name' => 'Отдел мониторинга Эволет Европы', 'depth' => 1],
                    ['id' => $regulation->id, 'name' => 'ОПР', 'full_name' => 'Отдел правового регулирования', 'depth' => 1],
                    ['id' => $design->id, 'name' => 'ОД', 'full_name' => 'Отдел Дизайна', 'depth' => 0],
                ])
            );
    }

    public function test_a_positions_page_names_its_holders_with_the_duties_of_all_they_hold()
    {
        $lawyer = Position::create(['name' => 'Юрист']);
        $contracts = $lawyer->duties()->create(['name' => 'Ведение договоров', 'order' => 0]);
        $translator = Position::create(['name' => 'Переводчик']);
        $papers = $translator->duties()->create(['name' => 'Перевод документов', 'order' => 0]);

        $both = User::factory()->create(['surname' => 'Азимова', 'name' => 'Нигина']);
        $both->positions()->attach([$lawyer->id, $translator->id]);
        $only = User::factory()->create(['surname' => 'Бобоев', 'name' => 'Фаррух']);
        $only->positions()->attach($lawyer);
        $fired = User::factory()->create(['surname' => 'Ашуров', 'name' => 'Далер', 'status' => 'fired']);
        $fired->positions()->attach($lawyer);

        $this->actingAs($this->mayLookAround($this->person(Directories::viewPermission('positions'))))
            ->get("/positions/{$lawyer->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('positions/show')
                ->where('position.name', 'Юрист')
                ->where('position.duties', [['id' => $contracts->id, 'name' => 'Ведение договоров']])
                ->where('position.department_duties', [])
                // Who still works here, by surname, named as every list names
                // somebody: "Фамилия Имя".
                ->where('employees', fn ($rows) => collect($rows)->pluck('name')->all() === ['Азимова Нигина', 'Бобоев Фаррух'])
                // Everything the person is responsible for, whichever position it
                // comes from — the positions as a card lists them, by name, and
                // the duties with them; the page picks this position's out by the
                // position a duty belongs to.
                ->where('employees.0.positions', [
                    ['id' => $translator->id, 'name' => 'Переводчик'],
                    ['id' => $lawyer->id, 'name' => 'Юрист'],
                ])
                // A duty that names no department is one the position carries
                // everywhere, and the page is told as much.
                ->where('employees.0.duties', [
                    ['id' => $papers->id, 'name' => 'Перевод документов', 'position_id' => $translator->id, 'department_id' => null],
                    ['id' => $contracts->id, 'name' => 'Ведение договоров', 'position_id' => $lawyer->id, 'department_id' => null],
                ])
                ->where('employees.1.duties', [
                    ['id' => $contracts->id, 'name' => 'Ведение договоров', 'position_id' => $lawyer->id, 'department_id' => null],
                ])
                ->where('canOpenCards', true)
                ->where('showsEmployees', true)
                ->where('showsPositions', true)
                ->where('canEdit', false)
            );
    }

    public function test_a_duty_of_a_department_is_carried_by_the_people_of_that_department_alone()
    {
        $regulation = Department::create(['name' => 'Отдел правового регулирования', 'abbreviation' => 'ОПР']);
        $europe = Department::create(['name' => 'Отдел мониторинга Эволет Европы', 'abbreviation' => 'ОМФК Европа']);
        // Nobody holds the position here, so what was written for this
        // department is nobody's.
        $design = Department::create(['name' => 'Отдел Дизайна', 'abbreviation' => 'ОД']);

        $analyst = Position::create(['name' => 'Аналитик']);
        $market = $analyst->duties()->create(['name' => 'Готовит отчёт по рынку', 'order' => 0]);
        $answers = $analyst->duties()->create([
            'name' => 'Готовит ответы регулятору',
            'department_id' => $regulation->id,
            'order' => 0,
        ]);
        $prices = $analyst->duties()->create([
            'name' => 'Сверяет цены по Европе',
            'department_id' => $europe->id,
            'order' => 0,
        ]);
        $covers = $analyst->duties()->create([
            'name' => 'Рисует обложки отчётов',
            'department_id' => $design->id,
            'order' => 0,
        ]);

        // One in both of the departments the position is written for, one in a
        // single one, and one in none at all.
        $both = User::factory()->create(['surname' => 'Азимова', 'name' => 'Нигина']);
        $both->positions()->attach($analyst);
        $both->departments()->attach([$regulation->id, $europe->id]);
        $one = User::factory()->create(['surname' => 'Бобоев', 'name' => 'Фаррух']);
        $one->positions()->attach($analyst);
        $one->departments()->attach($regulation);
        $none = User::factory()->create(['surname' => 'Валиев', 'name' => 'Сухроб']);
        $none->positions()->attach($analyst);

        $this->actingAs($this->mayLookAround($this->person(Directories::viewPermission('positions'))))
            ->get("/positions/{$analyst->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('position.duties', [['id' => $market->id, 'name' => 'Готовит отчёт по рынку']])
                ->where('position.department_duties', [
                    [
                        'department' => ['id' => $design->id, 'name' => 'ОД', 'full_name' => 'Отдел Дизайна'],
                        'duties' => [['id' => $covers->id, 'name' => 'Рисует обложки отчётов']],
                    ],
                    [
                        'department' => ['id' => $europe->id, 'name' => 'ОМФК Европа', 'full_name' => 'Отдел мониторинга Эволет Европы'],
                        'duties' => [['id' => $prices->id, 'name' => 'Сверяет цены по Европе']],
                    ],
                    [
                        'department' => ['id' => $regulation->id, 'name' => 'ОПР', 'full_name' => 'Отдел правового регулирования'],
                        'duties' => [['id' => $answers->id, 'name' => 'Готовит ответы регулятору']],
                    ],
                ])
                // Somebody in two of the departments the position is written for
                // carries both sets, on top of what it answers for everywhere.
                ->where('employees.0.duties', [
                    ['id' => $market->id, 'name' => 'Готовит отчёт по рынку', 'position_id' => $analyst->id, 'department_id' => null],
                    ['id' => $answers->id, 'name' => 'Готовит ответы регулятору', 'position_id' => $analyst->id, 'department_id' => $regulation->id],
                    ['id' => $prices->id, 'name' => 'Сверяет цены по Европе', 'position_id' => $analyst->id, 'department_id' => $europe->id],
                ])
                ->where('employees.1.duties', [
                    ['id' => $market->id, 'name' => 'Готовит отчёт по рынку', 'position_id' => $analyst->id, 'department_id' => null],
                    ['id' => $answers->id, 'name' => 'Готовит ответы регулятору', 'position_id' => $analyst->id, 'department_id' => $regulation->id],
                ])
                // And whoever is in none of them answers for what the position
                // answers for everywhere, and for nothing else.
                ->where('employees.2.duties', [
                    ['id' => $market->id, 'name' => 'Готовит отчёт по рынку', 'position_id' => $analyst->id, 'department_id' => null],
                ])
                // What was written for the design department reached nobody: no
                // holder of the position sits there.
                ->where('employees', fn ($rows) => collect($rows)
                    ->pluck('duties')
                    ->flatten(1)
                    ->doesntContain('id', $covers->id))
            );
    }

    public function test_the_holders_cost_the_same_few_queries_however_many_there_are()
    {
        $department = Department::create(['name' => 'Отдел правового регулирования', 'abbreviation' => 'ОПР']);
        $position = Position::create(['name' => 'Юрист']);
        $position->duties()->create(['name' => 'Ведение договоров', 'order' => 0]);
        $position->duties()->create(['name' => 'Арбитраж', 'department_id' => $department->id, 'order' => 0]);
        $reader = $this->mayLookAround($this->person(Directories::viewPermission('positions')));

        $count = function (int $holders) use ($position, $department, $reader) {
            User::factory($holders)->create()->each(function (User $u) use ($position, $department) {
                $u->positions()->attach($position);
                $u->departments()->attach($department);
            });

            return $this->reads(fn () => $this->actingAs($reader)->get("/positions/{$position->id}")->assertOk());
        };

        // A page of people is read with the relations rather than with a query
        // apiece, so five more holders — each with their own positions, duties
        // and departments — cost nothing more than the first one.
        $this->assertSame($count(1), $count(5));
    }

    public function test_the_list_costs_the_same_few_queries_however_many_positions_there_are()
    {
        $reader = $this->mayLookAround($this->person(Directories::viewPermission('positions')));

        $count = function (int $positions) use ($reader) {
            foreach (range(1, $positions) as $ignored) {
                $department = Department::create(['name' => 'Отдел '.Department::count()]);
                $position = Position::create(['name' => 'Должность '.Position::count()]);
                $position->duties()->create(['name' => 'Ведение договоров', 'order' => 0]);
                $position->duties()->create(['name' => 'Арбитраж', 'department_id' => $department->id, 'order' => 0]);
                User::factory(2)->create()->each(fn (User $u) => $u->positions()->attach($position));
            }

            return $this->reads(fn () => $this->actingAs($reader)->get('/positions')->assertOk());
        };

        // The positions, their duties, the departments a duty may name and the
        // people holding them are a query each for the whole table rather than
        // per row, so a company with seven positions reads the list in as many
        // queries as one with two.
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
                // Which departments somebody is in decides which duties are
                // theirs, and a group of duties is headed with a department's
                // name, so those tables are part of what a page costs.
                || str_contains($query, 'from departments')
                || str_contains($query, 'from department_user'))
            ->count();
        DB::disableQueryLog();

        return $reads;
    }

    public function test_reading_the_list_and_keeping_it_are_the_two_rights_it_has_always_had()
    {
        $position = Position::create(['name' => 'Юрист']);

        // Nobody signed in reads it at all.
        $this->get('/positions')->assertRedirect('/login');
        $this->get("/positions/{$position->id}")->assertRedirect('/login');

        // Neither right: the pages are shut and so is every form.
        $nobody = $this->person();
        $this->actingAs($nobody)->get('/positions')->assertForbidden();
        $this->actingAs($nobody)->get("/positions/{$position->id}")->assertForbidden();
        $this->actingAs($nobody)->post('/positions', ['name' => 'Курьер'])->assertForbidden();
        $this->actingAs($nobody)->put("/positions/{$position->id}", ['name' => 'Курьер'])->assertForbidden();
        $this->actingAs($nobody)->delete("/positions/{$position->id}")->assertForbidden();

        // Reading it is not changing it.
        $reader = $this->person(Directories::viewPermission('positions'));
        $this->actingAs($reader)->get('/positions')->assertOk();
        $this->actingAs($reader)->get("/positions/{$position->id}")->assertOk();
        $this->actingAs($reader)->post('/positions', ['name' => 'Курьер'])->assertForbidden();
        $this->assertSame(1, Position::count());

        // And changing it takes reading it as well: a list one cannot open is not
        // a list one adds to.
        $editor = $this->person(Directories::editPermission('positions'));
        $this->actingAs($editor)->get('/positions')->assertForbidden();
        $this->actingAs($editor)->post('/positions', ['name' => 'Курьер'])->assertForbidden();
        $this->assertSame(1, Position::count());

        $keeper = $this->keeper();
        $this->actingAs($keeper)->get('/positions')->assertInertia(fn (Assert $page) => $page->where('canEdit', true));
        $this->actingAs($keeper)->post('/positions', ['name' => 'Курьер'])->assertRedirect();
        $this->assertSame(2, Position::count());

        // Naming a department on a duty is part of keeping the position and asks
        // for nothing else: whoever keeps the list writes one, and whoever only
        // reads the list writes nothing at all.
        $department = Department::create(['name' => 'Отдел правового регулирования', 'abbreviation' => 'ОПР']);
        $duty = ['name' => 'Готовит ответы регулятору', 'department_id' => $department->id];

        $this->actingAs($reader)->put("/positions/{$position->id}", ['name' => 'Юрист', 'duties' => [$duty]])
            ->assertForbidden();
        $this->actingAs($keeper)->put("/positions/{$position->id}", ['name' => 'Юрист', 'duties' => [$duty]])
            ->assertRedirect();
        $this->assertSame($department->id, $position->fresh()->duties->first()->department_id);
    }

    public function test_a_position_is_added_renamed_and_deleted_with_its_duties()
    {
        $this->actingAs($this->keeper());

        // Trimmed, the blank lines dropped, and the order kept as sent.
        $this->post('/positions', ['name' => 'Юрист', 'duties' => [
            ['name' => '  Ведение договоров ', 'department_id' => null],
            ['name' => '', 'department_id' => null],
            ['name' => 'Арбитраж', 'department_id' => null],
        ]])->assertSessionHasNoErrors();
        $position = Position::firstWhere('name', 'Юрист');
        $this->assertSame(['Ведение договоров', 'Арбитраж'], $position->duties->pluck('name')->all());
        $this->assertSame([0, 1], $position->duties->pluck('order')->all());
        $this->assertSame([null, null], $position->duties->pluck('department_id')->all());

        // Saved again, the list replaces what was there rather than adding to it.
        $this->put("/positions/{$position->id}", ['name' => 'Юрисконсульт', 'duties' => [
            ['name' => 'Арбитраж', 'department_id' => null],
            ['name' => 'Претензии', 'department_id' => null],
        ]])->assertSessionHasNoErrors();
        $this->assertSame('Юрисконсульт', $position->fresh()->name);
        $this->assertSame(['Арбитраж', 'Претензии'], $position->fresh()->duties->pluck('name')->all());
        $this->assertSame(2, DB::table('position_duties')->count());

        // A rename that says nothing about them leaves them be; an empty list is
        // what clears them.
        $this->put("/positions/{$position->id}", ['name' => 'Юрист'])->assertSessionHasNoErrors();
        $this->assertCount(2, $position->fresh()->duties);
        $this->put("/positions/{$position->id}", ['name' => 'Юрист', 'duties' => []])->assertSessionHasNoErrors();
        $this->assertCount(0, $position->fresh()->duties);

        $this->post('/positions', ['name' => 'Юрист'])->assertSessionHasErrors('name');
        $this->post('/positions', ['name' => ''])->assertSessionHasErrors('name');
        $this->post('/positions', ['name' => 'Курьер', 'duties' => [['name' => str_repeat('а', 301)]]])
            ->assertSessionHasErrors('duties.0.name');
        // Not a duty as the form writes one.
        $this->post('/positions', ['name' => 'Курьер', 'duties' => ['Ведение договоров']])
            ->assertSessionHasErrors('duties.0');
        // And a department that is not on file is not one a duty may name.
        $this->post('/positions', ['name' => 'Курьер', 'duties' => [['name' => 'Арбитраж', 'department_id' => 9999]]])
            ->assertSessionHasErrors('duties.0.department_id');
        $this->assertNull(Position::firstWhere('name', 'Курьер'));

        // The duties go with the position, and its holders keep their others.
        $employee = User::factory()->create();
        $employee->positions()->attach($position);
        $this->put("/positions/{$position->id}", ['name' => 'Юрист', 'duties' => [['name' => 'Арбитраж']]])
            ->assertSessionHasNoErrors();

        $this->delete("/positions/{$position->id}")->assertSessionHasNoErrors();
        $this->assertNull(Position::find($position->id));
        $this->assertSame(0, DB::table('position_duties')->count());
        $this->assertCount(0, $employee->fresh()->positions);
    }

    public function test_the_duties_of_a_department_are_saved_in_the_one_list_and_numbered_group_by_group()
    {
        $this->actingAs($this->keeper());

        $regulation = Department::create(['name' => 'Отдел правового регулирования', 'abbreviation' => 'ОПР']);
        $europe = Department::create(['name' => 'Отдел мониторинга Эволет Европы', 'abbreviation' => 'ОМФК Европа']);

        // The form hands back one flat list, mixed as somebody typed it, and a
        // <select> writes its choice as a string — "во всех отделах" as nothing
        // at all.
        $this->post('/positions', ['name' => 'Аналитик', 'duties' => [
            ['name' => 'Готовит отчёт по рынку', 'department_id' => null],
            ['name' => '  Готовит ответы регулятору ', 'department_id' => (string) $regulation->id],
            ['name' => 'Ведёт закреплённые за ним препараты', 'department_id' => ''],
            ['name' => 'Проверяет документы на соответствие закону', 'department_id' => $regulation->id],
            ['name' => 'Сверяет цены по Европе', 'department_id' => $europe->id],
        ]])->assertSessionHasNoErrors();

        // Each group is numbered from the top — the common duties are a group
        // and so is every department — and each is read in the order it was
        // typed in.
        $position = Position::firstWhere('name', 'Аналитик');
        $this->assertSame([
            ['Готовит отчёт по рынку', null, 0],
            ['Ведёт закреплённые за ним препараты', null, 1],
            ['Готовит ответы регулятору', $regulation->id, 0],
            ['Проверяет документы на соответствие закону', $regulation->id, 1],
            ['Сверяет цены по Европе', $europe->id, 0],
        ], $position->duties->map(fn ($duty) => [$duty->name, $duty->department_id, $duty->order])->all());

        // Saved again, the list replaces the lot: a group the form no longer
        // shows is one somebody emptied.
        $this->put("/positions/{$position->id}", ['name' => 'Аналитик', 'duties' => [
            ['name' => 'Сверяет цены по Европе', 'department_id' => $europe->id],
            ['name' => 'Готовит отчёт по рынку', 'department_id' => null],
        ]])->assertSessionHasNoErrors();

        $this->assertSame([
            ['Готовит отчёт по рынку', null, 0],
            ['Сверяет цены по Европе', $europe->id, 0],
        ], $position->fresh()->duties->map(fn ($duty) => [$duty->name, $duty->department_id, $duty->order])->all());

        // And an empty list clears both kinds together.
        $this->put("/positions/{$position->id}", ['name' => 'Аналитик', 'duties' => []])->assertSessionHasNoErrors();
        $this->assertCount(0, $position->fresh()->duties);
    }

    public function test_the_holders_are_a_line_of_a_card_and_are_shown_as_such()
    {
        $position = Position::create(['name' => 'Юрист']);
        $position->duties()->create(['name' => 'Ведение договоров', 'order' => 0]);
        User::factory()->create(['surname' => 'Азимова', 'name' => 'Нигина'])->positions()->attach($position);

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

        // The list names the holders of a row on the same right, and whoever may
        // not read the staff list is left the count: the number is the one thing
        // everybody who reads the section is told.
        $this->actingAs($this->person(Directories::viewPermission('positions')))
            ->get('/positions')
            ->assertInertia(fn (Assert $page) => $page
                ->where('showsEmployees', false)
                ->where('positions.0.employees', [])
                ->where('positions.0.employees_count', 1)
            );

        $this->actingAs($this->person(Directories::viewPermission('positions'), 'employees.view'))
            ->get('/positions')
            ->assertInertia(fn (Assert $page) => $page
                ->where('showsEmployees', true)
                ->where('positions.0.employees.0.name', 'Азимова Нигина')
                ->where('positions.0.employees_count', 1)
            );

        // The count on the list leads to the staff list narrowed by the position,
        // so the link takes the list and that line of a card together: with one of
        // the two the number is a link into a refusal.
        $this->actingAs($this->person(Directories::viewPermission('positions')))
            ->get('/positions')
            ->assertInertia(fn (Assert $page) => $page->where('canSeeEmployees', false));

        $this->actingAs($this->person(Directories::viewPermission('positions'), 'employees.field.positions'))
            ->get('/positions')
            ->assertInertia(fn (Assert $page) => $page->where('canSeeEmployees', false));

        $this->actingAs($this->person(Directories::viewPermission('positions'), 'employees.view'))
            ->get('/positions')
            ->assertInertia(fn (Assert $page) => $page->where('canSeeEmployees', false));

        $this->actingAs($this->person(Directories::viewPermission('positions'), 'employees.view', 'employees.field.positions'))
            ->get('/positions')
            ->assertInertia(fn (Assert $page) => $page->where('canSeeEmployees', true));
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
