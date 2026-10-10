<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Equipment;
use App\Models\EquipmentEvent;
use App\Models\User;
use App\Models\UserDetail;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page()
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_everybody_else_starts_at_their_own_card()
    {
        // The figures here are the whole company's and nobody is given them by a
        // right, so until there is something to open line by line the page is the
        // one account's and everybody else lands on their profile.
        $this->actingAs(User::factory()->create());
        $this->get('/dashboard')->assertRedirect('/profile');
    }

    public function test_the_system_administrator_sees_the_company_as_the_database_has_it()
    {
        $this->travelTo('2026-09-30 12:00:00');
        $this->seed(RoleSeeder::class);
        $admin = User::factory()->create()->assignRole('sysadmin');

        // Staff: two hired within the last 30 days, one long ago, one hired
        // recently who has already gone, two fired — one recently, one last year —
        // and one transferred, who is neither working nor fired.
        $hire = fn (string $date) => UserDetail::factory()->create(['hired_at' => $date])->user;
        $recentA = $hire('2026-09-15');
        $recentB = $hire('2026-08-31');
        $veteran = $hire('2020-01-10');
        $hire('2026-09-20')->update(['status' => 'fired', 'status_changed_at' => '2026-09-25']);
        User::factory()->create(['status' => 'fired', 'status_changed_at' => '2025-06-01']);
        User::factory()->create(['status' => 'transferred', 'status_changed_at' => '2026-09-10']);

        // Structure: a department with a sub-department, and one of its own.
        // A person in both the parent and the child counts once; the fired
        // colleague does not count at all.
        // The bar names a department the way every other page does, by the short
        // name it is known by, and by its full one while it has none.
        $sales = Department::create(['name' => 'Продажи', 'abbreviation' => 'ОП']);
        $retail = Department::create(['name' => 'Розница', 'parent_id' => $sales->id]);
        $finance = Department::create(['name' => 'Финансы']);
        $sales->users()->attach([$recentA->id, $veteran->id]);
        $retail->users()->attach([$recentA->id, $recentB->id]);
        $finance->users()->attach([$admin->id]);
        $retail->users()->attach(User::where('status', 'fired')->first()->id);

        // Fleet: three out with people, one of them being serviced; two on the
        // balance sheet; one written off.
        $issued = Equipment::factory()->count(3)->issuedTo($veteran->id)->create();
        Equipment::factory()->count(2)->create();
        Equipment::factory()->writtenOff()->create();
        $issued->first()->repairs()->create(['kind' => 'Диагностика', 'started_at' => '2026-09-20']);
        $issued->last()->repairs()->create(['kind' => 'Чистка', 'started_at' => '2026-08-01', 'ended_at' => '2026-08-05']);

        $latest = EquipmentEvent::query()->orderByDesc('created_at')->orderByDesc('id')->first();

        $this->actingAs($admin)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('dashboard')
                ->where('since', '2026-08-31')
                ->where('today', '2026-09-30')
                // The admin plus the three still working.
                ->where('staff.active', 4)
                ->where('staff.hired', 2)
                ->where('staff.fired', 1)
                ->where('equipment.issued', 3)
                ->where('equipment.stock', 2)
                ->where('equipment.service', 1)
                ->has('departments', 2)
                // Short on the bar, spelled out for the hover beside it; one
                // with no short name of its own reads in full either way.
                ->where('departments.0', ['id' => $sales->id, 'name' => 'ОП', 'full_name' => 'Продажи', 'count' => 3])
                ->where('departments.1', ['id' => $finance->id, 'name' => 'Финансы', 'full_name' => 'Финансы', 'count' => 1])
                ->has('events', 6)
                ->where('events.0.id', $latest->id)
                ->where('events.0.kind', $latest->kind)
                ->where('events.0.unit.id', $latest->equipment_id)
                ->missing('stats')
                ->missing('pendingRequests')
            );
    }

    public function test_an_empty_company_shows_zeros_rather_than_anything_made_up()
    {
        $this->seed(RoleSeeder::class);
        $this->actingAs(User::factory()->create()->assignRole('sysadmin'));

        $this->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('staff', ['active' => 1, 'hired' => 0, 'fired' => 0])
                ->where('equipment', ['issued' => 0, 'stock' => 0, 'service' => 0])
                ->where('departments', [])
                ->where('events', [])
            );
    }
}
