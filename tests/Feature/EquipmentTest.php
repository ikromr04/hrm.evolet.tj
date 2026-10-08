<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Equipment;
use App\Models\EquipmentType;
use App\Models\User;
use App\Models\UserDetail;
use App\Support\EquipmentIcons;
use Database\Seeders\EquipmentTypeSeeder;
use Database\Seeders\PositionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * The equipment section: the whole fleet, the state each unit is in and who
 * holds it. A unit belongs to the company, not to whoever happens to have it.
 */
class EquipmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RoleSeeder::class, PositionSeeder::class, EquipmentTypeSeeder::class]);
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

    public function test_the_list_is_open_to_every_signed_in_colleague()
    {
        $this->get('/equipment')->assertRedirect('/login');
        $this->actingAs($this->colleague())->get('/equipment')->assertOk();
    }

    public function test_the_tabs_count_every_status()
    {
        $holder = User::factory()->create();
        Equipment::factory(2)->ofType($this->type())->issuedTo($holder->id)->create();
        Equipment::factory(1)->ofType($this->type())->writtenOff()->create();

        $this->actingAs($this->sysadmin())
            ->get('/equipment')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('counts.all', 3)
                ->where('counts.issued', 2)
                ->where('counts.stock', 0)
                ->where('counts.written_off', 1)
            );
    }

    public function test_the_tab_for_service_finds_whatever_is_being_looked_after()
    {
        $holder = User::factory()->create();
        $onDesk = Equipment::factory()->ofType($this->type())->issuedTo($holder->id)->create(['name' => 'Ноутбук в работе']);
        $onDesk->repairs()->create(['kind' => 'Замена клавиатуры', 'started_at' => '2026-09-01']);

        // Finished work is history, not a unit that is away right now.
        $done = Equipment::factory()->ofType($this->type())->create(['name' => 'Ноутбук после ТО']);
        $done->repairs()->create(['kind' => 'Плановое ТО', 'started_at' => '2026-08-01', 'ended_at' => '2026-08-03']);

        $this->actingAs($this->sysadmin())
            ->get('/equipment?tab=service')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('counts.service', 1)
                ->has('equipment.data', 1)
                ->where('equipment.data.0.name', $onDesk->name)
                // Service is not a status: it is still issued to the colleague.
                ->where('equipment.data.0.status', 'issued')
                ->where('equipment.data.0.in_service', true)
            );
    }

    public function test_an_end_date_takes_a_unit_off_the_service_list()
    {
        $unit = Equipment::factory()->ofType($this->type())->create();
        $repair = $unit->repairs()->create(['kind' => 'Замена клавиатуры', 'started_at' => '2026-09-01']);

        $this->assertSame(1, Equipment::query()->underService()->count());

        $this->actingAs($this->sysadmin())
            ->put("/equipment/{$unit->id}/repairs/{$repair->id}", [
                'kind' => 'Замена клавиатуры',
                'started_at' => '2026-09-01',
                'ended_at' => '2026-09-05',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('2026-09-05', $repair->refresh()->ended_at->toDateString());
        $this->assertSame(0, Equipment::query()->underService()->count());

        // Finishing the work is named in the journal, not filed as a correction.
        $this->assertSame('Замена клавиатуры', $unit->events()->where('kind', 'repair_ended')->sole()->note);
    }

    public function test_the_list_opens_on_what_is_out_with_people()
    {
        $holder = User::factory()->create(['surname' => 'Азимов']);
        Equipment::factory()->ofType($this->type())->issuedTo($holder->id)->create(['name' => 'Выданный ноутбук']);
        Equipment::factory()->ofType($this->type())->create(['name' => 'Свободный ноутбук']);

        // No tab in the query means the one the list opens on, not everything.
        $this->actingAs($this->sysadmin())
            ->get('/equipment')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('tab', 'issued')
                ->where('sort.key', 'holder')
                ->has('equipment.data', 1)
                ->where('equipment.data.0.name', 'Выданный ноутбук')
            );

        // Все says so, and then nothing is left out.
        $this->actingAs($this->sysadmin())
            ->get('/equipment?tab=all')
            ->assertInertia(fn (AssertableInertia $page) => $page->has('equipment.data', 2));
    }

    public function test_the_tab_above_the_table_picks_one_status()
    {
        $holder = User::factory()->create();
        Equipment::factory()->ofType($this->type())->issuedTo($holder->id)->create(['name' => 'Ноутбук A']);
        Equipment::factory()->ofType($this->type())->create(['name' => 'Ноутбук C']);

        $this->actingAs($this->sysadmin())->get('/equipment?tab=stock')
            ->assertInertia(fn (AssertableInertia $page) => $page->has('equipment.data', 1)->where('equipment.data.0.name', 'Ноутбук C'));
    }

    public function test_every_column_has_a_filter_of_its_own()
    {
        $holder = User::factory()->create(['surname' => 'Абдуллаев']);
        $other = User::factory()->create(['surname' => 'Зокиров']);
        Equipment::factory()->ofType($this->type())->issuedTo($holder->id)
            ->create(['name' => 'Ноутбук A', 'inventory_number' => 'EV-0001', 'issued_at' => '2026-03-14']);
        Equipment::factory()->ofType($this->type('Мониторы'))->issuedTo($other->id)
            ->create(['name' => 'Монитор B', 'inventory_number' => 'EV-0002', 'issued_at' => '2026-01-05']);
        Equipment::factory()->ofType($this->type())->create(['name' => 'Ноутбук C', 'inventory_number' => 'EV-0003']);

        $admin = $this->sysadmin();

        $only = fn (string $query, string $name) => $this->actingAs($admin)->get("/equipment?tab=all&{$query}")
            ->assertInertia(fn (AssertableInertia $page) => $page->has('equipment.data', 1)->where('equipment.data.0.name', $name));

        $only('name=Монитор', 'Монитор B');
        $only('inventory_number=EV-0001', 'Ноутбук A');
        $only('type[]='.$this->type('Мониторы')->id, 'Монитор B');
        $only('status[]=stock', 'Ноутбук C');
        // "У кого" is typed in, not picked from a list, and a full name works
        // too: that is the link the employee's profile opens.
        $only('holder='.urlencode('Абдулла'), 'Ноутбук A');
        $only('holder='.urlencode("Абдуллаев {$holder->name}"), 'Ноутбук A');
        $only('issued_from=2026-02-01', 'Ноутбук A');
        $only('issued_to=2026-02-01', 'Монитор B');
    }

    public function test_the_list_sorts_by_a_column_in_either_direction()
    {
        Equipment::factory()->ofType($this->type())->create(['name' => 'Б', 'inventory_number' => 'EV-0002']);
        Equipment::factory()->ofType($this->type())->create(['name' => 'А', 'inventory_number' => 'EV-0001']);

        $admin = $this->sysadmin();

        // A column of the table, and the same one turned around.
        $this->actingAs($admin)->get('/equipment?tab=all&sort=name')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('equipment.data.0.name', 'А'));

        $this->actingAs($admin)->get('/equipment?tab=all&sort=name&direction=desc')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('equipment.data.0.name', 'Б'));

        // A column the table does not offer is refused rather than ignored.
        $this->actingAs($admin)->get('/equipment?sort=serial_number')->assertSessionHasErrors('sort');
    }

    public function test_search_covers_the_name_the_sticker_and_the_categorys_own_fields()
    {
        $type = $this->type();
        $serial = $type->fields()->firstWhere('name', 'Серийный номер');

        $unit = Equipment::factory()->ofType($type)->create([
            'name' => 'Ноутбук Dell Latitude 5440',
            'inventory_number' => 'EV-0421',
        ]);
        $unit->fieldValues()->updateOrCreate(['equipment_field_id' => $serial->id], ['value' => '7K2L9P3']);

        $other = Equipment::factory()->ofType($type)->create(['name' => 'Монитор', 'inventory_number' => 'EV-9999']);
        $other->fieldValues()->updateOrCreate(['equipment_field_id' => $serial->id], ['value' => 'ZZZ']);

        $admin = $this->sysadmin();

        foreach (['Latitude', 'EV-0421', '7K2L9P3'] as $term) {
            $this->actingAs($admin)->get('/equipment?tab=all&q='.urlencode($term))
                ->assertInertia(fn (AssertableInertia $page) => $page
                    ->has('equipment.data', 1)
                    ->where('equipment.data.0.inventory_number', 'EV-0421')
                );
        }
    }

    public function test_an_admin_puts_a_new_unit_on_the_books()
    {
        $this->actingAs($this->sysadmin())
            ->post('/equipment', [
                'equipment_type_id' => $this->type()->id,
                'name' => 'Ноутбук Dell Latitude 5440',
                'inventory_number' => 'EV-0421',
            ])
            ->assertSessionHasNoErrors();

        $unit = Equipment::firstWhere('inventory_number', 'EV-0421');
        $this->assertSame('Ноутбук Dell Latitude 5440', $unit->name);
        // Nobody holds it yet: it is in stock until it is handed out.
        $this->assertSame('stock', $unit->status);
        $this->assertNull($unit->holder_user_id);
        $this->assertNull($unit->issued_at);
    }

    public function test_a_new_unit_can_be_handed_over_as_it_is_entered()
    {
        $employee = $this->colleague();

        $this->actingAs($this->sysadmin())
            ->post('/equipment', [
                'equipment_type_id' => $this->type()->id,
                'name' => 'Ноутбук для нового бухгалтера',
                'inventory_number' => 'EV-0422',
                'holder_user_id' => $employee->id,
                'issued_at' => '2026-03-14',
            ])
            ->assertSessionHasNoErrors();

        $unit = Equipment::firstWhere('inventory_number', 'EV-0422');
        $this->assertSame('issued', $unit->status);
        $this->assertSame($employee->id, $unit->holder_user_id);
        $this->assertSame('2026-03-14', $unit->issued_at->toDateString());

        // The journal reads as it happened: entered, then handed over.
        $this->assertSame(['created', 'issued'], $unit->events()->reorder('id')->pluck('kind')->all());
    }

    public function test_saving_and_adding_another_keeps_the_form_open()
    {
        $response = $this->actingAs($this->sysadmin())
            ->from('/equipment/create')
            ->post('/equipment', [
                'equipment_type_id' => $this->type()->id,
                'name' => 'Ноутбук из партии',
                'inventory_number' => 'EV-0500',
                'another' => true,
            ]);

        // Back to the form rather than off to the card, and the unit that was
        // filed rides along so the page can name it.
        $response->assertSessionHasNoErrors()->assertRedirect('/equipment/create');
        $response->assertSessionHas('equipment.inventory_number', 'EV-0500');
        $this->assertSame(1, Equipment::count());
    }

    public function test_the_form_for_a_new_unit_is_a_page_of_its_own()
    {
        $this->actingAs($this->sysadmin())
            ->get('/equipment/create')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('equipment/create')
                ->has('options.types')
                ->has('options.holders')
            );

        // A colleague who does not manage the fleet has no business there.
        $this->actingAs($this->colleague())->get('/equipment/create')->assertForbidden();
    }

    public function test_a_unit_can_be_photographed_as_it_is_entered()
    {
        Storage::fake('public');

        $this->actingAs($this->sysadmin())
            ->post('/equipment', [
                'equipment_type_id' => $this->type()->id,
                'name' => 'Ноутбук в заводской плёнке',
                'inventory_number' => 'EV-0424',
                'condition' => 'Новое, в упаковке',
                'checked_at' => now()->toDateString(),
                'next_inventory_at' => now()->addYear()->toDateString(),
                'photos' => [UploadedFile::fake()->image('box.jpg', 1600, 1200)],
            ])
            ->assertSessionHasNoErrors();

        $unit = Equipment::firstWhere('inventory_number', 'EV-0424');
        $this->assertSame(now()->toDateString(), $unit->checked_at->toDateString());

        // The picture hangs on the entry that records the arrival.
        $arrival = $unit->events()->sole();
        $this->assertSame('created', $arrival->kind);
        $photo = $arrival->photos()->sole();
        Storage::disk('public')->assertExists($photo->path);
        Storage::disk('public')->assertExists($photo->preview);
    }

    public function test_a_handover_made_while_entering_a_unit_still_needs_its_date()
    {
        $employee = $this->colleague();

        $this->actingAs($this->sysadmin())
            ->post('/equipment', [
                'equipment_type_id' => $this->type()->id,
                'name' => 'Ноутбук без даты',
                'inventory_number' => 'EV-0423',
                'holder_user_id' => $employee->id,
            ])
            ->assertSessionHasErrors('issued_at');

        $this->assertSame(0, Equipment::count());
    }

    public function test_a_new_unit_needs_a_name_a_category_and_a_free_inventory_number()
    {
        Equipment::factory()->ofType($this->type())->create(['inventory_number' => 'EV-0421']);
        $admin = $this->sysadmin();

        $this->actingAs($admin)->post('/equipment', [])
            ->assertSessionHasErrors(['equipment_type_id', 'name', 'inventory_number']);

        $this->actingAs($admin)->post('/equipment', [
            'equipment_type_id' => $this->type()->id,
            'name' => 'Второй ноутбук',
            'inventory_number' => 'EV-0421',
        ])->assertSessionHasErrors('inventory_number');

        $this->assertSame(1, Equipment::count());
    }

    public function test_a_unit_entered_by_mistake_is_struck_off_with_everything_under_it()
    {
        $unit = Equipment::factory()->ofType($this->type())->writtenOff()->create();
        $unit->repairs()->create(['kind' => 'Диагностика', 'started_at' => '2026-09-01']);

        $this->actingAs($this->sysadmin())->delete("/equipment/{$unit->id}")->assertRedirect('/equipment');

        $this->assertNull(Equipment::find($unit->id));
        // Nothing is left pointing at a unit that no longer exists.
        $this->assertSame(0, DB::table('equipment_repairs')->where('equipment_id', $unit->id)->count());
        $this->assertSame(0, DB::table('equipment_events')->where('equipment_id', $unit->id)->count());
    }

    public function test_only_a_written_off_unit_may_be_struck_off_and_only_by_a_manager()
    {
        $employee = $this->colleague();
        $inService = Equipment::factory()->ofType($this->type())->issuedTo($employee->id)->create();
        $written = Equipment::factory()->ofType($this->type())->writtenOff()->create();

        // Part of the fleet is somebody's to account for: a mistake is written
        // off first, and only then removed. What asks is a dialog, so it is sent
        // back with a sentence rather than a refusal it cannot show.
        $this->actingAs($this->sysadmin())
            ->from('/equipment')
            ->delete("/equipment/{$inService->id}")
            ->assertRedirect('/equipment')
            ->assertSessionHas('notice', 'Удалить можно только списанное оборудование.');
        $this->assertNotNull(Equipment::find($inService->id));

        $this->actingAs($employee)->delete("/equipment/{$written->id}")->assertForbidden();
        $this->assertNotNull(Equipment::find($written->id));
    }

    public function test_only_managers_add_equipment()
    {
        $this->actingAs($this->colleague())
            ->post('/equipment', [
                'equipment_type_id' => $this->type()->id,
                'name' => 'Ноутбук',
                'inventory_number' => 'EV-0001',
            ])
            ->assertForbidden();

        $this->assertSame(0, Equipment::count());
    }

    public function test_an_admin_hands_a_unit_to_an_employee()
    {
        $employee = $this->colleague();
        $unit = Equipment::factory()->ofType($this->type())->create();

        $this->actingAs($this->sysadmin())
            ->post("/equipment/{$unit->id}/issue", [
                'holder_user_id' => $employee->id,
                'issued_at' => '2026-03-14',
                'condition' => 'Рабочее, без повреждений',
            ])
            ->assertSessionHasNoErrors();

        $unit->refresh();
        $this->assertSame('issued', $unit->status);
        $this->assertSame($employee->id, $unit->holder_user_id);
        $this->assertSame('2026-03-14', $unit->issued_at->toDateString());

        // What state it went out in is kept, so a return has something to be
        // compared against.
        $this->assertSame('Рабочее, без повреждений', $unit->condition);

        // And the journal says so, with the colleague it went to.
        $event = $unit->events()->where('kind', 'issued')->sole();
        $this->assertSame([null, $employee->id], $event->diff['holder_user_id']);
    }

    public function test_the_journal_follows_a_unit_from_hand_to_hand()
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        $unit = Equipment::factory()->ofType($this->type())->create();
        $admin = $this->sysadmin();

        $this->actingAs($admin)->post("/equipment/{$unit->id}/issue", ['holder_user_id' => $first->id, 'issued_at' => '2026-01-10']);
        $this->actingAs($admin)->post("/equipment/{$unit->id}/take", ['condition_on_return' => 'Царапина на крышке', 'returned_at' => now()->toDateString()]);
        $this->actingAs($admin)->post("/equipment/{$unit->id}/issue", ['holder_user_id' => $second->id, 'issued_at' => '2026-03-14']);

        // With the first colleague, back on the balance sheet, then with the
        // second — the journal is the whole record of where it has been.
        $this->assertSame(
            ['created', 'issued', 'stocked', 'issued'],
            $unit->events()->reorder('id')->pluck('kind')->all(),
        );

        // What it came back looking like is kept on the unit and in its journal.
        $this->assertSame('Царапина на крышке', $unit->refresh()->condition);
    }

    public function test_the_card_shows_the_unit_its_history_and_its_repairs()
    {
        $employee = $this->colleague(['surname' => 'Рахимов']);
        $unit = Equipment::factory()->ofType($this->type())->issuedTo($employee->id)->create([
            'name' => 'Ноутбук Dell Latitude 5440',
            'accessories' => ['Блок питания 65 Вт', 'Сумка'],
        ]);

        // What this category asks about, as the directory set it up.
        $field = $unit->type->fields()->firstWhere('name', 'Процессор');
        $unit->fieldValues()->updateOrCreate(['equipment_field_id' => $field->id], ['value' => 'Intel Core i5-1335U']);
        $at = $unit->type->fields->search(fn ($row) => $row->id === $field->id);
        $unit->repairs()->create(['kind' => 'Замена аккумулятора', 'started_at' => '2024-11-02', 'ended_at' => '2024-11-06']);

        $this->actingAs($this->sysadmin())
            ->get("/equipment/{$unit->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('equipment/show')
                ->where('unit.name', 'Ноутбук Dell Latitude 5440')
                ->where("unit.fields.{$at}.name", 'Процессор')
                ->where("unit.fields.{$at}.value", 'Intel Core i5-1335U')
                ->where('unit.accessories.1', 'Сумка')
                ->has('repairs', 1)
            );
    }

    public function test_the_card_points_at_the_units_either_side_of_it()
    {
        $first = Equipment::factory()->ofType($this->type())->create(['name' => 'Монитор Dell P2422H']);
        $middle = Equipment::factory()->ofType($this->type())->create(['name' => 'Ноутбук Acer Aspire 5']);
        $last = Equipment::factory()->ofType($this->type())->create(['name' => 'Телефон Xiaomi Redmi 12']);
        $admin = $this->sysadmin();

        $this->actingAs($admin)
            ->get("/equipment/{$middle->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('neighbours.prev.name', $first->name)
                ->where('neighbours.next.name', $last->name)
            );

        // At either end of the list there is nowhere further to go.
        $this->actingAs($admin)
            ->get("/equipment/{$first->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('neighbours.prev', null)
                ->where('neighbours.next.name', $middle->name)
            );
    }

    public function test_walking_the_fleet_stays_within_one_status()
    {
        $holder = User::factory()->create();
        // On the balance sheet either side of the one that is out, and one struck off.
        Equipment::factory()->ofType($this->type())->create(['name' => 'А, свободен']);
        $issued = Equipment::factory()->ofType($this->type())->issuedTo($holder->id)->create(['name' => 'Б, выдан']);
        Equipment::factory()->ofType($this->type())->create(['name' => 'В, свободен']);
        Equipment::factory()->ofType($this->type())->writtenOff()->create(['name' => 'Г, списан']);

        $later = Equipment::factory()->ofType($this->type())->issuedTo($holder->id)->create(['name' => 'Я, тоже выдан']);

        $this->actingAs($this->sysadmin())
            ->get("/equipment/{$issued->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                // Nothing issued comes before it, whatever sits there in stock.
                ->where('neighbours.prev', null)
                ->where('neighbours.next.name', $later->name)
            );
    }

    public function test_the_card_blocks_are_edited_one_at_a_time()
    {
        $unit = Equipment::factory()->ofType($this->type())->create(['inventory_number' => 'EV-0421']);
        $taken = Equipment::factory()->ofType($this->type())->create(['inventory_number' => 'EV-0999']);
        $admin = $this->sysadmin();

        $monitors = $this->type('Мониторы');
        $model = $monitors->fields()->firstWhere('name', 'Модель');

        $this->actingAs($admin)
            ->put("/equipment/{$unit->id}/specs", [
                'equipment_type_id' => $monitors->id,
                'name' => 'Монитор Dell P2422H',
                // The model is a field of the category the unit lands in.
                'fields' => [$model->id => 'P2422H'],
                'inventory_number' => 'EV-0421',
            ])
            ->assertSessionHasNoErrors();

        $unit->refresh();
        $this->assertSame('Монитор Dell P2422H', $unit->name);
        $this->assertSame('P2422H', $unit->fieldValues()->where('equipment_field_id', $model->id)->sole()->value);
        $this->assertSame($monitors->id, $unit->equipment_type_id);
        // Its own number is not a clash with itself.
        $this->assertSame('EV-0421', $unit->inventory_number);

        // Somebody else's number still is.
        $this->actingAs($admin)
            ->put("/equipment/{$unit->id}/specs", [
                'equipment_type_id' => $unit->equipment_type_id,
                'name' => $unit->name,
                'inventory_number' => $taken->inventory_number,
            ])
            ->assertSessionHasErrors('inventory_number');

        // The list of accessories is replaced whole, blanks dropped.
        $this->actingAs($admin)
            ->put("/equipment/{$unit->id}/accessories", ['accessories' => ['Кабель HDMI', '  ', 'Подставка']])
            ->assertSessionHasNoErrors();
        $this->assertSame(['Кабель HDMI', 'Подставка'], $unit->fresh()->accessories);

        $this->actingAs($this->colleague())
            ->put("/equipment/{$unit->id}/accessories", ['accessories' => []])
            ->assertForbidden();
    }

    public function test_the_inventory_block_is_filled_in_by_hand()
    {
        $employee = $this->colleague();
        $unit = Equipment::factory()->ofType($this->type())->issuedTo($employee->id)->create();

        // Somebody checks a unit where it stands, without moving it.
        $this->actingAs($this->sysadmin())
            ->put("/equipment/{$unit->id}/state", [
                'condition' => 'Рабочее, следы эксплуатации',
                'checked_at' => '2026-09-01',
                'next_inventory_at' => '2026-12-01',
            ])
            ->assertSessionHasNoErrors();

        $unit->refresh();
        $this->assertSame('Рабочее, следы эксплуатации', $unit->condition);
        $this->assertSame('2026-09-01', $unit->checked_at->toDateString());
        // Who holds it is none of that block's business.
        $this->assertSame($employee->id, $unit->holder_user_id);
    }

    public function test_service_is_recorded_without_moving_the_unit()
    {
        $employee = $this->colleague();
        $unit = Equipment::factory()->ofType($this->type())->issuedTo($employee->id)->create();

        $this->actingAs($this->sysadmin())
            ->post("/equipment/{$unit->id}/repairs", ['kind' => 'Диагностика', 'started_at' => '2026-09-01'])
            ->assertSessionHasNoErrors();

        // A record and nothing more: it stays issued to the colleague holding it.
        $unit->refresh();
        $this->assertSame('issued', $unit->status);
        $this->assertSame($employee->id, $unit->holder_user_id);
        $this->assertCount(1, $unit->repairs);

        $stock = Equipment::factory()->ofType($this->type())->create();
        $this->actingAs($this->sysadmin())
            ->post("/equipment/{$stock->id}/repairs", ['kind' => 'Плановое ТО', 'started_at' => '2026-09-01', 'ended_at' => '2026-09-03']);
        $this->assertSame('stock', $stock->refresh()->status);
    }

    public function test_only_managers_touch_repairs()
    {
        $unit = Equipment::factory()->ofType($this->type())->create();

        $this->actingAs($this->colleague())
            ->post("/equipment/{$unit->id}/repairs", ['kind' => 'Диагностика', 'started_at' => '2026-09-01'])
            ->assertForbidden();
    }

    public function test_a_unit_is_issued_to_a_colleague_and_to_nobody_else()
    {
        $unit = Equipment::factory()->ofType($this->type())->create();

        // Hardware is signed out by name: there is no department to hand it to.
        $this->actingAs($this->sysadmin())
            ->post("/equipment/{$unit->id}/issue", ['issued_at' => '2026-03-14'])
            ->assertSessionHasErrors('holder_user_id');

        $this->assertSame('stock', $unit->refresh()->status);
    }

    public function test_taking_a_unit_back_clears_who_had_it()
    {
        $employee = $this->colleague();
        $unit = Equipment::factory()->ofType($this->type())->issuedTo($employee->id)->create();

        $this->actingAs($this->sysadmin())
            ->post("/equipment/{$unit->id}/take", ['returned_at' => now()->toDateString()])
            ->assertSessionHasNoErrors();

        $unit->refresh();
        $this->assertSame('stock', $unit->status);
        $this->assertNull($unit->holder_user_id);
        $this->assertNull($unit->issued_at);
    }

    public function test_writing_a_unit_off_records_the_state_it_left_in()
    {
        $unit = Equipment::factory()->ofType($this->type())->create(['condition' => 'Рабочее']);

        $this->actingAs($this->sysadmin())
            ->post("/equipment/{$unit->id}/write-off", [
                'written_off_at' => '2026-09-20',
                'condition' => 'Не подлежит ремонту',
            ])
            ->assertSessionHasNoErrors();

        $unit->refresh();
        $this->assertSame('written_off', $unit->status);
        $this->assertSame('Не подлежит ремонту', $unit->condition);

        // Left alone when nothing was said, rather than wiped.
        $other = Equipment::factory()->ofType($this->type())->create(['condition' => 'Рабочее']);
        $this->actingAs($this->sysadmin())->post("/equipment/{$other->id}/write-off", ['written_off_at' => '2026-09-20']);
        $this->assertSame('Рабочее', $other->refresh()->condition);
    }

    public function test_a_written_off_unit_cannot_be_moved_again()
    {
        $employee = $this->colleague();
        $unit = Equipment::factory()->ofType($this->type())->create();
        $admin = $this->sysadmin();

        $this->actingAs($admin)
            ->post("/equipment/{$unit->id}/write-off", ['written_off_at' => '2026-02-02'])
            ->assertSessionHasNoErrors();
        $this->assertSame('written_off', $unit->refresh()->status);

        $card = "/equipment/{$unit->id}";
        $events = $unit->events()->count();

        // Out of the fleet for good: no issuing, returning or repairing it. Each
        // of those is asked by a dialog, so each is sent back with a sentence
        // rather than a refusal the dialog has no way of showing.
        foreach ([
            "{$card}/issue" => ['holder_user_id' => $employee->id, 'issued_at' => '2026-03-14'],
            "{$card}/take" => ['returned_at' => '2026-03-14'],
            "{$card}/write-off" => ['written_off_at' => '2026-03-14'],
        ] as $move => $fields) {
            $this->actingAs($admin)->from($card)->post($move, $fields)
                ->assertRedirect($card)
                ->assertSessionHas('notice', 'Списанное оборудование нельзя перемещать.');
        }

        $this->actingAs($admin)->from($card)
            ->post("{$card}/repairs", ['kind' => 'Диагностика', 'started_at' => '2026-03-14'])
            ->assertRedirect($card)
            ->assertSessionHas('notice', 'Списанное оборудование нельзя обслуживать.');

        // A stale page changes nothing: the unit stands as it was struck off,
        // and no entry or record was filed on the way.
        $unit->refresh();
        $this->assertSame('written_off', $unit->status);
        $this->assertNull($unit->holder_user_id);
        $this->assertSame('2026-02-02', $unit->written_off_at->toDateString());
        $this->assertSame(0, $unit->repairs()->count());
        $this->assertSame($events, $unit->events()->count());
    }

    public function test_only_managers_move_equipment()
    {
        $employee = $this->colleague();
        $unit = Equipment::factory()->ofType($this->type())->create();

        $this->actingAs($employee);
        $this->post("/equipment/{$unit->id}/issue", ['holder_user_id' => $employee->id, 'issued_at' => '2026-03-14'])->assertForbidden();
        $this->post("/equipment/{$unit->id}/take")->assertForbidden();
        $this->post("/equipment/{$unit->id}/write-off", ['written_off_at' => '2026-02-02'])->assertForbidden();
    }

    public function test_the_profile_shows_what_the_employee_holds()
    {
        $employee = $this->mayLookAround(User::factory()->has(UserDetail::factory(), 'details')->create());
        Equipment::factory()->ofType($this->type())->issuedTo($employee->id)->create(['inventory_number' => 'EV-0421']);
        // Somebody else's unit must not show up here.
        Equipment::factory()->ofType($this->type())->issuedTo(User::factory()->create()->id)->create();

        $this->actingAs($employee)
            ->get('/profile')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('employee.private.equipment', 1)
                ->where('employee.private.equipment.0.inventory_number', 'EV-0421')
            );
    }

    public function test_the_profile_journal_follows_what_passed_through_their_hands()
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        $admin = $this->sysadmin();
        $laptop = Equipment::factory()->ofType($this->type())->create(['name' => 'Ноутбук Dell']);
        $untouched = Equipment::factory()->ofType($this->type())->create(['name' => 'Чужой монитор']);

        // To the first colleague, serviced while he had it, back, then on to the second.
        $this->actingAs($admin)->post("/equipment/{$laptop->id}/issue", ['holder_user_id' => $first->id, 'issued_at' => '2026-01-10']);
        $this->actingAs($admin)->post("/equipment/{$laptop->id}/repairs", ['kind' => 'Замена клавиатуры', 'started_at' => '2026-02-01', 'ended_at' => '2026-02-03']);
        $this->actingAs($admin)->post("/equipment/{$laptop->id}/take", ['returned_at' => '2026-03-01']);
        $this->actingAs($admin)->post("/equipment/{$laptop->id}/issue", ['holder_user_id' => $second->id, 'issued_at' => '2026-03-02']);

        // Nothing of another unit he never held leaks in.
        $this->actingAs($admin)->post("/equipment/{$untouched->id}/issue", ['holder_user_id' => $second->id, 'issued_at' => '2026-01-01']);

        $this->actingAs($admin)
            ->get("/employees/{$first->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                // The handover, the service, the return, and where it went next.
                ->has('employee.private.equipment_history.events', 4)
                ->where('employee.private.equipment_history.events.0.unit.name', 'Ноутбук Dell')
            );
    }

    public function test_a_category_is_drawn_by_the_icon_it_was_given()
    {
        $admin = $this->sysadmin();

        // The form offers the drawings the interface has, and takes one of them.
        $this->actingAs($admin)
            ->get('/directories/equipment')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('icons', EquipmentIcons::KEYS)
                ->where('items', fn ($items) => collect($items)->firstWhere('name', 'Ноутбуки')['icon'] === 'laptop')
            );

        $this->actingAs($admin)
            ->post('/directories/equipment', ['name' => 'Планшеты', 'icon' => 'tablet'])
            ->assertSessionHasNoErrors();
        $this->assertSame('tablet', EquipmentType::firstWhere('name', 'Планшеты')->icon);

        // A drawing the interface cannot draw is refused rather than stored.
        $this->actingAs($admin)
            ->post('/directories/equipment', ['name' => 'Швабры', 'icon' => 'broom'])
            ->assertSessionHasErrors('icon');

        // And a category may go without one: then it is the plain box.
        $this->actingAs($admin)
            ->post('/directories/equipment', ['name' => 'Прочее'])
            ->assertSessionHasNoErrors();
        $this->assertNull(EquipmentType::firstWhere('name', 'Прочее')->icon);
    }

    public function test_the_directory_counts_units_in_service()
    {
        Equipment::factory(2)->ofType($this->type())->create();
        Equipment::factory()->ofType($this->type())->writtenOff()->create();

        $this->actingAs($this->sysadmin())
            ->get('/directories/equipment')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('items', fn ($items) => collect($items)->firstWhere('name', 'Ноутбуки')['users_count'] === 2)
            );
    }
}
