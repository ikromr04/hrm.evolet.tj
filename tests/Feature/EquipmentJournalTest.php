<?php

namespace Tests\Feature;

use App\Models\Equipment;
use App\Models\EquipmentEvent;
use App\Models\EquipmentType;
use App\Models\User;
use Database\Seeders\EquipmentTypeSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * The journal: everything that has happened to the fleet, and what a stretch
 * of time amounted to. Entries are written by watching the units themselves,
 * so any way of moving one ends up recorded.
 */
class EquipmentJournalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RoleSeeder::class, EquipmentTypeSeeder::class]);
    }

    /**
     * The one account that passes every check, whatever the rights say: these
     * tests are not about what a position may do.
     */
    private function sysadmin(): User
    {
        return User::factory()->create()->assignRole('sysadmin');
    }

    private function type(string $name = 'Ноутбуки'): EquipmentType
    {
        return EquipmentType::firstWhere('name', $name);
    }

    public function test_putting_a_unit_on_the_books_is_the_first_entry()
    {
        $admin = $this->sysadmin();

        $this->actingAs($admin)->post('/equipment', [
            'equipment_type_id' => $this->type()->id,
            'name' => 'Ноутбук Dell Latitude 5440',
            'inventory_number' => 'EV-0421',
        ]);

        $event = Equipment::whereInventory('EV-0421')->first()->events()->sole();
        $this->assertSame('created', $event->kind);
        $this->assertSame($admin->id, $event->user_id);
        $this->assertStringContainsString('EV-0421', $event->note);
    }

    public function test_every_move_is_recorded_with_what_changed()
    {
        $employee = User::factory()->create();
        $unit = Equipment::factory()->ofType($this->type())->create();
        $admin = $this->sysadmin();

        $this->actingAs($admin)->post("/equipment/{$unit->id}/issue", ['holder_user_id' => $employee->id, 'issued_at' => '2026-03-14']);
        $this->actingAs($admin)->post("/equipment/{$unit->id}/take", ['condition_on_return' => 'Царапина на крышке', 'returned_at' => now()->toDateString()]);
        $this->actingAs($admin)->post("/equipment/{$unit->id}/write-off", ['written_off_at' => '2026-04-01']);

        $kinds = $unit->events()->reorder('id')->pluck('kind')->all();
        $this->assertSame(['created', 'issued', 'stocked', 'written_off'], $kinds);

        // The handover says where the unit went, and from what to what.
        $issued = $unit->events()->where('kind', 'issued')->sole();
        $this->assertSame(['stock', 'issued'], $issued->diff['status']);
        $this->assertSame([null, $employee->id], $issued->diff['holder_user_id']);
        $this->assertSame($admin->id, $issued->user_id);
    }

    public function test_a_correction_is_named_by_the_block_it_was_made_in()
    {
        $unit = Equipment::factory()->ofType($this->type())->create(['condition' => 'Новое, в упаковке', 'name' => 'Ноутбук Dell']);
        $admin = $this->sysadmin();

        $this->actingAs($admin)->put("/equipment/{$unit->id}/state", [
            'condition' => 'Рабочее, следы эксплуатации',
            'checked_at' => '2026-09-01',
        ]);

        // The state block of the card is named as such, not as a bare change.
        $event = $unit->events()->where('kind', 'condition')->sole();
        $this->assertSame(['Новое, в упаковке', 'Рабочее, следы эксплуатации'], $event->diff['condition']);
        // Untouched fields stay out of it.
        $this->assertArrayNotHasKey('name', $event->diff);

        // So is the box it comes in, and so is everything else, together.
        $this->actingAs($admin)->put("/equipment/{$unit->id}/accessories", ['accessories' => ['Сумка']]);
        $this->assertSame(1, $unit->events()->where('kind', 'accessories')->count());

        // A name the factory's pool cannot produce, or the save might change
        // nothing and write no entry at all.
        $this->actingAs($admin)->put("/equipment/{$unit->id}/specs", [
            'equipment_type_id' => $unit->equipment_type_id,
            'name' => 'Ноутбук из переговорной, второй',
            'inventory_number' => $unit->inventory_number,
        ]);
        $this->assertSame(1, $unit->events()->where('kind', 'updated')->count());
    }

    public function test_the_accessories_are_recorded_as_lists_on_both_sides()
    {
        $unit = Equipment::factory()->ofType($this->type())->create(['accessories' => ['Блок питания 65 Вт', 'Сумка', 'Док-станция WD19S']]);
        $admin = $this->sysadmin();

        // One item swapped for another: the entry keeps both lists whole, so
        // the card can say what came and what went rather than printing JSON.
        $this->actingAs($admin)->put("/equipment/{$unit->id}/accessories", [
            'accessories' => ['Блок питания 65 Вт', 'Сумка', 'Мышь Logitech M185'],
        ])->assertSessionHasNoErrors();

        $event = $unit->events()->where('kind', 'accessories')->sole();
        $this->assertSame([
            ['Блок питания 65 Вт', 'Сумка', 'Док-станция WD19S'],
            ['Блок питания 65 Вт', 'Сумка', 'Мышь Logitech M185'],
        ], $event->diff['accessories']);

        // Adding and removing are recorded the same way, as two whole lists.
        $this->actingAs($admin)->put("/equipment/{$unit->id}/accessories", ['accessories' => []]);

        $cleared = $unit->events()->where('kind', 'accessories')->latest('id')->first();
        $this->assertSame([['Блок питания 65 Вт', 'Сумка', 'Мышь Logitech M185'], []], $cleared->diff['accessories']);
    }

    public function test_service_says_what_was_done_and_leaves_the_unit_where_it_is()
    {
        $employee = User::factory()->create();
        $unit = Equipment::factory()->ofType($this->type())->issuedTo($employee->id)->create();
        $admin = $this->sysadmin();

        $this->actingAs($admin)->post("/equipment/{$unit->id}/repairs", [
            'kind' => 'Замена картриджа',
            'started_at' => now()->toDateString(),
        ])->assertSessionHasNoErrors();

        // An entry of its own, carrying what was done; the unit does not move.
        $this->assertSame('Замена картриджа', $unit->events()->where('kind', 'repair_added')->sole()->note);
        $this->assertSame('issued', $unit->refresh()->status);

        // Removing the record says so too, rather than leaving a hole.
        $this->actingAs($admin)
            ->delete("/equipment/{$unit->id}/repairs/{$unit->repairs()->sole()->id}")
            ->assertSessionHasNoErrors();

        $this->assertSame('Замена картриджа', $unit->events()->where('kind', 'repair_removed')->sole()->note);
        $this->assertSame(0, $unit->repairs()->count());
    }

    public function test_service_can_be_photographed_and_the_pictures_stay_with_the_record()
    {
        Storage::fake('public');
        $unit = Equipment::factory()->ofType($this->type())->create();

        $this->actingAs($this->sysadmin())->post("/equipment/{$unit->id}/repairs", [
            'kind' => 'Замена клавиатуры',
            'started_at' => '2026-09-01',
            'photos' => [UploadedFile::fake()->image('before.jpg', 1600, 1200)],
        ])->assertSessionHasNoErrors();

        $repair = $unit->repairs()->sole();
        $photo = $repair->photos()->sole();
        Storage::disk('public')->assertExists($photo->path);
        Storage::disk('public')->assertExists($photo->preview);

        // Readable from either side: the record it came with, and the journal.
        $this->assertSame($photo->id, $unit->events()->where('kind', 'repair_added')->sole()->photos()->sole()->id);
    }

    public function test_a_return_can_be_photographed_and_the_pictures_go_to_its_entry()
    {
        Storage::fake('public');
        $employee = User::factory()->create();
        $unit = Equipment::factory()->ofType($this->type())->issuedTo($employee->id)->create();

        $this->actingAs($this->sysadmin())->post("/equipment/{$unit->id}/take", [
            'condition_on_return' => 'Царапина на крышке',
            'returned_at' => now()->toDateString(),
            'photos' => [UploadedFile::fake()->image('lid.jpg', 1600, 1200)],
        ])->assertSessionHasNoErrors();

        // On the entry that records the return, not on some entry of its own.
        $photo = $unit->events()->where('kind', 'stocked')->sole()->photos()->sole();
        Storage::disk('public')->assertExists($photo->path);
        Storage::disk('public')->assertExists($photo->preview);
    }

    public function test_a_handover_and_a_write_off_keep_their_pictures_too()
    {
        Storage::fake('public');
        $employee = User::factory()->create();
        $unit = Equipment::factory()->ofType($this->type())->create();
        $admin = $this->sysadmin();

        $this->actingAs($admin)->post("/equipment/{$unit->id}/issue", [
            'holder_user_id' => $employee->id,
            'issued_at' => now()->toDateString(),
            'photos' => [UploadedFile::fake()->image('handover.jpg', 1200, 900)],
        ])->assertSessionHasNoErrors();

        $this->actingAs($admin)->post("/equipment/{$unit->id}/write-off", [
            'written_off_at' => now()->toDateString(),
            'photos' => [UploadedFile::fake()->image('broken.jpg', 1200, 900)],
        ])->assertSessionHasNoErrors();

        // Each picture on the entry for its own occasion, one apiece.
        $this->assertSame(1, $unit->events()->where('kind', 'issued')->sole()->photos()->count());
        $this->assertSame(1, $unit->events()->where('kind', 'written_off')->sole()->photos()->count());
        $this->assertSame(2, $unit->photos()->count());
    }

    public function test_correcting_a_service_record_says_what_was_corrected()
    {
        $unit = Equipment::factory()->ofType($this->type())->create();
        $repair = $unit->repairs()->create(['kind' => 'Диагностика', 'started_at' => '2026-09-01']);

        $this->actingAs($this->sysadmin())->put("/equipment/{$unit->id}/repairs/{$repair->id}", [
            'kind' => 'Замена клавиатуры',
            'started_at' => '2026-09-01',
            'note' => 'По заявке сотрудника',
        ])->assertSessionHasNoErrors();

        // Not just "something changed": the entry carries the lines themselves.
        $event = $unit->events()->where('kind', 'repair_updated')->sole();
        $this->assertSame(['Диагностика', 'Замена клавиатуры'], $event->diff['kind']);
        $this->assertSame([null, 'По заявке сотрудника'], $event->diff['note']);

        // Finishing the work is named as such, with the date it ended.
        $this->actingAs($this->sysadmin())->put("/equipment/{$unit->id}/repairs/{$repair->id}", [
            'kind' => 'Замена клавиатуры',
            'started_at' => '2026-09-01',
            'ended_at' => '2026-09-05',
            'note' => 'По заявке сотрудника',
        ])->assertSessionHasNoErrors();

        $ended = $unit->events()->where('kind', 'repair_ended')->sole();
        $this->assertSame([null, '2026-09-05'], $ended->diff['ended_at']);
    }

    public function test_a_check_keeps_its_photographs_and_a_later_one_does_not_replace_them()
    {
        Storage::fake('public');
        $unit = Equipment::factory()->ofType($this->type())->create(['condition' => 'Новое, в упаковке']);
        $admin = $this->sysadmin();

        $check = fn (string $condition, int $count) => $this->actingAs($admin)->post("/equipment/{$unit->id}/state", [
            // Multipart, so the upload is a POST that says it is a PUT.
            '_method' => 'put',
            'condition' => $condition,
            'checked_at' => now()->toDateString(),
            'next_inventory_at' => now()->addYear()->toDateString(),
            'photos' => array_map(fn () => UploadedFile::fake()->image('photo.jpg', 1600, 1200), range(1, $count)),
        ]);

        $check('Рабочее, следы эксплуатации', 2)->assertSessionHasNoErrors();
        $check('Скол на крышке', 1)->assertSessionHasNoErrors();

        // Each check keeps the pictures it was given; a later one only adds.
        $this->assertSame(3, $unit->photos()->count());
        $counts = $unit->events()->where('kind', 'condition')->get()->map(fn ($event) => $event->photos->count())->sort()->values()->all();
        $this->assertSame([1, 2], $counts);

        // Kept twice over: the upload, and the copy the interface shows.
        $photo = $unit->photos()->first();
        Storage::disk('public')->assertExists($photo->path);
        Storage::disk('public')->assertExists($photo->preview);
        $this->assertNotSame($photo->path, $photo->preview);
    }

    public function test_the_journal_answers_for_a_period()
    {
        $unit = Equipment::factory()->ofType($this->type())->create(['name' => 'Ноутбук Acer Aspire 5']);
        $old = $unit->events()->sole();
        $old->created_at = now()->subMonths(6);
        $old->save();

        $fresh = $unit->events()->create(['kind' => 'updated', 'diff' => ['condition' => ['Новое', 'Рабочее']]]);
        $fresh->created_at = now()->subDays(3);
        $fresh->save();

        // No period asked for, so the journal opens on the whole of it.
        $this->actingAs($this->sysadmin())
            ->get('/equipment/journal')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('equipment/journal')
                ->has('events.data', 2)
                ->where('filters.from', null)
                ->where('filters.to', null)
            );

        // Narrowed to the last month, the six-month-old entry drops out.
        $this->actingAs($this->sysadmin())
            ->get('/equipment/journal?from='.now()->subDays(29)->toDateString().'&to='.now()->toDateString())
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('events.data', 1)
                ->where('events.data.0.kind', 'updated')
            );

        // One end alone is a period too: everything since a date.
        $this->actingAs($this->sysadmin())
            ->get('/equipment/journal?from='.now()->subYear()->toDateString())
            ->assertInertia(fn (AssertableInertia $page) => $page->has('events.data', 2));
    }

    public function test_the_journal_narrows_by_operation_unit_and_who_did_it()
    {
        $admin = $this->sysadmin();
        $employee = User::factory()->create();
        $laptop = Equipment::factory()->ofType($this->type())->create(['name' => 'Ноутбук Acer', 'inventory_number' => 'EV-0001']);
        $monitor = Equipment::factory()->ofType($this->type('Мониторы'))->create(['name' => 'Монитор Dell', 'inventory_number' => 'EV-0002']);

        $this->actingAs($admin)->post("/equipment/{$laptop->id}/issue", ['holder_user_id' => $employee->id, 'issued_at' => now()->toDateString()]);

        $only = fn (string $query, int $count) => $this->actingAs($admin)->get("/equipment/journal?{$query}")
            ->assertInertia(fn (AssertableInertia $page) => $page->has('events.data', $count));

        $only('kind[]=issued', 1);
        $only('unit=EV-0002', 1);
        $only('type[]='.$monitor->equipment_type_id, 1);
        // Units made by the factory have nobody behind them, so the admin owns
        // only the handover they actually made.
        $only("actor[]={$admin->id}", 1);
        $only("actor[]={$employee->id}", 0);
    }

    public function test_the_ids_an_entry_kept_are_read_back_as_names()
    {
        $employee = User::factory()->create(['surname' => 'Рахимов', 'name' => 'Фарход']);
        $unit = Equipment::factory()->ofType($this->type())->create();
        $admin = $this->sysadmin();

        $this->actingAs($admin)->post("/equipment/{$unit->id}/issue", [
            'holder_user_id' => $employee->id,
            'issued_at' => now()->toDateString(),
        ]);

        // The entry keeps the id it saw; the page says who stands behind it.
        $this->actingAs($admin)
            ->get('/equipment/journal')
            ->assertInertia(fn (AssertableInertia $page) => $page->where("names.holder_user_id.{$employee->id}", 'Рахимов Фарход'));

        $this->actingAs($admin)
            ->get("/equipment/{$unit->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page->where("names.holder_user_id.{$employee->id}", 'Рахимов Фарход'));
    }

    public function test_the_card_carries_its_own_journal()
    {
        $unit = Equipment::factory()->ofType($this->type())->create();

        $this->actingAs($this->sysadmin())
            ->get("/equipment/{$unit->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page->has('events', 1)->where('events.0.kind', 'created'));
    }

    public function test_the_journal_keeps_to_whoever_manages_the_fleet()
    {
        $this->actingAs($this->colleague())->get('/equipment/journal')->assertForbidden();
        $this->actingAs($this->sysadmin())->get('/equipment/journal')->assertOk();
    }

    public function test_every_kind_the_journal_can_record_has_a_name()
    {
        // The page reads the kinds off this list, so nothing may go unnamed.
        $this->assertSame(
            EquipmentEvent::KINDS,
            array_values(array_unique(EquipmentEvent::KINDS)),
        );
    }
}
