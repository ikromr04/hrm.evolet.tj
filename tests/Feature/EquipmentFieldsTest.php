<?php

namespace Tests\Feature;

use App\Models\Equipment;
use App\Models\EquipmentField;
use App\Models\EquipmentFieldValue;
use App\Models\EquipmentType;
use App\Models\User;
use App\Support\EquipmentName;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * What units of a category are described by.
 *
 * A monitor has a diagonal and no processor, a phone has an IMEI. Which fields a
 * category has is decided in the directory, so the card of a unit asks whatever
 * its category says and nothing more — and a new field costs no deployment.
 */
class EquipmentFieldsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    /**
     * The one account that passes every check, whatever the rights say: these
     * tests are not about what a position may do.
     */
    private function sysadmin(): User
    {
        return User::factory()->create()->assignRole('sysadmin');
    }

    /**
     * A category as the directory makes one: with the two fields a unit is
     * usually named by at the head of its list, and room for its own after them.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function category(string $name, array $attributes = []): EquipmentType
    {
        $type = EquipmentType::create(['name' => $name, ...$attributes]);

        foreach (array_keys(EquipmentField::ROLES) as $position => $role) {
            $type->fields()->create([
                'role' => $role,
                'name' => EquipmentField::ROLES[$role],
                'type' => 'text',
                'required' => true,
                'position' => $position,
            ]);
        }

        return $type->load('fields');
    }

    private function laptops(): EquipmentType
    {
        return $this->category('Ноутбуки', ['icon' => 'laptop']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function field(array $overrides = []): array
    {
        return ['name' => 'Процессор', 'type' => 'text', 'required' => false, 'options' => [], ...$overrides];
    }

    /**
     * The two a unit cannot be without, as the dialog sends them. A category
     * that already holds them sends them back by their ids; one that does not
     * — a type made by hand in a test — sends them as new rows. Every saved
     * list carries both, so every request here does too.
     *
     * @return list<array<string, mixed>>
     */
    private function roleFields(?EquipmentType $type = null): array
    {
        $held = $type?->fields()->whereNotNull('role')->get()->keyBy('role') ?? collect();

        return collect(EquipmentField::ROLES)
            ->map(fn (string $name, string $role) => [
                ...($held->has($role) ? ['id' => $held[$role]->id] : []),
                'role' => $role,
                'name' => $held[$role]->name ?? $name,
                'type' => 'text',
                'required' => true,
                'options' => [],
            ])
            ->values()
            ->all();
    }

    public function test_a_category_is_given_its_fields_in_the_directory()
    {
        $this->actingAs($this->sysadmin())
            ->post('/directories/equipment', [
                'name' => 'Мониторы',
                'icon' => 'monitor',
                'fields' => [
                    ...$this->roleFields(),
                    $this->field(['name' => 'Диагональ', 'type' => 'number', 'required' => true]),
                    $this->field(['name' => 'Разрешение', 'type' => 'select', 'options' => ['1920×1080', '2560×1440']]),
                ],
            ])
            ->assertSessionHasNoErrors();

        $fields = EquipmentType::firstWhere('name', 'Мониторы')->fields;

        // What a unit is called and its number lead the list, then what the
        // dialog asked for.
        $this->assertSame(['Наименование', 'Инвентарный номер', 'Диагональ', 'Разрешение'], $fields->pluck('name')->all());
        $this->assertSame(['title', 'inventory', null, null], $fields->pluck('role')->all());
        $this->assertTrue($fields[2]->required);
        $this->assertSame('number', $fields[2]->type);
        $this->assertSame(['1920×1080', '2560×1440'], $fields[3]->options);
        // The order they were put in is the order they are read in.
        $this->assertSame([0, 1, 2, 3], $fields->pluck('position')->all());
    }

    public function test_a_field_that_is_kept_survives_a_rename_of_the_category()
    {
        $type = $this->laptops();
        $field = $type->fields()->create(['name' => 'Процессор', 'type' => 'text', 'position' => 0]);
        $unit = Equipment::factory()->create(['equipment_type_id' => $type->id]);
        $unit->fieldValues()->create(['equipment_field_id' => $field->id, 'value' => 'Intel Core i5']);

        $this->actingAs($this->sysadmin())
            ->put("/directories/equipment/{$type->id}", [
                'name' => 'Ноутбуки и планшеты',
                'icon' => 'laptop',
                // Same field, kept by its id, renamed.
                'fields' => [...$this->roleFields($type), $this->field(['id' => $field->id, 'name' => 'Процессор (CPU)'])],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('Процессор (CPU)', $field->fresh()->name);
        $this->assertSame('Intel Core i5', $unit->fieldValues()->whereRelation('field', 'role', null)->sole()->value);
    }

    public function test_a_field_left_out_is_dropped_with_everything_written_in_it()
    {
        $type = $this->laptops();
        $field = $type->fields()->create(['name' => 'Процессор', 'type' => 'text', 'position' => 0]);
        $unit = Equipment::factory()->create(['equipment_type_id' => $type->id]);
        $unit->fieldValues()->create(['equipment_field_id' => $field->id, 'value' => 'Intel Core i5']);

        $this->actingAs($this->sysadmin())
            // Everything the category had, bar the one left out.
            ->put("/directories/equipment/{$type->id}", ['name' => 'Ноутбуки', 'icon' => 'laptop', 'fields' => $this->roleFields($type)])
            ->assertSessionHasNoErrors();

        $this->assertNull($field->fresh());
        $this->assertSame(0, EquipmentFieldValue::where('equipment_field_id', $field->id)->count());
        // The two a unit is named by stay whatever the list says.
        $this->assertSame(['title', 'inventory'], $type->fields()->orderBy('position')->pluck('role')->all());
    }

    public function test_the_two_a_unit_is_named_by_can_be_dropped_like_any_other_field()
    {
        $type = $this->laptops();
        $unit = Equipment::factory()->create(['equipment_type_id' => $type->id, 'name' => 'Ноутбук Dell']);

        $this->actingAs($this->sysadmin())
            // Everything the category had, bar the two it named its units by.
            ->put("/directories/equipment/{$type->id}", ['name' => 'Ноутбуки', 'fields' => [$this->field()]])
            ->assertSessionHasNoErrors();

        $this->assertSame(['Процессор'], $type->fields()->pluck('name')->all());
        // What the units had written in them goes with the fields, as it does
        // for any field somebody drops.
        $this->assertNull($unit->fresh()->name);
        $this->assertSame(0, $unit->fieldValues()->count());
    }

    public function test_a_category_may_end_up_with_no_fields_at_all()
    {
        $type = $this->laptops();
        $admin = $this->sysadmin();

        $this->actingAs($admin)
            ->put("/directories/equipment/{$type->id}", ['name' => 'Ноутбуки', 'fields' => []])
            ->assertSessionHasNoErrors();

        $this->assertSame(0, $type->fields()->count());

        // And one can be opened with none: a box of patch cords is described by
        // the shelf it is on and nothing else.
        $this->actingAs($admin)
            ->post('/directories/equipment', ['name' => 'Расходники', 'fields' => []])
            ->assertSessionHasNoErrors();

        $this->assertSame(0, EquipmentType::firstWhere('name', 'Расходники')->fields()->count());
    }

    public function test_a_role_may_be_handed_to_another_field_but_not_to_two_at_once()
    {
        $type = $this->laptops();
        $this->actingAs($this->sysadmin());

        // Two fields claiming one role is the one list that is refused: nothing
        // could then say which of them names a unit.
        $this->put("/directories/equipment/{$type->id}", [
            'name' => 'Ноутбуки',
            'fields' => [...$this->roleFields($type), $this->field(['role' => 'title', 'name' => 'Второе название'])],
        ])->assertSessionHasErrors('fields');

        // Handing it over is not: the field that carries the role afterwards is
        // the one a unit is named by.
        $this->put("/directories/equipment/{$type->id}", [
            'name' => 'Ноутбуки',
            'fields' => [$this->field(['role' => 'title', 'name' => 'Модель'])],
        ])->assertSessionHasNoErrors();

        $model = $type->fields()->sole();
        $this->assertSame(['Модель', 'title'], [$model->name, $model->role]);

        $unit = Equipment::factory()->create(['equipment_type_id' => $type->id, 'name' => 'Latitude 5440']);

        $this->assertSame('Latitude 5440', $unit->fresh()->name);
        $this->assertSame('Latitude 5440', $unit->fieldValues()->where('equipment_field_id', $model->id)->sole()->value);
    }

    public function test_two_fields_cannot_share_a_name_and_a_list_needs_something_to_choose_from()
    {
        $this->actingAs($this->sysadmin())
            ->post('/directories/equipment', [
                'name' => 'Мониторы',
                'fields' => [$this->field(['name' => 'Диагональ']), $this->field(['name' => 'диагональ'])],
            ])
            ->assertSessionHasErrors('fields.1.name');

        $this->actingAs($this->sysadmin())
            ->post('/directories/equipment', [
                'name' => 'Периферия',
                'fields' => [$this->field(['name' => 'Подключение', 'type' => 'select', 'options' => []])],
            ])
            ->assertSessionHasErrors('fields.0.options');

        $this->assertSame(0, EquipmentType::count());
    }

    public function test_a_unit_of_a_category_that_names_nothing_goes_on_the_books_all_the_same()
    {
        // A category with no fields whatever: nothing is asked about its units
        // beyond which category they belong to.
        $type = EquipmentType::factory()->withoutIdentity()->create(['name' => 'Расходники']);
        $admin = $this->sysadmin();

        $this->actingAs($admin)
            ->post('/equipment', ['equipment_type_id' => $type->id])
            ->assertSessionHasNoErrors();

        $unit = Equipment::sole();

        $this->assertNull($unit->name);
        $this->assertNull($unit->inventory_number);
        // Nothing was written anywhere, and the category did not grow a field
        // to hold what the form never asked for.
        $this->assertSame(0, $unit->fieldValues()->count());
        $this->assertSame(0, $type->fields()->count());
        // The arrival is in the journal, remembered by nothing in particular.
        $this->assertNull($unit->events()->sole()->note);

        // Its card opens, with nowhere to walk from a unit that is the only one
        // of its kind on the shelf. The card is handed the two as they are
        // rather than as they read: its own forms open on what it was given.
        $this->actingAs($admin)
            ->get("/equipment/{$unit->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('unit.name', null)
                ->where('unit.inventory_number', null)
                ->where('unit.fields', [])
                ->where('neighbours.prev', null)
                ->where('neighbours.next', null)
                // The words the heading puts there instead, from the one place
                // every screen and every letter takes them.
                ->where('nameless', EquipmentName::NAMELESS)
            );

        // And it is in the list, and in the search, under its category — in
        // both of them a row that says it has no name rather than a blank line.
        $this->actingAs($admin)
            ->get('/equipment?view=list&tab=stock')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('equipment.data', 1)
                ->where('equipment.data.0.name', EquipmentName::NAMELESS)
                ->where('equipment.data.0.inventory_number', EquipmentName::NO_NUMBER)
                ->where('equipment.data.0.details', null)
                ->where('equipment.data.0.type', 'Расходники')
            );

        $this->actingAs($admin)
            ->getJson('/search?q='.urlencode('Расходники'))
            ->assertOk()
            ->assertJsonCount(1, 'equipment')
            ->assertJsonPath('equipment.0.id', $unit->id)
            ->assertJsonPath('equipment.0.name', EquipmentName::NAMELESS);
    }

    public function test_a_unit_with_no_name_is_handed_over_written_off_and_named_in_the_letters()
    {
        $type = EquipmentType::factory()->withoutIdentity()->create(['name' => 'Расходники']);
        $unit = Equipment::factory()->create(['equipment_type_id' => $type->id]);
        $holder = $this->colleague();

        $this->actingAs($this->sysadmin())
            ->post("/equipment/{$unit->id}/issue", ['holder_user_id' => $holder->id, 'issued_at' => now()->toDateString()])
            ->assertSessionHasNoErrors();

        $this->assertSame($holder->id, $unit->fresh()->holder_user_id);
        // The letter still reads as a sentence: the unit it is about has no name
        // to put in it, and says so instead of leaving a gap.
        $this->assertSame(
            'Вам выдано: Без наименования',
            $holder->notifications()->latest('id')->first()->data['text'],
        );

        // The holder's own card names it the same way as it lists what they hold.
        $this->actingAs($this->sysadmin())
            ->get("/employees/{$holder->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('employee.private.equipment.0.name', EquipmentName::NAMELESS)
                ->where('employee.private.equipment.0.inventory_number', EquipmentName::NO_NUMBER)
            );

        $this->actingAs($this->sysadmin())
            ->post("/equipment/{$unit->id}/write-off", ['written_off_at' => now()->toDateString(), 'condition' => 'Израсходовано'])
            ->assertSessionHasNoErrors();

        $this->assertSame('written_off', $unit->fresh()->status);

        // The journal names it as the list does, so a line is about a unit
        // rather than about nothing.
        $this->actingAs($this->sysadmin())
            ->get('/equipment/journal')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('events.data.0.unit.name', EquipmentName::NAMELESS)
                ->where('events.data.0.unit.inventory_number', EquipmentName::NO_NUMBER)
                ->where('events.data.0.unit.type', 'Расходники')
            );
    }

    public function test_a_unit_with_no_name_reads_last_whichever_way_the_column_is_sorted()
    {
        $named = Equipment::factory()->create(['equipment_type_id' => $this->laptops()->id, 'name' => 'Ноутбук Dell']);
        $nameless = Equipment::factory()->create(['equipment_type_id' => EquipmentType::factory()->withoutIdentity()->create(['name' => 'Расходники'])->id]);
        $admin = $this->sysadmin();

        foreach (['asc', 'desc'] as $direction) {
            $this->actingAs($admin)
                ->get("/equipment?view=list&tab=stock&sort=name&direction={$direction}")
                ->assertInertia(fn (AssertableInertia $page) => $page
                    ->where('equipment.data.0.id', $named->id)
                    ->where('equipment.data.1.id', $nameless->id)
                );
        }
    }

    public function test_an_inventory_number_is_only_checked_where_a_category_keeps_one()
    {
        $taken = Equipment::factory()->create(['equipment_type_id' => $this->laptops()->id, 'inventory_number' => 'EV-0421']);
        $loose = EquipmentType::factory()->withoutIdentity()->create(['name' => 'Расходники']);
        $admin = $this->sysadmin();

        // A category with no inventory field has no number to be unique: the
        // rule is not asked at all, and what a stale form sends is let go.
        $this->actingAs($admin)
            ->post('/equipment', ['equipment_type_id' => $loose->id, 'inventory_number' => 'EV-0421'])
            ->assertSessionHasNoErrors();

        $this->assertSame('EV-0421', $taken->fresh()->inventory_number);
        $this->assertSame(0, Equipment::whereKeyNot($taken->id)->sole()->fieldValues()->count());

        // A category that keeps one still allows it only once.
        $this->actingAs($admin)
            ->post('/equipment', [
                'equipment_type_id' => $taken->equipment_type_id,
                'name' => 'Второй ноутбук',
                'inventory_number' => 'EV-0421',
            ])
            ->assertSessionHasErrors('inventory_number');
    }

    public function test_a_unit_is_filed_with_what_its_category_asks_about()
    {
        $type = $this->laptops();
        $cpu = $type->fields()->create(['name' => 'Процессор', 'type' => 'text', 'position' => 0]);
        $warranty = $type->fields()->create(['name' => 'Гарантия до', 'type' => 'date', 'position' => 1]);
        $corporate = $type->fields()->create(['name' => 'Корпоративная', 'type' => 'boolean', 'position' => 2]);

        $this->actingAs($this->sysadmin())
            ->post('/equipment', [
                'equipment_type_id' => $type->id,
                'name' => 'Ноутбук Dell Latitude 5440',
                'inventory_number' => 'EV-0421',
                'fields' => [$cpu->id => 'Intel Core i5-1335U', $warranty->id => '2027-05-01', $corporate->id => true],
            ])
            ->assertSessionHasNoErrors();

        $unit = Equipment::whereInventory('EV-0421')->first();

        $this->assertSame('Intel Core i5-1335U', $unit->fieldValues()->where('equipment_field_id', $cpu->id)->sole()->value);
        $this->assertSame('2027-05-01', $unit->fieldValues()->where('equipment_field_id', $warranty->id)->sole()->value);
        $this->assertSame('1', $unit->fieldValues()->where('equipment_field_id', $corporate->id)->sole()->value);
    }

    public function test_a_field_is_checked_against_what_it_holds()
    {
        $type = $this->laptops();
        $year = $type->fields()->create(['name' => 'Год выпуска', 'type' => 'number', 'position' => 0]);
        $needed = $type->fields()->create(['name' => 'Процессор', 'type' => 'text', 'required' => true, 'position' => 1]);
        $list = $type->fields()->create(['name' => 'Формат', 'type' => 'select', 'options' => ['A4', 'A3'], 'position' => 2]);

        $this->actingAs($this->sysadmin())
            ->post('/equipment', [
                'equipment_type_id' => $type->id,
                'name' => 'Ноутбук',
                'inventory_number' => 'EV-0001',
                'fields' => [$year->id => 'позавчера', $needed->id => '', $list->id => 'A5'],
            ])
            ->assertSessionHasErrors(["fields.{$year->id}", "fields.{$needed->id}", "fields.{$list->id}"]);

        $this->assertSame(0, Equipment::count());
    }

    public function test_a_unit_is_never_asked_about_another_categorys_fields()
    {
        $laptops = $this->laptops();
        $laptops->fields()->create(['name' => 'Процессор', 'type' => 'text', 'required' => true, 'position' => 0]);
        $monitors = $this->category('Мониторы', ['icon' => 'monitor']);

        // A monitor goes on the books without a word about processors, required
        // or not: the field belongs to another category.
        $this->actingAs($this->sysadmin())
            ->post('/equipment', ['equipment_type_id' => $monitors->id, 'name' => 'Монитор Dell', 'inventory_number' => 'EV-9999'])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Equipment::count());
        // Its name and its number are field values of its own; nothing else was asked.
        $this->assertSame(0, EquipmentFieldValue::whereRelation('field', 'role', null)->count());
    }

    public function test_the_card_shows_the_fields_of_its_own_category_with_what_the_unit_has()
    {
        $type = $this->laptops();
        $cpu = $type->fields()->create(['name' => 'Процессор', 'type' => 'text', 'position' => 0]);
        $ram = $type->fields()->create(['name' => 'Память / диск', 'type' => 'text', 'position' => 1]);
        $unit = Equipment::factory()->create(['equipment_type_id' => $type->id]);
        $unit->fieldValues()->create(['equipment_field_id' => $cpu->id, 'value' => 'Intel Core i5']);

        $this->actingAs($this->sysadmin())
            ->get("/equipment/{$unit->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                // The page carries every field the category has, the two it names
                // a unit by included; what the card draws as "характеристики" is
                // the rest, and that is what this is about.
                ->where('unit.fields', function ($fields) {
                    $own = collect($fields)->whereNull('role')->values();

                    return $own->pluck('name')->all() === ['Процессор', 'Память / диск']
                        && $own[0]['value'] === 'Intel Core i5'
                        // Asked about and left empty is still asked about.
                        && $own[1]['value'] === null;
                })
                // Every category travels with its own fields, for the form that
                // can move the unit from one to another.
                ->where('types.0.fields', fn ($fields) => collect($fields)->whereNull('role')->values()[1]['name'] === 'Память / диск')
            );

        // Its own fields keep the order the dialog gave them, whatever the
        // two a unit is named by do.
        $this->assertSame($ram->id, $type->fields()->whereNull('role')->orderBy('position')->get()[1]->id);
    }

    public function test_the_journal_says_what_moved_in_a_categorys_field()
    {
        $type = $this->laptops();
        $cpu = $type->fields()->create(['name' => 'Процессор', 'type' => 'text', 'position' => 0]);
        $unit = Equipment::factory()->create(['equipment_type_id' => $type->id, 'name' => 'Ноутбук Dell']);
        $unit->fieldValues()->create(['equipment_field_id' => $cpu->id, 'value' => 'Intel Core i5']);
        $before = $unit->events()->count();

        $this->actingAs($this->sysadmin())
            ->put("/equipment/{$unit->id}/specs", [
                'equipment_type_id' => $type->id,
                'name' => $unit->name,
                'inventory_number' => $unit->inventory_number,
                'fields' => [$cpu->id => 'Intel Core i7-1255U'],
            ])
            ->assertSessionHasNoErrors();

        // Nothing else moved, so the entry is about the field alone — and it is
        // named by the field rather than by a column nobody would recognise.
        $this->assertSame($before + 1, $unit->events()->count());
        $entry = $unit->events()->latest('id')->first();

        $this->assertSame('updated', $entry->kind);
        $this->assertSame(['Intel Core i5', 'Intel Core i7-1255U'], $entry->diff['Процессор']);
    }

    public function test_a_yes_or_no_reads_as_one_in_the_journal()
    {
        $type = $this->laptops();
        $own = $type->fields()->create(['name' => 'Корпоративная', 'type' => 'boolean', 'position' => 0]);
        $unit = Equipment::factory()->create(['equipment_type_id' => $type->id]);

        $this->actingAs($this->sysadmin())
            ->put("/equipment/{$unit->id}/specs", [
                'equipment_type_id' => $type->id,
                'name' => $unit->name,
                'inventory_number' => $unit->inventory_number,
                'fields' => [$own->id => true],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame([null, 'Да'], $unit->events()->latest('id')->first()->diff['Корпоративная']);
    }

    public function test_moving_a_unit_to_another_category_leaves_what_it_had_where_it_was()
    {
        $laptops = $this->laptops();
        $cpu = $laptops->fields()->create(['name' => 'Процессор', 'type' => 'text', 'position' => 0]);
        $monitors = $this->category('Мониторы', ['icon' => 'monitor']);
        $size = $monitors->fields()->create(['name' => 'Диагональ', 'type' => 'number', 'position' => 0]);

        $unit = Equipment::factory()->create(['equipment_type_id' => $laptops->id]);
        $unit->fieldValues()->create(['equipment_field_id' => $cpu->id, 'value' => 'Intel Core i5']);

        $this->actingAs($this->sysadmin())
            ->put("/equipment/{$unit->id}/specs", [
                'equipment_type_id' => $monitors->id,
                'name' => 'Монитор Dell P2422H',
                'inventory_number' => $unit->inventory_number,
                'fields' => [$size->id => '24'],
            ])
            ->assertSessionHasNoErrors();

        // The card now asks about a diagonal, and only about that.
        $this->assertSame($monitors->id, $unit->fresh()->equipment_type_id);
        $this->assertSame('24', $unit->fieldValues()->where('equipment_field_id', $size->id)->sole()->value);
        // What it said as a laptop is still on file: a unit filed under the wrong
        // category and put back should not have lost anything on the way.
        $this->assertSame('Intel Core i5', $unit->fieldValues()->where('equipment_field_id', $cpu->id)->sole()->value);
    }

    public function test_emptying_a_field_leaves_no_value_behind()
    {
        $type = $this->laptops();
        $cpu = $type->fields()->create(['name' => 'Процессор', 'type' => 'text', 'position' => 0]);
        $unit = Equipment::factory()->create(['equipment_type_id' => $type->id]);
        $unit->fieldValues()->create(['equipment_field_id' => $cpu->id, 'value' => 'Intel Core i5']);

        $this->actingAs($this->sysadmin())
            ->put("/equipment/{$unit->id}/specs", [
                'equipment_type_id' => $type->id,
                'name' => $unit->name,
                'inventory_number' => $unit->inventory_number,
                'fields' => [$cpu->id => ''],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(0, $unit->fieldValues()->whereRelation('field', 'role', null)->count());
        $this->assertSame(['Intel Core i5', null], $unit->events()->latest('id')->first()->diff['Процессор']);
    }

    public function test_the_form_for_a_new_unit_carries_every_categorys_fields()
    {
        $type = $this->laptops();
        $field = $type->fields()->create(['name' => 'Процессор', 'type' => 'text', 'position' => 0]);

        $this->actingAs($this->sysadmin())
            ->get('/equipment/create')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('options.types.0.name', 'Ноутбуки')
                // Every field the category has travels with it, the ones a unit
                // is named by included; the form asks for those by themselves,
                // and this is about the rest.
                ->where('options.types.0.fields', fn ($fields) => collect($fields)
                    ->whereNull('role')
                    ->values()
                    ->all() === [['id' => $field->id, 'name' => 'Процессор', 'role' => null, 'type' => 'text', 'options' => [], 'required' => false]])
            );
    }

    public function test_a_new_category_starts_with_the_two_a_unit_is_named_by()
    {
        $this->actingAs($this->sysadmin())
            ->get('/directories/equipment')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                // Nothing else: what describes a monitor says nothing about a cable.
                ->has('defaultFields', 2)
                ->where('defaultFields.0', ['role' => 'title', 'name' => 'Наименование', 'type' => 'text', 'options' => [], 'required' => true])
                ->where('defaultFields.1.role', 'inventory')
            );
    }

    public function test_a_category_says_whether_its_units_come_with_anything()
    {
        $this->actingAs($this->sysadmin())
            ->post('/directories/equipment', ['name' => 'Периферия', 'fields' => $this->roleFields(), 'has_accessories' => false])
            ->assertSessionHasNoErrors();

        $type = EquipmentType::firstWhere('name', 'Периферия');
        $this->assertFalse($type->has_accessories);

        // A category says its units do unless somebody says otherwise.
        $this->actingAs($this->sysadmin())
            ->post('/directories/equipment', ['name' => 'Ноутбуки', 'fields' => $this->roleFields()])
            ->assertSessionHasNoErrors();
        $this->assertTrue(EquipmentType::firstWhere('name', 'Ноутбуки')->has_accessories);

        // And it can change its mind later.
        $this->actingAs($this->sysadmin())
            ->put("/directories/equipment/{$type->id}", ['name' => 'Периферия', 'fields' => $this->roleFields($type), 'has_accessories' => true])
            ->assertSessionHasNoErrors();
        $this->assertTrue($type->fresh()->has_accessories);
    }

    public function test_a_unit_of_a_category_without_accessories_has_no_list_to_show_or_edit()
    {
        $type = $this->category('Периферия', ['has_accessories' => false]);
        $unit = Equipment::factory()->create(['equipment_type_id' => $type->id]);
        $admin = $this->sysadmin();

        // The card leaves the block out rather than showing an empty one.
        $this->actingAs($admin)
            ->get("/equipment/{$unit->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('unit.accessories', null));

        // And there is nothing to fill in, whatever a form sends.
        $this->actingAs($admin)
            ->put("/equipment/{$unit->id}/accessories", ['accessories' => ['Кабель USB']])
            ->assertForbidden();

        $this->assertNull($unit->fresh()->accessories);
    }

    public function test_a_unit_of_a_category_with_accessories_keeps_its_list()
    {
        $type = $this->category('Ноутбуки', ['has_accessories' => true]);
        $unit = Equipment::factory()->create(['equipment_type_id' => $type->id]);

        $this->actingAs($this->sysadmin())
            ->put("/equipment/{$unit->id}/accessories", ['accessories' => ['Блок питания 65 Вт', 'Сумка']])
            ->assertSessionHasNoErrors();

        $this->assertSame(['Блок питания 65 Вт', 'Сумка'], $unit->fresh()->accessories);
    }

    public function test_every_category_travels_with_whether_it_comes_with_anything()
    {
        $this->category('Периферия', ['has_accessories' => false]);

        $this->actingAs($this->sysadmin())
            ->get('/equipment/create')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('options.types', fn ($types) => collect($types)->firstWhere('name', 'Периферия')['has_accessories'] === false)
            );
    }

    public function test_the_directory_shows_a_category_with_its_fields()
    {
        $type = $this->laptops();
        $type->fields()->create(['name' => 'Разрешение', 'type' => 'select', 'options' => ['A4'], 'position' => 0]);

        $this->actingAs($this->sysadmin())
            ->get('/directories/equipment')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('fieldTypes', count(EquipmentField::TYPES))
                ->where('items', fn ($items) => collect(collect($items)->firstWhere('name', 'Ноутбуки')['fields'])
                    ->whereNull('role')
                    ->first()['name'] === 'Разрешение')
            );
    }
}
