<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Equipment;
use App\Models\EquipmentEvent;
use App\Models\EquipmentType;
use App\Models\User;
use App\Support\EquipmentAccess;
use Database\Seeders\EquipmentTypeSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * How much of the fleet a person sees.
 *
 * The section answers three different questions depending on who asks: what is
 * on my own desk, what my people hold, and what the company owns. Each answer
 * is a right of its own, and each carries a second one — whether the journal of
 * those units is open too. Everything here checks the server: the list, the
 * tabs, the card, the journal and the search all narrow themselves, so nothing
 * depends on a button being hidden.
 */
class EquipmentAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RoleSeeder::class, PermissionSeeder::class, EquipmentTypeSeeder::class]);
    }

    /** Somebody with exactly these rights and no others. */
    private function person(array $rights = []): User
    {
        $user = User::factory()->create();

        return $rights === [] ? $user : $user->givePermissionTo($rights);
    }

    private function type(string $name = 'Ноутбуки'): EquipmentType
    {
        return EquipmentType::firstWhere('name', $name);
    }

    private function unitOf(?User $holder = null): Equipment
    {
        $unit = Equipment::factory()->ofType($this->type());

        return ($holder === null ? $unit : $unit->issuedTo($holder->id))->create();
    }

    /** An entry about a unit, without going through the pages that write them. */
    private function entry(Equipment $unit, User $actor): EquipmentEvent
    {
        return EquipmentEvent::create([
            'equipment_id' => $unit->id,
            'user_id' => $actor->id,
            'kind' => 'updated',
            'note' => "Запись о «{$unit->name}»",
        ]);
    }

    /** The names the list page actually shows this person. */
    private function listed(User $viewer): array
    {
        $names = [];

        $this->actingAs($viewer)->get('/equipment?tab=all')->assertInertia(function (AssertableInertia $page) use (&$names) {
            $names = collect($page->toArray()['props']['equipment']['data'])->pluck('name')->all();
        });

        return $names;
    }

    /**
     * Which units the journal page talks about. Putting a unit on the books is
     * itself an entry, so the count of entries is not the point — whose units
     * they are is.
     *
     * @return list<int>
     */
    private function journalUnits(User $viewer): array
    {
        $ids = [];

        $this->actingAs($viewer)->get('/equipment/journal')->assertInertia(function (AssertableInertia $page) use (&$ids) {
            $ids = collect($page->toArray()['props']['events']['data'])->pluck('unit.id')->unique()->sort()->values()->all();
        });

        return $ids;
    }

    public function test_without_a_part_of_the_fleet_the_section_is_shut()
    {
        $nobody = $this->person();

        $this->actingAs($nobody)->get('/equipment')->assertForbidden();
        $this->actingAs($nobody)->get('/equipment/journal')->assertForbidden();
        $this->actingAs($nobody)->get('/equipment/'.$this->unitOf()->id)->assertForbidden();
    }

    public function test_own_units_are_the_ones_that_are_listed()
    {
        $viewer = $this->person(['equipment.view.own']);
        $mine = $this->unitOf($viewer);
        $theirs = $this->unitOf(User::factory()->create());
        $stock = $this->unitOf();

        $this->actingAs($viewer)
            ->get('/equipment?view=list')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('equipment.total', 1)
                ->where('scopes', ['own'])
                ->where('journalScopes', [])
            );

        $this->assertSame([$mine->name], $this->listed($viewer));

        // The card follows the list: what is not on it does not open.
        $this->actingAs($viewer)->get("/equipment/{$mine->id}")->assertOk();
        $this->actingAs($viewer)->get("/equipment/{$theirs->id}")->assertForbidden();
        $this->actingAs($viewer)->get("/equipment/{$stock->id}")->assertForbidden();
    }

    public function test_the_tabs_count_the_same_units_the_list_shows()
    {
        $viewer = $this->person(['equipment.view.own']);
        Equipment::factory(2)->ofType($this->type())->issuedTo($viewer->id)->create();
        Equipment::factory(3)->ofType($this->type())->issuedTo(User::factory()->create()->id)->create();
        Equipment::factory(1)->ofType($this->type())->writtenOff()->create();

        $this->actingAs($viewer)
            ->get('/equipment?view=list')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('counts.all', 2)
                ->where('counts.issued', 2)
                ->where('counts.stock', 0)
                ->where('counts.written_off', 0)
            );
    }

    public function test_a_head_sees_the_units_of_their_department_and_of_its_divisions()
    {
        $department = Department::create(['name' => 'Производство']);
        $division = Department::create(['name' => 'Цех №2', 'parent_id' => $department->id]);
        $elsewhere = Department::create(['name' => 'Бухгалтерия']);

        $head = $this->person(['equipment.view.department']);
        $head->departments()->attach($department->id, ['is_head' => true]);

        $subordinate = User::factory()->create();
        $subordinate->departments()->attach($division->id, ['is_head' => false]);
        $stranger = User::factory()->create();
        $stranger->departments()->attach($elsewhere->id, ['is_head' => false]);

        $theirs = $this->unitOf($subordinate);
        $other = $this->unitOf($stranger);

        $this->assertSame([$theirs->name], $this->listed($head));
        $this->actingAs($head)->get("/equipment/{$theirs->id}")->assertOk();
        $this->actingAs($head)->get("/equipment/{$other->id}")->assertForbidden();
    }

    public function test_heading_nothing_shows_nothing_rather_than_everything()
    {
        // A position may carry the department scope while the person holding it
        // heads no department. That must come to an empty list, not to the fleet.
        $viewer = $this->person(['equipment.view.department']);
        $this->unitOf(User::factory()->create());
        $mine = $this->unitOf($viewer);

        $this->assertSame([], $this->listed($viewer));
        $this->actingAs($viewer)->get("/equipment/{$mine->id}")->assertForbidden();
    }

    public function test_the_two_narrow_parts_add_up()
    {
        $department = Department::create(['name' => 'Логистика']);
        $viewer = $this->person(['equipment.view.own', 'equipment.view.department']);
        $viewer->departments()->attach($department->id, ['is_head' => true]);

        $colleague = User::factory()->create();
        $colleague->departments()->attach($department->id, ['is_head' => false]);

        $mine = $this->unitOf($viewer);
        $theirs = $this->unitOf($colleague);
        $this->unitOf(User::factory()->create());

        $this->assertEqualsCanonicalizing([$mine->name, $theirs->name], $this->listed($viewer));
    }

    public function test_the_whole_fleet_is_the_whole_fleet()
    {
        $viewer = $this->person(['equipment.view.all']);
        $units = [$this->unitOf(), $this->unitOf(User::factory()->create()), $this->unitOf($viewer)];

        $this->assertCount(3, $this->listed($viewer));

        foreach ($units as $unit) {
            $this->actingAs($viewer)->get("/equipment/{$unit->id}")->assertOk();
        }
    }

    public function test_seeing_a_unit_is_not_reading_what_happened_to_it()
    {
        $viewer = $this->person(['equipment.view.own']);
        $mine = $this->unitOf($viewer);
        $this->entry($mine, $viewer);

        $this->actingAs($viewer)->get('/equipment/journal')->assertForbidden();
        $this->actingAs($viewer)
            ->get("/equipment/{$mine->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('canReadJournal', false)
                ->where('events', [])
            );
    }

    public function test_the_journal_opens_once_its_part_of_the_fleet_is_open()
    {
        $viewer = $this->person(['equipment.view.own', 'equipment.journal.own']);
        $mine = $this->unitOf($viewer);
        $this->entry($mine, $viewer);
        $this->entry($this->unitOf(User::factory()->create()), $viewer);

        $this->assertSame([$mine->id], $this->journalUnits($viewer));

        $this->actingAs($viewer)
            ->get("/equipment/{$mine->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('canReadJournal', true)->has('events'));
    }

    public function test_a_journal_of_units_one_may_not_see_stays_shut()
    {
        // The journal of the whole fleet, granted to somebody who only sees
        // their own desk, opens nothing: the entries are about units.
        $viewer = $this->person(['equipment.view.own', 'equipment.journal.all']);
        $this->entry($this->unitOf(User::factory()->create()), $viewer);

        $this->assertSame([], EquipmentAccess::journalScopes($viewer->fresh()));
        $this->actingAs($viewer)->get('/equipment/journal')->assertForbidden();
    }

    public function test_a_head_reads_the_journal_of_their_department_only()
    {
        $department = Department::create(['name' => 'Сервис']);
        $head = $this->person(['equipment.view.department', 'equipment.journal.department']);
        $head->departments()->attach($department->id, ['is_head' => true]);

        $subordinate = User::factory()->create();
        $subordinate->departments()->attach($department->id, ['is_head' => false]);

        $theirs = $this->unitOf($subordinate);
        $other = $this->unitOf(User::factory()->create());
        $this->entry($theirs, $head);
        $this->entry($other, $head);

        $this->assertSame([$theirs->id], $this->journalUnits($head));
    }

    public function test_the_filter_by_actor_names_only_people_from_readable_entries()
    {
        $viewer = $this->person(['equipment.view.own', 'equipment.journal.own']);
        $stranger = User::factory()->create(['surname' => 'Рахимов', 'name' => 'Фарход']);

        $this->entry($this->unitOf($viewer), $viewer);
        // Somebody who only ever touched a unit this viewer cannot see: naming
        // them in the filter would answer a question about that unit.
        $this->entry($this->unitOf(User::factory()->create()), $stranger);

        $this->actingAs($viewer)
            ->get('/equipment/journal')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('options.actors', fn ($actors) => collect($actors)->pluck('id')->all() === [$viewer->id])
            );
    }

    public function test_search_reaches_no_further_than_the_list()
    {
        $viewer = $this->person(['equipment.view.own']);
        $mine = $this->unitOf($viewer);
        $theirs = $this->unitOf(User::factory()->create());

        $found = $this->actingAs($viewer)->getJson('/search?q='.urlencode('EV-'))->json('equipment');
        $ids = array_column($found, 'id');

        $this->assertContains($mine->id, $ids);
        $this->assertNotContains($theirs->id, $ids);
    }

    /**
     * The box above the list asks about the whole row, the holder's name among
     * it. That is still a question about the part of the fleet this person was
     * given: naming somebody else's colleague finds their unit no sooner than
     * an empty box does.
     */
    public function test_the_lists_search_narrows_the_slice_and_never_widens_it()
    {
        $viewer = $this->person(['equipment.view.own']);
        $mine = $this->unitOf($viewer);
        $keeper = User::factory()->create(['surname' => 'Зокиров', 'name' => 'Бахтиёр']);
        $theirs = $this->unitOf($keeper);

        $found = fn (string $term) => $this->actingAs($viewer)
            ->get('/equipment?tab=all&q='.urlencode($term))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('equipment.data', 0));

        // By the name of whoever holds it, and by the sticker on it.
        $found('Зокиров Бахтиёр');
        $found($theirs->inventory_number);

        // What is theirs is still found, so the box narrows rather than shuts.
        $this->actingAs($viewer)
            ->get('/equipment?tab=all&q='.urlencode($mine->inventory_number))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('equipment.data', 1)
                ->where('equipment.data.0.id', $mine->id)
            );
    }

    public function test_search_offers_nothing_from_a_section_that_is_shut()
    {
        $nobody = $this->person();
        $this->unitOf();

        $response = $this->actingAs($nobody)->getJson('/search?q='.urlencode('EV-'));

        $this->assertSame([], $response->json('equipment'));
        $this->assertSame([], $response->json('equipmentTypes'));
    }

    public function test_an_administrator_is_asked_nothing()
    {
        $admin = User::factory()->create()->assignRole('sysadmin');
        $unit = $this->unitOf(User::factory()->create());
        $this->entry($unit, $admin);

        $this->actingAs($admin)->get('/equipment')->assertOk();
        $this->actingAs($admin)->get('/equipment/journal')->assertOk();
        $this->actingAs($admin)
            ->get("/equipment/{$unit->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('canReadJournal', true));
    }

    public function test_every_position_starts_with_its_own_desk()
    {
        // What a colleague carries by default: their own units, no journal.
        $colleague = $this->colleague();

        $this->assertSame(['own'], EquipmentAccess::viewScopes($colleague->fresh()));
        $this->assertSame([], EquipmentAccess::journalScopes($colleague->fresh()));
    }
}
