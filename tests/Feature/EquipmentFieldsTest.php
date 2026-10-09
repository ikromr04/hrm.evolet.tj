<?php

namespace Tests\Feature;

use App\Models\Equipment;
use App\Models\EquipmentField;
use App\Models\EquipmentFieldValue;
use App\Models\EquipmentType;
use App\Models\User;
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

    private function laptops(): EquipmentType
    {
        return EquipmentType::create(['name' => 'Ноутбуки', 'icon' => 'laptop']);
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

    public function test_the_two_a_unit_is_named_by_cannot_be_dropped_or_handed_over()
    {
        $type = $this->laptops();
        $this->actingAs($this->sysadmin());

        // A list without them is refused, with the field's own name in the sentence.
        $this->put("/directories/equipment/{$type->id}", ['name' => 'Ноутбуки', 'fields' => [$this->field()]])
            ->assertSessionHasErrors('fields');

        // And so is one that hands a role to another field.
        $roles = $this->roleFields($type);
        $this->put("/directories/equipment/{$type->id}", [
            'name' => 'Ноутбуки',
            'fields' => [...$roles, $this->field(['role' => 'title', 'name' => 'Второе название'])],
        ])->assertSessionHasErrors('fields');
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
        $monitors = EquipmentType::create(['name' => 'Мониторы', 'icon' => 'monitor']);

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
        $monitors = EquipmentType::create(['name' => 'Мониторы', 'icon' => 'monitor']);
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
        $type->fields()->create(['name' => 'Процессор', 'type' => 'text', 'position' => 0]);

        $this->actingAs($this->sysadmin())
            ->get('/equipment/create')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('options.types.0.name', 'Ноутбуки')
                ->where('options.types.0.fields.0.name', 'Процессор')
                ->where('options.types.0.fields.0.type', 'text')
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
        $type = EquipmentType::create(['name' => 'Периферия', 'has_accessories' => false]);
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
        $type = EquipmentType::create(['name' => 'Ноутбуки', 'has_accessories' => true]);
        $unit = Equipment::factory()->create(['equipment_type_id' => $type->id]);

        $this->actingAs($this->sysadmin())
            ->put("/equipment/{$unit->id}/accessories", ['accessories' => ['Блок питания 65 Вт', 'Сумка']])
            ->assertSessionHasNoErrors();

        $this->assertSame(['Блок питания 65 Вт', 'Сумка'], $unit->fresh()->accessories);
    }

    public function test_every_category_travels_with_whether_it_comes_with_anything()
    {
        EquipmentType::create(['name' => 'Периферия', 'has_accessories' => false]);

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
                ->where('items', fn ($items) => collect(collect($items)->firstWhere('name', 'Ноутбуки')['fields'])->first()['name'] === 'Разрешение')
            );
    }
}
