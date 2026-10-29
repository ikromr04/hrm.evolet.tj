<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Equipment;
use App\Models\EquipmentType;
use App\Models\Language;
use App\Models\Position;
use App\Models\User;
use App\Models\UserDetail;
use Database\Seeders\EquipmentTypeSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_search()
    {
        $this->getJson('/search?q=Азимова')->assertUnauthorized();
    }

    public function test_finds_working_employees_by_every_word_with_public_data_only()
    {
        $designer = Position::create(['name' => 'Дизайнер']);
        $nigina = User::factory()->create(['surname' => 'Азимова', 'name' => 'Нигина', 'email' => 'n.azimova@evolet.test']);
        $nigina->positions()->attach($designer);
        UserDetail::factory()->for($nigina)->create(['home_address' => 'г. Душанбе, ул. Азимова 1']);
        User::factory()->create(['surname' => 'Азимов', 'name' => 'Далер']);
        User::factory()->create(['surname' => 'Азимова', 'name' => 'Мадина', 'status' => 'fired']);
        User::factory()->create(['surname' => 'Азимова', 'name' => 'Зарина', 'status' => 'transferred']);

        // Named, not left to the factory: its pool of surnames holds "Азимов"
        // too, and a viewer who drew it would be counted among the matches.
        $this->actingAs($this->colleague(['surname' => 'Холов', 'name' => 'Бахром']));

        // Several words narrow it down; one of them may be the position. (SQLite in tests
        // ignores case for Latin letters only, so the query keeps the stored case.)
        $this->getJson('/search?q=Азимова Дизайнер')->assertOk()->assertExactJson([
            'employees' => [[
                'id' => $nigina->id,
                'name' => 'Азимова Нигина',
                'avatar' => null,
                'email' => 'n.azimova@evolet.test',
                'positions' => ['Дизайнер'],
            ]],
            'equipment' => [],
            'departments' => [],
            'positions' => [],
            'roles' => [],
            'equipmentTypes' => [],
            'languages' => [],
        ]);

        // People who left are not found; private fields are not searched.
        $this->getJson('/search?q=Азимов')->assertJsonCount(2, 'employees');
        $this->getJson('/search?q=Душанбе')->assertJsonCount(0, 'employees');
    }

    /**
     * A line of a card the viewer may not read is neither searched nor shown.
     *
     * Searching it is the same leak by a shorter road: type an address, get back
     * the one person it belongs to, and the answer is whose address it is —
     * which is the very thing the right was taken away to stop. The palette used
     * to search the address and hand it back besides.
     */
    public function test_a_line_nobody_may_read_is_neither_searched_nor_handed_back()
    {
        $nigina = User::factory()->create(['surname' => 'Азимова', 'name' => 'Нигина', 'email' => 'n.azimova@evolet.test']);

        $viewer = $this->colleague(['surname' => 'Холов', 'name' => 'Бахром']);
        $viewer->revokePermissionTo('employees.field.email');

        $this->actingAs($viewer);

        $this->getJson('/search?q=n.azimova@evolet.test')->assertOk()->assertJsonCount(0, 'employees');

        // Found by her name all the same, with the address left empty.
        $this->getJson('/search?q=Азимова')
            ->assertOk()
            ->assertJsonPath('employees.0.id', $nigina->id)
            ->assertJsonPath('employees.0.email', null);

        // And for somebody who may read it, both still work as they did.
        $this->actingAs($this->colleague(['surname' => 'Шарипов', 'name' => 'Фируз']));

        $this->getJson('/search?q=n.azimova@evolet.test')->assertOk()->assertJsonPath('employees.0.id', $nigina->id);
    }

    public function test_finds_departments_positions_and_roles()
    {
        $this->seed(RoleSeeder::class);
        $department = Department::create(['name' => 'Отдел Дизайна', 'abbreviation' => 'ОД']);
        $position = Position::create(['name' => 'Графический дизайнер']);

        $this->actingAs($this->colleague())
            ->getJson('/search?q=изайн')
            ->assertOk()
            // Typed in full and answered the way the pages name it.
            ->assertJsonPath('departments', [['id' => $department->id, 'name' => 'ОД', 'full_name' => 'Отдел Дизайна']])
            ->assertJsonPath('positions', [['id' => $position->id, 'name' => 'Графический дизайнер']])
            ->assertJsonPath('roles.0.name', 'graphic-designer');

        // And found by that short name, which is all anybody ever sees of it.
        $this->getJson('/search?q=ОД')
            ->assertOk()
            ->assertJsonPath('departments', [['id' => $department->id, 'name' => 'ОД', 'full_name' => 'Отдел Дизайна']]);
    }

    public function test_finds_a_unit_by_anything_printed_on_it_or_by_who_has_it()
    {
        $this->seed(EquipmentTypeSeeder::class);
        $type = EquipmentType::firstWhere('name', 'Ноутбуки');
        $holder = User::factory()->create(['surname' => 'Рахимов', 'name' => 'Фарход']);

        $laptop = Equipment::factory()->ofType($type)->issuedTo($holder->id)->create([
            'name' => 'Ноутбук Dell Latitude 5440',
            'inventory_number' => 'EV-0421',
        ]);
        $laptop->fieldValues()->updateOrCreate(
            ['equipment_field_id' => $type->fields()->firstWhere('name', 'Серийный номер')->id],
            ['value' => '7K2L9P3'],
        );

        // A unit of another kind, with nothing of the laptop printed on it, so
        // each search below has exactly one right answer.
        $monitors = EquipmentType::firstWhere('name', 'Мониторы');
        $other = Equipment::factory()->ofType($monitors)->create(['name' => 'Монитор', 'inventory_number' => 'EV-9999']);
        $other->fieldValues()->updateOrCreate(
            ['equipment_field_id' => $monitors->fields()->firstWhere('name', 'Серийный номер')->id],
            ['value' => 'ZZZ'],
        );

        // Somebody who sees the whole fleet: search reaches exactly as far as
        // the list does, and a colleague only ever holds their own units.
        $this->actingAs($this->colleague(['surname' => 'Холов', 'name' => 'Бахром'])->givePermissionTo('equipment.view.all'));

        // The sticker, the serial, and the two words somebody would actually type.
        foreach (['EV-0421', '7K2L9P3', 'Latitude'] as $term) {
            $this->getJson('/search?q='.urlencode($term))
                ->assertOk()
                ->assertJsonPath('equipment.0.id', $laptop->id)
                ->assertJsonCount(1, 'equipment');
        }

        $this->getJson('/search?q='.urlencode('Ноутбук Рахимов'))
            ->assertJsonPath('equipment.0.inventory_number', 'EV-0421')
            ->assertJsonPath('equipment.0.holder', 'Рахимов Фарход')
            ->assertJsonPath('equipment.0.icon', 'laptop');

        // And the category itself, for jumping to everything of that kind.
        $this->getJson('/search?q='.urlencode('Ноутбуки'))
            ->assertJsonPath('equipmentTypes.0.id', $type->id);
    }

    public function test_finds_a_language_people_are_filtered_by()
    {
        $language = Language::create(['name' => 'Английский']);

        $this->actingAs($this->colleague())
            ->getJson('/search?q='.urlencode('Англ'))
            ->assertOk()
            ->assertJsonPath('languages', [['id' => $language->id, 'name' => 'Английский']]);
    }

    public function test_an_empty_query_finds_nothing()
    {
        User::factory()->create();

        $this->actingAs($this->colleague())
            ->getJson('/search?q=%20')
            ->assertExactJson([
                'employees' => [],
                'equipment' => [],
                'departments' => [],
                'positions' => [],
                'roles' => [],
                'equipmentTypes' => [],
                'languages' => [],
            ]);
    }
}
