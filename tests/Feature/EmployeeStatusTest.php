<?php

namespace Tests\Feature;

use App\Models\Equipment;
use App\Models\EquipmentEvent;
use App\Models\User;
use App\Models\UserDetail;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class EmployeeStatusTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('sysadmin');
    }

    public function test_employees_cannot_act_on_colleagues_or_see_who_left()
    {
        $colleague = User::factory()->create();
        $this->actingAs($this->colleague());

        $this->post("/employees/{$colleague->id}/fire", ['date' => '2026-09-01'])->assertForbidden();
        $this->post("/employees/{$colleague->id}/transfer", ['date' => '2026-09-01', 'note' => 'X'])->assertForbidden();
        $this->delete("/employees/{$colleague->id}")->assertForbidden();
        $this->assertTrue($colleague->fresh()->isActive());

        $this->get('/employees?status=fired')->assertSessionHasErrors('status');
        $this->get('/employees')->assertInertia(fn (Assert $page) => $page
            ->where('statusCounts', null)
            ->where('auth.can', fn ($can) => $can['employees.fire'] === false)
        );
    }

    public function test_transfer_needs_a_destination_and_moves_the_person_to_their_list()
    {
        $employee = $this->colleague(['surname' => 'Азимов']);
        $this->actingAs($this->admin);

        $this->post("/employees/{$employee->id}/transfer", ['date' => '2026-09-01'])->assertSessionHasErrors('note');

        $this->post("/employees/{$employee->id}/transfer", ['date' => '2026-09-01', 'note' => 'Эволет Европа'])->assertSessionHasNoErrors();
        $employee->refresh();
        $this->assertSame('transferred', $employee->status);
        $this->assertSame('2026-09-01', $employee->status_changed_at->toDateString());
        $this->assertSame('Эволет Европа', $employee->status_note);

        $this->get('/employees')->assertInertia(fn (Assert $page) => $page
            ->where('employees.data', fn ($rows) => collect($rows)->doesntContain('id', $employee->id))
            ->where('statusCounts', ['active' => 1, 'transferred' => 1, 'fired' => 0])
        );
        $this->get('/employees?status=transferred')->assertInertia(fn (Assert $page) => $page
            ->where('status', 'transferred')
            ->has('employees.data', 1)
            ->where('employees.data.0.status_note', 'Эволет Европа')
        );
    }

    public function test_firing_takes_an_optional_reason_and_can_be_undone()
    {
        $employee = $this->colleague();
        $this->actingAs($this->admin);

        $this->post("/employees/{$employee->id}/fire", ['date' => '2026-08-15'])->assertSessionHasNoErrors();
        $this->assertSame('fired', $employee->fresh()->status);
        $this->assertNull($employee->fresh()->status_note);

        // Already gone: cannot be fired again. What asks is a dialog, so the
        // answer is the way back with a sentence rather than a refusal the
        // dialog has no way of showing.
        $this->from("/employees/{$employee->id}")
            ->post("/employees/{$employee->id}/fire", ['date' => '2026-08-16'])
            ->assertRedirect("/employees/{$employee->id}")
            ->assertSessionHas('notice', 'Сотрудник уже не работает.');

        // The second date was not written: the first firing stands untouched.
        $this->assertSame('2026-08-15', $employee->fresh()->status_changed_at->toDateString());

        $this->post("/employees/{$employee->id}/restore")->assertSessionHasNoErrors();
        $employee->refresh();
        $this->assertTrue($employee->isActive());
        $this->assertNull($employee->status_changed_at);
    }

    public function test_deleting_removes_the_employee_and_their_private_data()
    {
        $employee = User::factory()->has(UserDetail::factory(), 'details')->create();
        $this->actingAs($this->admin);

        $this->delete("/employees/{$employee->id}")->assertSessionHasNoErrors();

        $this->assertNull(User::find($employee->id));
        $this->assertSame(0, UserDetail::where('user_id', $employee->id)->count());
    }

    public function test_deleting_puts_what_the_employee_held_back_on_the_balance()
    {
        $employee = $this->colleague(['surname' => 'Азимов', 'name' => 'Рустам']);
        $other = $this->colleague();
        $held = Equipment::factory(2)->issuedTo($employee->id)->create();
        $notTheirs = Equipment::factory()->issuedTo($other->id)->create();
        $this->actingAs($this->admin);

        $this->delete("/employees/{$employee->id}")->assertSessionHasNoErrors();

        foreach ($held as $unit) {
            $unit->refresh();
            $this->assertSame('stock', $unit->status);
            $this->assertNull($unit->holder_user_id);
            $this->assertNull($unit->issued_at);

            // One return each, signed by whoever deleted the person and saying why.
            $event = $unit->events()->where('kind', 'stocked')->sole();
            $this->assertSame($this->admin->id, $event->user_id);
            $this->assertSame('Сотрудник Азимов Рустам удалён из системы', $event->note);
            $this->assertSame(['issued', 'stock'], $event->diff['status']);
        }

        // Somebody else's unit is none of this deletion's business.
        $this->assertSame('issued', $notTheirs->fresh()->status);
        $this->assertSame($other->id, $notTheirs->fresh()->holder_user_id);
        $this->assertSame(0, $notTheirs->events()->where('kind', 'stocked')->count());
    }

    public function test_deleting_someone_who_holds_nothing_writes_nothing_to_the_journal()
    {
        $employee = $this->colleague();
        $this->actingAs($this->admin);

        $this->delete("/employees/{$employee->id}")->assertSessionHasNoErrors();

        $this->assertNull(User::find($employee->id));
        $this->assertSame(0, EquipmentEvent::count());
    }

    public function test_deleting_from_the_profile_lands_on_the_employee_list()
    {
        $employee = $this->colleague();
        $this->actingAs($this->admin);

        // Going back would mean the profile of someone who no longer exists.
        $this->from("/employees/{$employee->id}")
            ->delete("/employees/{$employee->id}")
            ->assertRedirect('/employees');
    }

    public function test_deleting_from_the_list_goes_back_to_it_with_its_filters()
    {
        $employee = $this->colleague();
        $this->actingAs($this->admin);

        $this->from('/employees?status=active&per_page=25')
            ->delete("/employees/{$employee->id}")
            ->assertRedirect('/employees?status=active&per_page=25');
    }

    public function test_admins_cannot_fire_transfer_or_delete_themselves()
    {
        $this->actingAs($this->admin);

        $this->post("/employees/{$this->admin->id}/fire", ['date' => '2026-09-01'])->assertForbidden();
        $this->post("/employees/{$this->admin->id}/transfer", ['date' => '2026-09-01', 'note' => 'X'])->assertForbidden();
        $this->delete("/employees/{$this->admin->id}")->assertForbidden();
        $this->assertTrue($this->admin->fresh()->isActive());
    }

    public function test_people_who_left_cannot_sign_in()
    {
        User::factory()->create(['email' => 'gone@evolet.test', 'status' => 'fired']);

        $this->post('/login', ['email' => 'gone@evolet.test', 'password' => 'password'])
            ->assertSessionHasErrors(['email' => 'Учётная запись отключена. Обратитесь в HR-отдел.']);

        $this->assertGuest();
    }

    public function test_someone_fired_while_signed_in_is_signed_out()
    {
        // Their own card, which is the one page everybody can open.
        $employee = $this->colleague();
        $this->actingAs($employee)->get('/profile')->assertOk();

        $employee->update(['status' => 'fired']);

        $this->get('/profile')->assertRedirect('/login');
        $this->assertGuest();
    }
}
