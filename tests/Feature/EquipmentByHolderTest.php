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

        $this->actingAs($viewer)->get('/equipment?view=holders'.($query === '' ? '' : "&{$query}"))
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
            ->get('/equipment?view=holders')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('equipment/index')
                ->where('view', 'holders')
                // Three units, one row: the point of the table.
                ->has('holders.data', 1)
                ->where('holders.data.0.id', $holder->id)
                ->where('holders.data.0.name', 'Абдуллоев Фарход')
                // Keyed by the category, which is the column the cell is drawn
                // in, and read by name inside it as the list reads them.
                ->where('holders.data.0.units.'.$this->type()->id, [
                    ['id' => $spare->id, 'name' => 'Ноутбук Acer Aspire 5', 'inventory_number' => 'EV-0002'],
                    ['id' => $laptop->id, 'name' => 'Ноутбук Dell Latitude 5440', 'inventory_number' => 'EV-0001'],
                ])
                ->where('holders.data.0.units.'.$this->type('Мониторы')->id, [
                    ['id' => $monitor->id, 'name' => 'Монитор Dell P2422H', 'inventory_number' => 'EV-0003'],
                ])
                // A category they hold nothing of is absent rather than empty.
                ->missing('holders.data.0.units.'.$this->type('Телефоны')->id)
                // The columns are the categories, in the order they always come in.
                ->has('options.types', EquipmentType::count())
                // The units themselves are the other table's answer, not this one's.
                ->missing('equipment')
                // Only the name sorts here; the rest of the headers are categories.
                ->where('sortable', ['holder'])
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

    public function test_the_table_is_paged_by_colleague()
    {
        $busy = User::factory()->create(['surname' => 'Абдуллоев', 'name' => 'Фарход']);
        Equipment::factory(3)->ofType($this->type())->issuedTo($busy->id)->create();

        // Twenty-five others, one unit each: a page of this table is twenty-five
        // colleagues, whatever the units behind them add up to.
        User::factory(25)->create()->each(fn (User $u) => Equipment::factory()->ofType($this->type())->issuedTo($u->id)->create());

        $admin = $this->sysadmin();

        $this->actingAs($admin)->get('/equipment?view=holders&per_page=25')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('holders.total', 26)
                ->where('holders.per_page', 25)
                ->has('holders.data', 25)
                ->where('perPage', 25)
            );

        $this->actingAs($admin)->get('/equipment?view=holders&per_page=25&page=2')
            ->assertInertia(fn (AssertableInertia $page) => $page->has('holders.data', 1));

        // Twenty-eight units in all, and the paging never counted them.
        $this->assertSame(28, Equipment::count());
    }

    public function test_the_table_opens_on_the_last_handover_and_sorts_by_the_name()
    {
        $first = User::factory()->create(['surname' => 'Азимова', 'name' => 'Нигина']);
        $last = User::factory()->create(['surname' => 'Зокиров', 'name' => 'Бахтиёр']);

        Equipment::factory()->ofType($this->type())->issuedTo($first->id)->create(['issued_at' => '2026-01-05']);
        Equipment::factory()->ofType($this->type())->issuedTo($last->id)->create(['issued_at' => '2026-09-20']);

        $admin = $this->sysadmin();

        // Opened: whoever was handed something last, under a key of its own, so
        // the page can tell the opening order from a sorted column.
        $this->actingAs($admin)->get('/equipment?view=holders')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('sort.key', 'issued')
                ->where('sort.direction', 'desc')
                ->where('holders.data.0.id', $last->id)
            );

        // The name, up and then down — the only header that sorts here.
        $this->actingAs($admin)->get('/equipment?view=holders&sort=holder')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('sort.key', 'holder')
                ->where('sort.direction', 'asc')
                ->where('holders.data.0.id', $first->id)
            );

        $this->actingAs($admin)->get('/equipment?view=holders&sort=holder&direction=desc')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('holders.data.0.id', $last->id));

        // A category is not a column one sorts by, and neither is anything the
        // other table offers: it is refused rather than quietly ignored.
        $this->actingAs($admin)->get('/equipment?view=holders&sort=name')->assertSessionHasErrors('sort');
        $this->actingAs($admin)->get('/equipment?view=holders&sort=status')->assertSessionHasErrors('sort');
        // And a view nobody offers is not a view.
        $this->actingAs($admin)->get('/equipment?view=cards')->assertSessionHasErrors('view');
    }

    public function test_a_page_of_colleagues_costs_the_same_few_queries_however_many_there_are()
    {
        $admin = $this->sysadmin();

        $count = function (int $holders) use ($admin) {
            User::factory($holders)->create()->each(function (User $holder) {
                Equipment::factory()->ofType($this->type())->issuedTo($holder->id)->create();
                Equipment::factory()->ofType($this->type('Мониторы'))->issuedTo($holder->id)->create();
            });

            return $this->reads(fn () => $this->actingAs($admin)->get('/equipment?view=holders')->assertOk());
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
