<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Equipment;
use App\Models\EquipmentType;
use App\Models\User;
use Database\Seeders\EquipmentTypeSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Hardware that is nobody's in particular.
 *
 * A printer in a corridor, the router in the ceiling, the air conditioner on
 * the wall, a spare laptop nobody has been given yet: the department answers
 * for them and no colleague signs for one. Nothing about the thing says which
 * it will be — the same model of laptop goes to a person one week and stays
 * the department's the next — so the question is asked at the handover and
 * answered there, and any unit of any category may be held either way.
 *
 * Held by a department, a unit lives exactly as a unit held by a person does:
 * it is handed over, taken back and written off by the same three moves, and
 * it is read in the table under the department that holds it, beside the
 * equipment of the people who work there.
 */
class EquipmentByDepartmentTest extends TestCase
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

    /**
     * The kind of thing a department usually ends up answering for. Nothing
     * marks it as such — it is an ordinary category like any other, and these
     * tests hand its units to departments because that is what somebody does
     * with a printer, not because the category says so.
     */
    private function shared(string $name = 'Печать'): EquipmentType
    {
        return EquipmentType::firstWhere('name', $name) ?? EquipmentType::factory()->create(['name' => $name]);
    }

    private function type(string $name = 'Ноутбуки'): EquipmentType
    {
        return EquipmentType::firstWhere('name', $name);
    }

    /** A department of the company with one unit inside it. */
    private function structure(): array
    {
        $analysis = Department::create(['name' => 'Департамент анализа и стратегии', 'abbreviation' => 'ДАС']);

        return [$analysis, Department::create(['name' => 'Отдел аналитики', 'abbreviation' => 'ОА', 'parent_id' => $analysis->id])];
    }

    /** Whatever the code says, the database holds a unit against one name and not two. */
    public function test_the_database_refuses_a_unit_held_by_a_colleague_and_a_department_at_once()
    {
        [, $unit] = $this->structure();
        $holder = User::factory()->create();
        $printer = Equipment::factory()->ofType($this->shared())->heldBy($unit->id)->create();

        $this->expectException(QueryException::class);

        // Not through the model, which would never write both: straight at the
        // table, which is the one place the rule cannot be talked past.
        DB::table('equipment')->where('id', $printer->id)->update(['holder_user_id' => $holder->id]);
    }

    /**
     * The three moves, for a kind of thing nobody signs for: handed to a
     * department, taken back onto the balance sheet, and written off.
     */
    public function test_a_unit_is_handed_to_a_department_taken_back_and_written_off()
    {
        [, $unit] = $this->structure();
        $printer = Equipment::factory()->ofType($this->shared())->create();
        $admin = $this->sysadmin();

        $this->actingAs($admin)
            ->post("/equipment/{$printer->id}/issue", ['holder_department_id' => $unit->id, 'issued_at' => '2026-02-10'])
            ->assertSessionHasNoErrors();

        $printer->refresh();
        $this->assertSame('issued', $printer->status);
        $this->assertNull($printer->holder_user_id);
        $this->assertTrue($unit->is($printer->holderDepartment));
        // The journal reads it as a handover like any other, and names the
        // department behind the id it kept.
        $handover = $printer->events()->where('kind', 'issued')->latest('id')->first();
        $this->assertSame([null, $unit->id], $handover->diff['holder_department_id']);

        // Back on the balance sheet: the department lets go as a person does.
        $this->actingAs($admin)
            ->post("/equipment/{$printer->id}/take", ['returned_at' => '2026-03-01'])
            ->assertSessionHasNoErrors();

        $printer->refresh();
        $this->assertSame('stock', $printer->status);
        $this->assertNull($printer->holder_department_id);

        // And written off, from a department's hands as from anybody's.
        $this->actingAs($admin)
            ->post("/equipment/{$printer->id}/issue", ['holder_department_id' => $unit->id, 'issued_at' => '2026-03-02'])
            ->assertSessionHasNoErrors();
        $this->actingAs($admin)
            ->post("/equipment/{$printer->id}/write-off", ['written_off_at' => '2026-04-01'])
            ->assertSessionHasNoErrors();

        $printer->refresh();
        $this->assertSame('written_off', $printer->status);
        $this->assertNull($printer->holder_department_id);
        $this->assertNull($printer->holder_user_id);
    }

    /**
     * A handover goes to one holder: the form must name a colleague or a
     * department, and may not name both. Any unit may go either way — the same
     * laptop to the person who works on it, or to the department that keeps it
     * as a spare — so neither answer is refused for what the thing is.
     */
    public function test_a_handover_names_one_holder_and_not_two()
    {
        [, $unit] = $this->structure();
        $laptop = Equipment::factory()->ofType($this->type())->create();
        $admin = $this->sysadmin();
        $issue = fn (array $data) => $this->actingAs($admin)
            ->post("/equipment/{$laptop->id}/issue", [...$data, 'issued_at' => '2026-02-10']);

        // Neither: there is no handover without somebody to hand it to.
        $issue([])->assertSessionHasErrors('holder_user_id');
        // Both: the row would stand against two names, and the database would
        // refuse it even if this did not.
        $issue(['holder_user_id' => $admin->id, 'holder_department_id' => $unit->id])->assertSessionHasErrors('holder_user_id');

        // A laptop to a department is no stranger than a printer to a person.
        $issue(['holder_department_id' => $unit->id])->assertSessionHasNoErrors();
        $this->assertNull($laptop->fresh()->holder_user_id);

        $this->actingAs($admin)->post("/equipment/{$laptop->id}/take", ['returned_at' => '2026-02-20']);
        $issue(['holder_user_id' => $admin->id])->assertSessionHasNoErrors();
        $this->assertNull($laptop->fresh()->holder_department_id);
    }

    /**
     * What the table makes of such a unit: no colleague in «У кого», and the
     * department it is signed out to in the two columns of the structure — so
     * that a department's own equipment and its people's stand in one block,
     * which is the whole of what was asked for.
     */
    public function test_the_list_reads_a_departments_own_equipment_under_that_department()
    {
        [$analysis, $unit] = $this->structure();
        $colleague = User::factory()->create(['surname' => 'Бобоев']);
        $colleague->departments()->attach($unit->id);

        Equipment::factory()->ofType($this->shared())->heldBy($unit->id)->create(['name' => 'Принтер отдела']);
        Equipment::factory()->ofType($this->type())->issuedTo($colleague->id)->create(['name' => 'Ноутбук Бобоева']);
        Equipment::factory()->ofType($this->type())->create(['name' => 'Ноутбук на складе']);

        $rows = [];
        $this->actingAs($this->sysadmin())->get('/equipment?view=list&tab=all&sort=department')
            ->assertInertia(function (AssertableInertia $page) use (&$rows) {
                $rows = collect($page->toArray()['props']['equipment']['data'])->keyBy('name');
            });

        $printer = $rows['Принтер отдела'];

        // Nobody holds it, and the dash the column draws for that is the whole
        // of what «У кого» has to say about it.
        $this->assertNull($printer['holder']);
        $this->assertTrue($printer['held_by_department']);
        // And it is read under the department that holds it, both steps of it.
        $this->assertSame($unit->id, $printer['department']['id']);
        $this->assertSame('ОА', $printer['department']['name']);
        $this->assertSame($analysis->id, $printer['department']['parent']['id']);

        // The department's own equipment opens its block, before the people of
        // it: one name stands over the lot, which is what the merged cells are
        // drawn from. The unit nobody holds reads last, as it always has.
        $this->assertSame(
            ['Принтер отдела', 'Ноутбук Бобоева', 'Ноутбук на складе'],
            collect($rows)->keys()->all(),
        );
    }

    /**
     * And «По сотрудникам», where the rows are the people: a department that
     * holds equipment of its own has a row of its own at the head of its block,
     * and it is not an employee — it says so, and nothing counts it as one.
     */
    public function test_a_department_has_a_row_of_its_own_in_the_table_of_holders()
    {
        [, $unit] = $this->structure();
        $colleague = User::factory()->create(['surname' => 'Бобоев', 'name' => 'Сухроб']);
        $colleague->departments()->attach($unit->id);

        Equipment::factory()->ofType($this->shared())->heldBy($unit->id)->create(['name' => 'Принтер отдела']);
        Equipment::factory()->ofType($this->type())->issuedTo($colleague->id)->create(['name' => 'Ноутбук Бобоева']);

        $rows = [];
        $this->actingAs($this->sysadmin())->get('/equipment?view=holders')
            ->assertInertia(function (AssertableInertia $page) use (&$rows) {
                $rows = $page->toArray()['props']['holders']['data'];
            });

        // Two rows: the department, then the colleague who works in it.
        $this->assertSame(['department', 'person'], array_column($rows, 'kind'));
        $this->assertSame(['ОА', 'Бобоев Сухроб'], array_column($rows, 'name'));
        // Both read under the same pair of columns, so one name stands over them.
        $this->assertSame($unit->id, $rows[0]['department']['id']);
        $this->assertSame($unit->id, $rows[1]['department']['id']);
        // And the department's row holds what the department holds, under the
        // category it is drawn in — the same cell a colleague's row draws.
        $this->assertSame(
            ['Принтер отдела'],
            array_column($rows[0]['units'][$this->shared()->id], 'name'),
        );
        // A department has no face: it is not drawn as a person anywhere.
        $this->assertNull($rows[0]['avatar']);
    }

    /**
     * The right to see one's own department's fleet covers what the department
     * itself holds. Without this a head of department would be shown their
     * people's laptops and not the printer in their own corridor.
     */
    public function test_a_head_of_department_sees_what_the_department_itself_holds()
    {
        [, $unit] = $this->structure();
        $head = User::factory()->create()->givePermissionTo(['equipment.view.department']);
        $head->departments()->attach($unit->id, ['is_head' => true]);

        Equipment::factory()->ofType($this->shared())->heldBy($unit->id)->create(['name' => 'Принтер отдела']);
        // Another department's, which is none of their business.
        $other = Department::create(['name' => 'Департамент маркетинга', 'abbreviation' => 'ДМ']);
        Equipment::factory()->ofType($this->shared())->heldBy($other->id)->create(['name' => 'Чужой принтер']);

        $this->actingAs($head)->get('/equipment?view=list&tab=all')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('equipment.data', 1)
                ->where('equipment.data.0.name', 'Принтер отдела')
            );
    }

    /** The two funnels and the search box answer for a department's own equipment too. */
    public function test_the_structure_narrows_and_the_search_finds_a_departments_own_equipment()
    {
        [$analysis, $unit] = $this->structure();

        Equipment::factory()->ofType($this->shared())->heldBy($unit->id)->create(['name' => 'Принтер отдела']);
        Equipment::factory()->ofType($this->type())->create(['name' => 'Ноутбук на складе']);

        $admin = $this->sysadmin();
        $found = function (string $query) use ($admin) {
            $names = [];
            $this->actingAs($admin)->get("/equipment?view=list&tab=all&{$query}")
                ->assertInertia(function (AssertableInertia $page) use (&$names) {
                    $names = array_column($page->toArray()['props']['equipment']['data'], 'name');
                });

            return $names;
        };

        // The department of the company, which asks about its units too.
        $this->assertSame(['Принтер отдела'], $found("department[]={$analysis->id}"));
        $this->assertSame(['Принтер отдела'], $found("unit[]={$unit->id}"));
        // And the box above the table, by either name of either step.
        $this->assertSame(['Принтер отдела'], $found('q='.urlencode('ДАС')));
        $this->assertSame(['Принтер отдела'], $found('q='.urlencode('Отдел аналитики')));
    }

    /**
     * A department that is dissolved does not take the company's printers with
     * it: they fall back to holding nobody, which is what the balance sheet is.
     */
    public function test_a_dissolved_department_lets_go_of_what_it_held()
    {
        [, $unit] = $this->structure();
        $printer = Equipment::factory()->ofType($this->shared())->heldBy($unit->id)->create();

        $unit->delete();

        $this->assertNotNull($printer->fresh());
        $this->assertNull($printer->fresh()->holder_department_id);
    }
}
