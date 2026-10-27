<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Equipment;
use App\Models\EquipmentType;
use App\Models\User;
use Database\Seeders\EquipmentTypeSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * The equipment page read the other way round: one row per colleague, one
 * column per category, and in each cell what that colleague holds of it.
 *
 * The same table of units answers both views; this one asks who has what, so a
 * name is printed once, a unit nobody holds has no place in it, and neither a
 * status nor the day a unit changed hands narrows anything.
 */
class EquipmentByHolderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RoleSeeder::class, PermissionSeeder::class, EquipmentTypeSeeder::class]);
    }

    /** The account every check lets through: these tests are not about rights. */
    private function sysadmin(): User
    {
        return User::factory()->create()->assignRole('sysadmin');
    }

    private function type(string $name = 'Ноутбуки'): EquipmentType
    {
        return EquipmentType::firstWhere('name', $name);
    }

    /** Somebody with exactly these rights and no others. */
    private function person(array $rights = []): User
    {
        $user = User::factory()->create();

        return $rights === [] ? $user : $user->givePermissionTo($rights);
    }

    /** The rows of the second table, as the page is given them. */
    private function rows(User $viewer, string $query = ''): array
    {
        $rows = [];

        $this->actingAs($viewer)->get('/inventory/equipment?view=holders'.($query === '' ? '' : "&{$query}"))
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use (&$rows) {
                $rows = $page->toArray()['props']['holders']['data'];
            });

        return $rows;
    }

    public function test_a_colleague_is_named_once_with_their_units_under_their_categories()
    {
        $holder = User::factory()->create(['surname' => 'Абдуллоев', 'name' => 'Фарход']);

        $laptop = Equipment::factory()->ofType($this->type())->issuedTo($holder->id)
            ->create(['name' => 'Ноутбук Dell Latitude 5440', 'inventory_number' => 'EV-0001']);
        // Two of the same category, so the cell holds a list rather than a value.
        $spare = Equipment::factory()->ofType($this->type())->issuedTo($holder->id)
            ->create(['name' => 'Ноутбук Acer Aspire 5', 'inventory_number' => 'EV-0002']);
        $monitor = Equipment::factory()->ofType($this->type('Мониторы'))->issuedTo($holder->id)
            ->create(['name' => 'Монитор Dell P2422H', 'inventory_number' => 'EV-0003']);

        $this->actingAs($this->sysadmin())
            ->get('/inventory/equipment?view=holders')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('equipment/index')
                ->where('view', 'holders')
                // Three units, one row: the point of the table.
                ->has('holders.data', 1)
                ->where('holders.data.0.id', $holder->id)
                ->where('holders.data.0.name', 'Абдуллоев Фарход')
                // Keyed by the category, which is the column the cell is drawn
                // in, and read by name inside it as the list reads them. A unit
                // says of itself what it says in the other table: its name, the
                // first couple of things its category asks about, and the
                // number on its sticker.
                ->where('holders.data.0.units.'.$this->type()->id, [
                    ['id' => $spare->id, 'name' => 'Ноутбук Acer Aspire 5', 'details' => 'Acer · Aspire 5', 'inventory_number' => 'EV-0002'],
                    ['id' => $laptop->id, 'name' => 'Ноутбук Dell Latitude 5440', 'details' => 'Dell · Latitude 5440', 'inventory_number' => 'EV-0001'],
                ])
                ->where('holders.data.0.units.'.$this->type('Мониторы')->id, [
                    ['id' => $monitor->id, 'name' => 'Монитор Dell P2422H', 'details' => 'Dell · P2422H', 'inventory_number' => 'EV-0003'],
                ])
                // A category they hold nothing of is absent rather than empty.
                ->missing('holders.data.0.units.'.$this->type('Телефоны')->id)
                // The columns are the categories, in the order they always come in.
                ->has('options.types', EquipmentType::count())
                // The units themselves are the other table's answer, not this one's.
                ->missing('equipment')
                // Where they stand and what they are called sort here; the rest
                // of the headers are categories, and a category holds a list.
                ->where('sortable', ['department', 'unit', 'holder'])
            );
    }

    public function test_whoever_holds_nothing_is_absent_and_so_is_a_unit_nobody_holds()
    {
        $holder = User::factory()->create(['surname' => 'Азимова', 'name' => 'Нигина']);
        Equipment::factory()->ofType($this->type())->issuedTo($holder->id)->create(['name' => 'Ноутбук A']);

        // On the balance sheet and written off: units with nobody behind them.
        Equipment::factory()->ofType($this->type())->create(['name' => 'Ноутбук на складе']);
        Equipment::factory()->ofType($this->type())->writtenOff()->create(['name' => 'Списанный ноутбук']);
        // And a colleague who has never been handed anything.
        User::factory()->create(['surname' => 'Зокиров', 'name' => 'Бахтиёр']);

        $rows = $this->rows($this->sysadmin());

        $this->assertSame(['Азимова Нигина'], array_column($rows, 'name'));
        $this->assertSame(['Ноутбук A'], array_column($rows[0]['units'][$this->type()->id], 'name'));
    }

    public function test_the_search_and_the_holder_filter_narrow_it_and_empty_rows_drop_out()
    {
        $laptops = User::factory()->create(['surname' => 'Абдуллоев', 'name' => 'Фарход']);
        $phones = User::factory()->create(['surname' => 'Зокиров', 'name' => 'Бахтиёр']);

        Equipment::factory()->ofType($this->type())->issuedTo($laptops->id)
            ->create(['name' => 'Ноутбук Dell Latitude 5440', 'inventory_number' => 'EV-0001']);
        Equipment::factory()->ofType($this->type('Телефоны'))->issuedTo($phones->id)
            ->create(['name' => 'Телефон Samsung Galaxy A54', 'inventory_number' => 'EV-0002']);

        $admin = $this->sysadmin();

        $names = fn (string $query) => array_column($this->rows($admin, $query), 'name');

        // The toolbar box, over what a unit is called and the sticker on it: the
        // colleague left with nothing is not a row of blanks, but no row at all.
        $this->assertSame(['Абдуллоев Фарход'], $names('q='.urlencode('Latitude')));
        $this->assertSame(['Зокиров Бахтиёр'], $names('q=EV-0002'));
        // And over the name of whoever holds it, as it does in the list.
        $this->assertSame(['Зокиров Бахтиёр'], $names('q='.urlencode('Бахтиёр')));

        // The column filters that speak of a unit.
        $this->assertSame(['Абдуллоев Фарход'], $names('name='.urlencode('Ноутбук')));
        $this->assertSame(['Абдуллоев Фарход'], $names('inventory_number=EV-0001'));
        $this->assertSame(['Зокиров Бахтиёр'], $names('type[]='.$this->type('Телефоны')->id));

        // "У кого", typed in as it is in the list.
        $this->assertSame(['Абдуллоев Фарход'], $names('holder='.urlencode('Абдулло')));
        $this->assertSame(['Абдуллоев Фарход'], $names('holder='.urlencode('Абдуллоев Фарход')));

        // Nothing matches: the table is empty rather than whole.
        $this->assertSame([], $names('q='.urlencode('Проектор')));
    }

    /**
     * The table opens on where each colleague stands, so that the fleet can be
     * read department by department here too: a row is a person and everything
     * against their name, so a department's cell covers its people with all
     * their equipment beside them.
     *
     * Every row of this table is held by somebody, so it has only one kind of
     * nothing — a colleague nobody has filed in a department — and that one
     * reads last, as a column's missing value does in the list.
     */
    public function test_each_colleague_is_read_under_the_department_they_stand_in()
    {
        $analysis = Department::create(['name' => 'Департамент анализа и стратегии', 'abbreviation' => 'ДАС']);
        $analytics = Department::create(['name' => 'Отдел аналитики', 'abbreviation' => 'ОА', 'parent_id' => $analysis->id]);
        $staff = Department::create(['name' => 'Департамент по управлению персоналом', 'abbreviation' => 'ДУЧР']);

        $standing = function (string $surname, ?Department $department) {
            $holder = User::factory()->create(['surname' => $surname, 'name' => 'Фарход']);
            $department === null || $holder->departments()->attach($department->id);
            Equipment::factory()->ofType($this->type())->issuedTo($holder->id)->create();

            return $holder;
        };

        $inUnit = $standing('Бобоев', $analytics);
        $inDepartment = $standing('Олимова', $staff);
        $unfiled = $standing('Мирзоев', null);

        $admin = $this->sysadmin();
        $rows = collect($this->rows($admin, 'sort=department'));

        // Both steps of the path on the row of somebody standing in a unit, and
        // the unit's own column empty — nothing above it — for somebody filed
        // on a department itself.
        $this->assertSame([
            'id' => $analytics->id,
            'name' => 'ОА',
            'full_name' => 'Отдел аналитики',
            'parent' => ['id' => $analysis->id, 'name' => 'ДАС', 'full_name' => 'Департамент анализа и стратегии'],
        ], $rows->firstWhere('id', $inUnit->id)['department']);

        $this->assertNull($rows->firstWhere('id', $inDepartment->id)['department']['parent']);
        $this->assertNull($rows->firstWhere('id', $unfiled->id)['department']);

        // ДАС, then ДУЧР, then the colleague nobody has filed — and the other
        // way round, with that one still last.
        $this->assertSame([$inUnit->id, $inDepartment->id, $unfiled->id], $rows->pluck('id')->all());
        $this->assertSame(
            [$inDepartment->id, $inUnit->id, $unfiled->id],
            array_column($this->rows($admin, 'sort=department&direction=desc'), 'id'),
        );

        // By the unit, the one person standing in one reads first, and both the
        // colleague filed a step short of a unit and the unfiled one follow.
        $this->assertSame(
            [$inUnit->id, $inDepartment->id, $unfiled->id],
            array_column($this->rows($admin, 'sort=unit'), 'id'),
        );

        // And the funnels narrow this table as they narrow the list: a
        // department asks about its units too.
        $this->assertSame([$inUnit->id], array_column($this->rows($admin, "department[]={$analysis->id}"), 'id'));
        $this->assertSame([$inUnit->id], array_column($this->rows($admin, "unit[]={$analytics->id}"), 'id'));
        $this->assertSame([$inDepartment->id], array_column($this->rows($admin, "department[]={$staff->id}"), 'id'));
    }

    public function test_the_status_and_the_dates_of_issue_make_no_difference_here()
    {
        $holder = User::factory()->create(['surname' => 'Азимова', 'name' => 'Нигина']);
        Equipment::factory()->ofType($this->type())->issuedTo($holder->id)
            ->create(['name' => 'Ноутбук A', 'issued_at' => '2026-01-05']);

        $admin = $this->sysadmin();

        // What the list reads as a tab, a status or a date is read past here:
        // this table is about what people hold, and a query carrying any of
        // them from the other view answers the same way rather than refusing.
        foreach ([
            'tab=stock',
            'tab=written_off',
            'tab=service',
            'status[]=stock',
            'status[]=written_off',
            'issued_from=2026-06-01',
            'issued_to=2025-01-01',
        ] as $query) {
            $this->assertSame(['Азимова Нигина'], array_column($this->rows($admin, $query), 'name'), $query);
        }
    }

    public function test_a_narrow_viewer_sees_their_own_slice_and_no_sign_of_anybody_elses()
    {
        $viewer = $this->person(['equipment.view.own']);
        $mine = Equipment::factory()->ofType($this->type())->issuedTo($viewer->id)->create(['name' => 'Мой ноутбук']);
        $stranger = User::factory()->create(['surname' => 'Зокиров', 'name' => 'Бахтиёр']);
        Equipment::factory()->ofType($this->type())->issuedTo($stranger->id)->create(['name' => 'Чужой ноутбук']);

        $rows = $this->rows($viewer);

        $this->assertSame([$viewer->id], array_column($rows, 'id'));
        $this->assertSame(['Мой ноутбук'], array_column($rows[0]['units'][$this->type()->id], 'name'));
        // Not a name, not an id, not a count: the stranger is nowhere on the page.
        $this->assertStringNotContainsString('Зокиров', json_encode($rows, JSON_UNESCAPED_UNICODE));

        // A head of department reads their people the same way they read the list.
        $logistics = Department::create(['name' => 'Логистика']);
        $head = $this->person(['equipment.view.department']);
        $head->departments()->attach($logistics->id, ['is_head' => true]);
        $stranger->departments()->attach($logistics->id);

        $this->assertSame([$stranger->id], array_column($this->rows($head), 'id'));

        // And whoever heads nothing is shown nothing rather than everything.
        $nobodys = $this->person(['equipment.view.department']);
        $this->assertSame([], $this->rows($nobodys));
        $this->assertNotNull($mine->fresh());
    }

    public function test_every_page_of_the_section_reads_its_own_part_of_the_stock()
    {
        $holder = User::factory()->create(['surname' => 'Азимов', 'name' => 'Далер']);

        // A category says which part of the stock it is of, and that is what
        // puts its units on one page of «Инвентаризация» rather than another.
        $parts = [
            'equipment' => $this->type(),
            'furniture' => EquipmentType::factory()->create(['name' => 'Столы офисные', 'section' => 'furniture']),
            'office' => EquipmentType::factory()->create(['name' => 'Принтеры офисные', 'section' => 'office']),
        ];

        foreach ($parts as $type) {
            Equipment::factory()->ofType($type)->issuedTo($holder->id)->create();
        }

        foreach (['/inventory/equipment' => 'equipment', '/inventory/furniture' => 'furniture', '/inventory/office' => 'office'] as $url => $section) {
            $this->actingAs($this->sysadmin())->get($url)
                ->assertInertia(fn (AssertableInertia $page) => $page
                    ->where('section', $section)
                    ->where('view', 'holders')
                    ->has('holders.data', 1)
                    // The funnel offers the categories of this part and no
                    // others: a desk is not asked about under the laptops.
                    ->where('options.types', function ($types) use ($parts, $section) {
                        $offered = collect($types)->pluck('name');
                        $elsewhere = collect($parts)->except($section)->map(fn ($type) => $type->name);

                        return $offered->contains($parts[$section]->name) && $elsewhere->every(fn ($name) => ! $offered->contains($name));
                    })
                );
        }

        // «Обзор» reads the lot: three categories, and the one person holding
        // something of each.
        $this->actingAs($this->sysadmin())->get('/inventory')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('view', 'list')
                ->has('equipment.data', 3)
                ->where('options.types', fn ($types) => collect($types)->pluck('name')->contains('Столы офисные'))
            );

        // And they are open to exactly whoever may read some part of the
        // fleet: somebody who may read none of it is refused all three.
        $nobody = User::factory()->create();

        foreach (['/inventory/equipment', '/inventory/furniture', '/inventory/office'] as $url) {
            $this->actingAs($nobody)->get($url)->assertForbidden();
        }
    }

    public function test_the_table_is_paged_by_colleague()
    {
        $busy = User::factory()->create(['surname' => 'Абдуллоев', 'name' => 'Фарход']);
        Equipment::factory(3)->ofType($this->type())->issuedTo($busy->id)->create();

        // Twenty-five others, one unit each: a page of this table is twenty-five
        // colleagues, whatever the units behind them add up to.
        User::factory(25)->create()->each(fn (User $u) => Equipment::factory()->ofType($this->type())->issuedTo($u->id)->create());

        $admin = $this->sysadmin();

        $this->actingAs($admin)->get('/inventory/equipment?view=holders&per_page=25')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('holders.total', 26)
                ->where('holders.per_page', 25)
                ->has('holders.data', 25)
                ->where('perPage', 25)
            );

        $this->actingAs($admin)->get('/inventory/equipment?view=holders&per_page=25&page=2')
            ->assertInertia(fn (AssertableInertia $page) => $page->has('holders.data', 1));

        // Twenty-eight units in all, and the paging never counted them.
        $this->assertSame(28, Equipment::count());
    }

    public function test_the_table_opens_on_the_structure_and_sorts_by_the_name()
    {
        $first = User::factory()->create(['surname' => 'Азимова', 'name' => 'Нигина']);
        $last = User::factory()->create(['surname' => 'Зокиров', 'name' => 'Бахтиёр']);

        Equipment::factory()->ofType($this->type())->issuedTo($first->id)->create(['issued_at' => '2026-01-05']);
        Equipment::factory()->ofType($this->type())->issuedTo($last->id)->create(['issued_at' => '2026-09-20']);

        $admin = $this->sysadmin();

        // Opened: the structure read down, under a key of its own, so the page
        // can tell the opening order from a sorted column. Neither of these two
        // stands in a department, so what is left of that order is the name.
        $this->actingAs($admin)->get('/inventory/equipment?view=holders')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('sort.key', 'structure')
                ->where('sort.direction', 'asc')
                ->where('holders.data.0.id', $first->id)
            );

        // The order this table opened on until the structure took its place
        // still answers, so a link or a remembered query carrying it is served.
        $this->actingAs($admin)->get('/inventory/equipment?view=holders&sort=issued&direction=desc')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('holders.data.0.id', $last->id));

        // The name, up and then down — one of the three headers that sort here.
        $this->actingAs($admin)->get('/inventory/equipment?view=holders&sort=holder')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('sort.key', 'holder')
                ->where('sort.direction', 'asc')
                ->where('holders.data.0.id', $first->id)
            );

        $this->actingAs($admin)->get('/inventory/equipment?view=holders&sort=holder&direction=desc')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('holders.data.0.id', $last->id));

        // A category is not a column one sorts by, and neither is anything the
        // other table offers: it is refused rather than quietly ignored.
        $this->actingAs($admin)->get('/inventory/equipment?view=holders&sort=name')->assertSessionHasErrors('sort');
        $this->actingAs($admin)->get('/inventory/equipment?view=holders&sort=status')->assertSessionHasErrors('sort');
        // And a view nobody offers is not a view.
        $this->actingAs($admin)->get('/inventory/equipment?view=cards')->assertSessionHasErrors('view');
    }

    public function test_a_page_of_colleagues_costs_the_same_few_queries_however_many_there_are()
    {
        $admin = $this->sysadmin();

        $count = function (int $holders) use ($admin) {
            User::factory($holders)->create()->each(function (User $holder) {
                Equipment::factory()->ofType($this->type())->issuedTo($holder->id)->create();
                Equipment::factory()->ofType($this->type('Мониторы'))->issuedTo($holder->id)->create();
            });

            return $this->reads(fn () => $this->actingAs($admin)->get('/inventory/equipment?view=holders')->assertOk());
        };

        // The units of the whole page are read in one go and grouped in PHP, so
        // ten colleagues with their categories cost what one of them costs.
        $this->assertSame($count(1), $count(9));
    }

    /**
     * How many queries a visit spends reading the fleet and the people holding
     * it. What the rights cost to look up is another matter and is not counted.
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
            ->filter(fn (string $query) => str_contains($query, 'from equipment')
                || str_contains($query, 'from users'))
            ->count();
        DB::disableQueryLog();

        return $reads;
    }
}
