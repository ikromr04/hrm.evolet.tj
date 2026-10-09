<?php

namespace Tests\Feature;

use App\Models\Equipment;
use App\Models\EquipmentRepair;
use App\Models\EquipmentType;
use App\Models\User;
use Database\Seeders\EquipmentTypeSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Tests\TestCase;

/**
 * What one may change about a unit, and what one may do to it.
 *
 * A card is edited block by block — renaming a unit, packing its box and keeping
 * its inventory dates are three different jobs — and the moves are separate
 * again: a storekeeper hands units over, an accountant writes them off, and only
 * somebody correcting a mistake deletes a row. Every one of them also takes
 * seeing the unit, because a right cannot reach further than the list does.
 */
class EquipmentRightsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RoleSeeder::class, PermissionSeeder::class, EquipmentTypeSeeder::class]);
    }

    /** Somebody who sees the whole fleet and holds exactly these rights besides. */
    private function person(string ...$rights): User
    {
        return User::factory()->create()->givePermissionTo(['equipment.view.all', ...$rights]);
    }

    private function type(string $name = 'Ноутбуки'): EquipmentType
    {
        return EquipmentType::firstWhere('name', $name);
    }

    private function unit(?User $holder = null): Equipment
    {
        $unit = Equipment::factory()->ofType($this->type());

        return ($holder === null ? $unit : $unit->issuedTo($holder->id))->create();
    }

    /** The three block endpoints with a payload each block accepts. */
    private function blockRequests(Equipment $unit): array
    {
        return [
            'specs' => ['put', "/equipment/{$unit->id}/specs", [
                'equipment_type_id' => $unit->equipment_type_id,
                'name' => 'Ноутбук Dell Latitude 5440',
                'inventory_number' => $unit->inventory_number,
            ]],
            'accessories' => ['put', "/equipment/{$unit->id}/accessories", ['accessories' => ['Кабель HDMI']]],
            'state' => ['put', "/equipment/{$unit->id}/state", [
                'condition' => 'Рабочее, следы эксплуатации',
                'checked_at' => '2026-09-01',
                'next_inventory_at' => '2026-12-01',
            ]],
        ];
    }

    public function test_each_block_of_a_card_is_a_right_of_its_own()
    {
        $unit = $this->unit();
        $requests = $this->blockRequests($unit);

        foreach (array_keys($requests) as $block) {
            $editor = $this->person("equipment.edit.{$block}");

            foreach ($requests as $other => [$method, $url, $payload]) {
                $response = $this->actingAs($editor)->{$method}($url, $payload);

                $other === $block
                    ? $response->assertSessionHasNoErrors()->assertRedirect()
                    : $response->assertForbidden();
            }
        }
    }

    public function test_a_block_one_may_change_is_still_a_unit_one_must_see()
    {
        // The right to rename units, over a fleet that comes down to one's own
        // desk: somebody else's laptop is not renamed.
        $editor = User::factory()->create()->givePermissionTo(['equipment.view.own', 'equipment.edit.specs']);
        $theirs = $this->unit(User::factory()->create());
        $mine = $this->unit($editor);

        [$method, $url, $payload] = $this->blockRequests($theirs)['specs'];
        $this->actingAs($editor)->{$method}($url, $payload)->assertForbidden();

        [$method, $url, $payload] = $this->blockRequests($mine)['specs'];
        $this->actingAs($editor)->{$method}($url, $payload)->assertRedirect();
    }

    public function test_handing_a_unit_over_taking_it_back_and_writing_it_off_are_three_rights()
    {
        $colleague = User::factory()->create();

        $issuer = $this->person('equipment.issue');
        $taker = $this->person('equipment.take');
        $writer = $this->person('equipment.write_off');

        $unit = $this->unit();
        $issue = fn (User $actor) => $this->actingAs($actor)
            ->post("/equipment/{$unit->id}/issue", ['holder_user_id' => $colleague->id, 'issued_at' => '2026-03-14']);

        $issue($taker)->assertForbidden();
        $issue($writer)->assertForbidden();
        $issue($issuer)->assertRedirect();
        $this->assertSame('issued', $unit->refresh()->status);

        $take = fn (User $actor) => $this->actingAs($actor)
            ->post("/equipment/{$unit->id}/take", ['returned_at' => '2026-04-01']);

        $take($issuer)->assertForbidden();
        $take($taker)->assertRedirect();
        $this->assertSame('stock', $unit->refresh()->status);

        $writeOff = fn (User $actor) => $this->actingAs($actor)
            ->post("/equipment/{$unit->id}/write-off", ['written_off_at' => '2026-05-01']);

        $writeOff($issuer)->assertForbidden();
        $writeOff($writer)->assertRedirect();
        $this->assertSame('written_off', $unit->refresh()->status);
    }

    public function test_putting_a_unit_on_the_books_is_a_right_of_its_own()
    {
        $payload = [
            'equipment_type_id' => $this->type()->id,
            'name' => 'Ноутбук Dell Latitude 5440',
            'inventory_number' => 'EV-0421',
        ];

        // Somebody who may hand units out but not bring new ones in.
        $issuer = $this->person('equipment.issue');
        $this->actingAs($issuer)->get('/equipment/create')->assertForbidden();
        $this->actingAs($issuer)->post('/equipment', $payload)->assertForbidden();

        $keeper = $this->person('equipment.create');
        $this->actingAs($keeper)->get('/equipment/create')->assertOk();
        $this->actingAs($keeper)->post('/equipment', $payload)->assertRedirect();
        $this->assertNotNull(Equipment::whereInventory('EV-0421')->first());
    }

    public function test_records_of_repair_are_opened_corrected_and_closed_under_one_right()
    {
        $unit = $this->unit();
        $outsider = $this->person('equipment.edit.state');
        $mechanic = $this->person('equipment.service');

        $this->actingAs($outsider)
            ->post("/equipment/{$unit->id}/repairs", ['kind' => 'Диагностика', 'started_at' => '2026-09-01'])
            ->assertForbidden();

        $this->actingAs($mechanic)
            ->post("/equipment/{$unit->id}/repairs", ['kind' => 'Диагностика', 'started_at' => '2026-09-01'])
            ->assertRedirect();

        $repair = EquipmentRepair::query()->sole();

        $this->actingAs($outsider)
            ->put("/equipment/{$unit->id}/repairs/{$repair->id}", ['kind' => 'Ремонт', 'started_at' => '2026-09-01'])
            ->assertForbidden();
        $this->actingAs($outsider)->delete("/equipment/{$unit->id}/repairs/{$repair->id}")->assertForbidden();

        $this->actingAs($mechanic)
            ->put("/equipment/{$unit->id}/repairs/{$repair->id}", ['kind' => 'Ремонт', 'started_at' => '2026-09-01', 'ended_at' => '2026-09-03'])
            ->assertRedirect();
        $this->actingAs($mechanic)->delete("/equipment/{$unit->id}/repairs/{$repair->id}")->assertRedirect();
        $this->assertSame(0, EquipmentRepair::count());
    }

    public function test_striking_a_unit_off_the_books_is_not_writing_it_off()
    {
        // Only a written-off unit may go at all, so the one here is already out
        // of the fleet and the rights are all that is left to decide it.
        $unit = Equipment::factory()->ofType($this->type())->writtenOff()->create();

        $this->actingAs($this->person('equipment.write_off'))->delete("/equipment/{$unit->id}")->assertForbidden();
        $this->actingAs($this->person('equipment.delete'))->delete("/equipment/{$unit->id}")->assertRedirect('/equipment');
        $this->assertNull(Equipment::find($unit->id));
    }

    public function test_a_move_reaches_no_further_than_the_list_does()
    {
        // The right to hand units over, held by somebody who only sees their own:
        // a unit on a stranger's desk is not theirs to move.
        $mover = User::factory()->create()->givePermissionTo(['equipment.view.own', 'equipment.issue', 'equipment.take']);
        $theirs = $this->unit(User::factory()->create());
        $mine = $this->unit($mover);

        $this->actingAs($mover)->post("/equipment/{$theirs->id}/take", ['returned_at' => '2026-04-01'])->assertForbidden();
        $this->actingAs($mover)->post("/equipment/{$mine->id}/take", ['returned_at' => '2026-04-01'])->assertRedirect();
    }

    public function test_the_pages_offer_only_what_the_server_would_allow()
    {
        $keeper = $this->person('equipment.create', 'equipment.issue', 'equipment.edit.state');
        $unit = $this->unit();

        foreach (['/equipment', "/equipment/{$unit->id}"] as $url) {
            $can = $this->actingAs($keeper)->get($url)->assertOk()->viewData('page')['props']['can'];

            $this->assertTrue($can['create']);
            $this->assertTrue($can['issue']);
            $this->assertTrue($can['state']);
            $this->assertFalse($can['specs']);
            $this->assertFalse($can['accessories']);
            $this->assertFalse($can['take']);
            $this->assertFalse($can['write_off']);
            $this->assertFalse($can['service']);
            $this->assertFalse($can['delete']);
        }
    }

    public function test_handing_units_over_from_a_card_takes_the_same_right()
    {
        $employee = User::factory()->create();
        $unit = $this->unit();

        // The block of the employee's card is not enough on its own: this hands
        // a unit over, and handing units over is a right of the fleet.
        $clerk = User::factory()->create()->givePermissionTo([
            'employees.view',
            'employees.edit.equipment',
            'employees.field.equipment',
        ]);

        $this->actingAs($clerk)
            ->post("/employees/{$employee->id}/equipment", ['equipment' => [$unit->id]])
            ->assertForbidden();

        $clerk->givePermissionTo('equipment.issue');

        $this->actingAs($clerk->fresh())
            ->post("/employees/{$employee->id}/equipment", ['equipment' => [$unit->id]])
            ->assertRedirect();
        $this->assertSame($employee->id, $unit->fresh()->holder_user_id);
    }

    public function test_entering_a_unit_for_a_colleague_takes_all_three_rights_at_once()
    {
        $employee = User::factory()->create();

        // Putting a unit on the books and handing it over in one go is both
        // jobs at once: the block of that colleague's card, the right to hand
        // units over, and the right to bring one in.
        $rights = [
            'card' => ['employees.view', 'employees.field.equipment', 'employees.edit.equipment'],
            'issue' => ['equipment.issue'],
            'create' => ['equipment.create'],
        ];

        $payload = fn (string $number) => [
            'for' => $employee->id,
            'equipment_type_id' => $this->type()->id,
            'name' => 'Ноутбук Dell Latitude 5440',
            'inventory_number' => $number,
        ];

        // Any two of the three are two too few.
        foreach (array_keys($rights) as $missing) {
            $short = $this->person(...array_merge(...array_values(Arr::except($rights, $missing))));

            $this->actingAs($short)->get("/equipment/create?for={$employee->id}")->assertForbidden();
            $this->actingAs($short)->post('/equipment', $payload('EV-0421'))->assertForbidden();
        }

        $this->assertSame(0, Equipment::count());

        $clerk = $this->person(...array_merge(...array_values($rights)));
        $this->actingAs($clerk)->get("/equipment/create?for={$employee->id}")->assertOk();
        $this->actingAs($clerk)
            ->post('/equipment', $payload('EV-0421'))
            ->assertRedirect("/employees/{$employee->id}");

        $this->assertSame($employee->id, Equipment::whereInventory('EV-0421')->first()->holder_user_id);

        // Without a colleague named, the rights are what they always were: the
        // one right to put a unit on the books.
        $keeper = $this->person('equipment.create');
        $this->actingAs($keeper)->get('/equipment/create')->assertOk();
        $this->actingAs($keeper)->post('/equipment', [...$payload('EV-0422'), 'for' => null])->assertRedirect();
        $this->assertNull(Equipment::whereInventory('EV-0422')->first()->holder_user_id);
    }

    public function test_an_administrator_needs_none_of_these_rights()
    {
        $admin = User::factory()->create()->assignRole('sysadmin');
        $unit = $this->unit();

        foreach ($this->blockRequests($unit) as [$method, $url, $payload]) {
            $this->actingAs($admin)->{$method}($url, $payload)->assertRedirect();
        }

        $written = Equipment::factory()->ofType($this->type())->writtenOff()->create();
        $this->actingAs($admin)->delete("/equipment/{$written->id}")->assertRedirect('/equipment');
    }
}
