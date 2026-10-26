<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\EmploymentType;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Two lists of «Справочники» kept the way the citizenships are, except that a
 * card names one entry of each rather than any number of them.
 */
class EmploymentTypeTest extends TestCase
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

    public function test_only_admins_manage_the_employment_types_directory()
    {
        $full = EmploymentType::create(['name' => 'Полная занятость']);
        User::factory()->create(['employment_type_id' => $full->id]);
        // The counts are of working staff, as everywhere else on these lists.
        User::factory()->create(['status' => 'fired', 'employment_type_id' => $full->id]);

        $this->actingAs($this->colleague())->get('/directories/employment_types')->assertForbidden();
        $this->actingAs($this->colleague())->post('/directories/employment_types', ['name' => 'Подработка'])->assertForbidden();

        $this->actingAs($this->admin);
        $this->get('/directories/employment_types')->assertInertia(fn (Assert $page) => $page
            ->component('directories/employment-types')
            ->where('items.0.name', 'Полная занятость')
            ->where('items.0.users_count', 1)
        );

        $this->post('/directories/employment_types', ['name' => 'Подработка'])->assertSessionHasNoErrors();
        $this->post('/directories/employment_types', ['name' => 'Подработка'])->assertSessionHasErrors('name');
        $this->put("/directories/employment_types/{$full->id}", ['name' => 'Полный день'])->assertSessionHasNoErrors();
        $this->assertSame('Полный день', $full->fresh()->name);

        $this->delete("/directories/employment_types/{$full->id}")->assertSessionHasNoErrors();
        $this->assertSame(0, EmploymentType::whereKey($full->id)->count());
    }

    public function test_only_admins_manage_the_branches_directory()
    {
        $office = Branch::create(['name' => 'Душанбе']);
        User::factory()->create(['branch_id' => $office->id]);

        $this->actingAs($this->colleague())->get('/directories/branches')->assertForbidden();
        $this->actingAs($this->colleague())->post('/directories/branches', ['name' => 'Худжанд'])->assertForbidden();

        $this->actingAs($this->admin);
        $this->get('/directories/branches')->assertInertia(fn (Assert $page) => $page
            ->component('directories/branches')
            ->where('items.0.name', 'Душанбе')
            ->where('items.0.users_count', 1)
        );

        $this->post('/directories/branches', ['name' => 'Худжанд'])->assertSessionHasNoErrors();
        $this->post('/directories/branches', ['name' => 'Худжанд'])->assertSessionHasErrors('name');
        $this->put("/directories/branches/{$office->id}", ['name' => 'Душанбе, центральный'])->assertSessionHasNoErrors();
        $this->assertSame('Душанбе, центральный', $office->fresh()->name);

        $this->delete("/directories/branches/{$office->id}")->assertSessionHasNoErrors();
        $this->assertSame(0, Branch::whereKey($office->id)->count());
    }
}
