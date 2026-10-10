<?php

namespace Tests\Feature;

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
 * both are still offered by the access table.
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
                ->where('positions.1.name', 'Юрист')
                ->where('positions.1.duties', ['Ведение договоров', 'Арбитраж'])
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
                ->where('employees.0.duties', [
                    ['id' => $papers->id, 'name' => 'Перевод документов', 'position_id' => $translator->id],
                    ['id' => $contracts->id, 'name' => 'Ведение договоров', 'position_id' => $lawyer->id],
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

    public function test_the_holders_cost_the_same_few_queries_however_many_there_are()
    {
        $position = Position::create(['name' => 'Юрист']);
        $position->duties()->create(['name' => 'Ведение договоров', 'order' => 0]);
        $reader = $this->mayLookAround($this->person(Directories::viewPermission('positions')));

        $count = function (int $holders) use ($position, $reader) {
            User::factory($holders)->create()->each(fn (User $u) => $u->positions()->attach($position));

            return $this->reads(fn () => $this->actingAs($reader)->get("/positions/{$position->id}")->assertOk());
        };

        // A page of people is read with the relations rather than with a query
        // apiece, so five more holders — each with their own positions and duties
        // — cost nothing more than the first one.
        $this->assertSame($count(1), $count(5));
    }

    public function test_the_list_costs_the_same_few_queries_however_many_positions_there_are()
    {
        $reader = $this->mayLookAround($this->person(Directories::viewPermission('positions')));

        $count = function (int $positions) use ($reader) {
            foreach (range(1, $positions) as $ignored) {
                $position = Position::create(['name' => 'Должность '.Position::count()]);
                $position->duties()->create(['name' => 'Ведение договоров', 'order' => 0]);
                User::factory(2)->create()->each(fn (User $u) => $u->positions()->attach($position));
            }

            return $this->reads(fn () => $this->actingAs($reader)->get('/positions')->assertOk());
        };

        // The positions, their duties and the people holding them are a query
        // each for the whole table rather than per row, so a company with seven
        // positions reads the list in as many queries as one with two.
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
                || str_contains($query, 'from position_duties'))
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
    }

    public function test_a_position_is_added_renamed_and_deleted_with_its_duties()
    {
        $this->actingAs($this->keeper());

        // Trimmed, the blank lines dropped, and the order kept as sent.
        $this->post('/positions', ['name' => 'Юрист', 'duties' => ['  Ведение договоров ', '', 'Арбитраж']])
            ->assertSessionHasNoErrors();
        $position = Position::firstWhere('name', 'Юрист');
        $this->assertSame(['Ведение договоров', 'Арбитраж'], $position->duties->pluck('name')->all());
        $this->assertSame([0, 1], $position->duties->pluck('order')->all());

        // Saved again, the list replaces what was there rather than adding to it.
        $this->put("/positions/{$position->id}", ['name' => 'Юрисконсульт', 'duties' => ['Арбитраж', 'Претензии']])
            ->assertSessionHasNoErrors();
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
        $this->post('/positions', ['name' => 'Курьер', 'duties' => [str_repeat('а', 301)]])->assertSessionHasErrors('duties.0');
        $this->assertNull(Position::firstWhere('name', 'Курьер'));

        // The duties go with the position, and its holders keep their others.
        $employee = User::factory()->create();
        $employee->positions()->attach($position);
        $this->put("/positions/{$position->id}", ['name' => 'Юрист', 'duties' => ['Арбитраж']])->assertSessionHasNoErrors();

        $this->delete("/positions/{$position->id}")->assertSessionHasNoErrors();
        $this->assertNull(Position::find($position->id));
        $this->assertSame(0, DB::table('position_duties')->count());
        $this->assertCount(0, $employee->fresh()->positions);
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
