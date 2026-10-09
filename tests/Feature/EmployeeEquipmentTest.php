<?php

namespace Tests\Feature;

use App\Models\Equipment;
use App\Models\EquipmentType;
use App\Models\User;
use Database\Seeders\EquipmentTypeSeeder;
use Database\Seeders\PositionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Moving equipment from a colleague's card.
 *
 * The section used to be something to read: what they hold, and what has
 * happened to it. Handing a unit over from it is two jobs at once and takes the
 * rights of both — the equipment block of this card, because that is the line
 * being written, and the right to hand units over at all — so the card offers
 * it only to whoever holds the pair, and sends the free units only to them.
 *
 * Taking a unit back and striking it off are offered from the row of the unit
 * itself, and each is asked of that very unit: the card shows a move only where
 * the route behind it would go through.
 */
class EmployeeEquipmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RoleSeeder::class, PositionSeeder::class, EquipmentTypeSeeder::class]);
    }

    /** Somebody whose position holds exactly these rights, plus the staff list. */
    private function withRights(string ...$rights): User
    {
        $role = Role::create(['name' => 'r-'.Role::count(), 'title' => 'Роль '.Role::count(), 'guard_name' => 'web']);
        $role->syncPermissions(['employees.view', ...$rights]);

        return User::factory()->create()->assignRole($role);
    }

    /** A free unit with a name and a number to recognise it by. */
    private function unit(string $name, string $number): Equipment
    {
        return Equipment::factory()
            ->ofType(EquipmentType::firstWhere('name', 'Ноутбуки'))
            ->create(['name' => $name, 'inventory_number' => $number]);
    }

    /** The same, on somebody's desk, in a state the forms can open on. */
    private function held(User $holder, string $name, string $number): Equipment
    {
        return Equipment::factory()
            ->ofType(EquipmentType::firstWhere('name', 'Ноутбуки'))
            ->issuedTo($holder->id)
            ->create(['name' => $name, 'inventory_number' => $number, 'condition' => 'Рабочее, следы эксплуатации']);
    }

    public function test_the_card_offers_issuing_and_names_what_is_free()
    {
        $laptop = $this->unit('Ноутбук Dell Latitude 5440', 'EV-0001');
        $employee = User::factory()->create();

        $keeper = $this->withRights('employees.field.equipment', 'employees.edit.equipment', 'equipment.issue');

        $this->actingAs($keeper)->get("/employees/{$employee->id}")->assertInertia(fn (AssertableInertia $page) => $page
            ->where('canIssue', true)
            // The name and the number are fields of the unit's category, read
            // off the unit rather than off its row.
            ->where('stock', [['id' => $laptop->id, 'name' => 'Ноутбук Dell Latitude 5440', 'inventory_number' => 'EV-0001']])
        );
    }

    public function test_what_is_already_out_is_not_offered_again()
    {
        $holder = User::factory()->create();
        Equipment::factory()
            ->ofType(EquipmentType::firstWhere('name', 'Мониторы'))
            ->issuedTo($holder->id)
            ->create(['name' => 'Монитор Dell P2422H', 'inventory_number' => 'EV-0002']);
        $this->unit('Ноутбук HP ProBook 450 G9', 'EV-0003');

        $keeper = $this->withRights('employees.field.equipment', 'employees.edit.equipment', 'equipment.issue');

        $this->actingAs($keeper)->get("/employees/{$holder->id}")->assertInertia(fn (AssertableInertia $page) => $page
            ->has('stock', 1)
            ->where('stock.0.inventory_number', 'EV-0003')
        );
    }

    public function test_reading_the_section_is_not_handing_units_out()
    {
        $this->unit('Ноутбук Acer Aspire 5', 'EV-0004');
        $employee = User::factory()->create();

        // The block is read but not written: nothing to hand over with.
        $reader = $this->withRights('employees.field.equipment', 'equipment.issue');

        $this->actingAs($reader)->get("/employees/{$employee->id}")->assertInertia(fn (AssertableInertia $page) => $page
            ->where('canIssue', false)
            ->where('stock', [])
        );

        $this->actingAs($reader)->post("/employees/{$employee->id}/equipment", ['equipment' => []])->assertForbidden();
    }

    public function test_the_block_alone_does_not_hand_units_out()
    {
        $laptop = $this->unit('Ноутбук Lenovo ThinkPad E14', 'EV-0005');
        $employee = User::factory()->create();

        // The whole block is theirs to change, but the fleet is not theirs to move.
        $editor = $this->withRights('employees.field.equipment', 'employees.edit.equipment');

        $this->actingAs($editor)->get("/employees/{$employee->id}")->assertInertia(fn (AssertableInertia $page) => $page
            ->where('canIssue', false)
            ->where('stock', [])
        );

        $this->actingAs($editor)->post("/employees/{$employee->id}/equipment", ['equipment' => [$laptop->id]])->assertForbidden();
        $this->assertSame('stock', $laptop->refresh()->status);
    }

    public function test_nobody_hands_a_unit_to_themselves_from_their_own_card()
    {
        $this->unit('Ноутбук Dell Latitude 5440', 'EV-0006');

        // Every right the card asks for, on their own card: issuing is still not
        // offered, as transferring and firing are not offered there either.
        $keeper = $this->withRights('profile.field.equipment', 'profile.edit.equipment', 'equipment.issue');

        $this->actingAs($keeper)->get('/profile')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('isSelf', true)
            ->where('canIssue', false)
            ->where('stock', [])
        );
    }

    public function test_the_pair_of_rights_hands_the_unit_over()
    {
        $laptop = $this->unit('Ноутбук Dell Latitude 5440', 'EV-0007');
        $employee = User::factory()->create();

        $keeper = $this->withRights('employees.field.equipment', 'employees.edit.equipment', 'equipment.issue');

        $this->actingAs($keeper)
            ->from("/employees/{$employee->id}")
            ->post("/employees/{$employee->id}/equipment", ['equipment' => [$laptop->id], 'issued_at' => '2026-10-01'])
            ->assertSessionHasNoErrors()
            ->assertRedirect("/employees/{$employee->id}");

        $laptop->refresh();
        $this->assertSame('issued', $laptop->status);
        $this->assertSame($employee->id, $laptop->holder_user_id);
        $this->assertSame('2026-10-01', $laptop->issued_at?->toDateString());
    }

    public function test_a_held_unit_offers_the_two_moves_to_whoever_holds_them()
    {
        $holder = User::factory()->create();
        $this->held($holder, 'Ноутбук Dell Latitude 5440', 'EV-0008');

        $keeper = $this->withRights('employees.field.equipment', 'equipment.view.all', 'equipment.take', 'equipment.write_off');

        $this->actingAs($keeper)->get("/employees/{$holder->id}")->assertInertia(fn (AssertableInertia $page) => $page
            ->where('employee.private.equipment.0.can_take', true)
            ->where('employee.private.equipment.0.can_write_off', true)
            // The write-off form opens on what the card says now.
            ->where('employee.private.equipment.0.condition', 'Рабочее, следы эксплуатации')
        );
    }

    public function test_reading_what_somebody_holds_moves_none_of_it()
    {
        $holder = User::factory()->create();
        $laptop = $this->held($holder, 'Ноутбук HP ProBook 450 G9', 'EV-0009');

        // The whole fleet is theirs to read, and none of it theirs to move.
        $reader = $this->withRights('employees.field.equipment', 'equipment.view.all');

        $this->actingAs($reader)->get("/employees/{$holder->id}")->assertInertia(fn (AssertableInertia $page) => $page
            ->where('employee.private.equipment.0.can_take', false)
            ->where('employee.private.equipment.0.can_write_off', false)
        );

        $this->actingAs($reader)->post("/equipment/{$laptop->id}/take", ['returned_at' => '2026-10-05'])->assertForbidden();
        $this->actingAs($reader)->post("/equipment/{$laptop->id}/write-off", ['written_off_at' => '2026-10-05'])->assertForbidden();
        $this->assertSame('issued', $laptop->refresh()->status);
    }

    public function test_the_card_takes_a_unit_back_onto_the_balance_sheet()
    {
        $holder = User::factory()->create();
        $laptop = $this->held($holder, 'Ноутбук Lenovo ThinkPad E14', 'EV-0010');

        $keeper = $this->withRights('employees.field.equipment', 'equipment.view.all', 'equipment.take');

        $this->actingAs($keeper)
            ->from("/employees/{$holder->id}")
            ->post("/equipment/{$laptop->id}/take", ['returned_at' => '2026-10-05', 'condition_on_return' => 'Рабочее, нужна чистка'])
            ->assertSessionHasNoErrors()
            // Back to the card, which rebuilds the list out of the new props.
            ->assertRedirect("/employees/{$holder->id}");

        $laptop->refresh();
        $this->assertSame('stock', $laptop->status);
        $this->assertNull($laptop->holder_user_id);
        $this->assertSame('Рабочее, нужна чистка', $laptop->condition);
        $this->assertSame('2026-10-05', $laptop->checked_at?->toDateString());
    }

    public function test_the_card_strikes_a_unit_off()
    {
        $holder = User::factory()->create();
        $laptop = $this->held($holder, 'Ноутбук Acer Aspire 5', 'EV-0011');

        $keeper = $this->withRights('employees.field.equipment', 'equipment.view.all', 'equipment.write_off');

        $this->actingAs($keeper)
            ->from("/employees/{$holder->id}")
            ->post("/equipment/{$laptop->id}/write-off", ['written_off_at' => '2026-10-05', 'condition' => 'Не подлежит ремонту'])
            ->assertSessionHasNoErrors()
            ->assertRedirect("/employees/{$holder->id}");

        $laptop->refresh();
        $this->assertSame('written_off', $laptop->status);
        $this->assertNull($laptop->holder_user_id);
        $this->assertSame('2026-10-05', $laptop->written_off_at?->toDateString());
        $this->assertSame('Не подлежит ремонту', $laptop->condition);
    }
}
