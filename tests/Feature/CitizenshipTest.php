<?php

namespace Tests\Feature;

use App\Models\Citizenship;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CitizenshipTest extends TestCase
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

    public function test_only_admins_manage_the_citizenships_directory()
    {
        $russia = Citizenship::create(['name' => 'Россия']);
        $holder = User::factory()->create();
        $holder->citizenships()->attach($russia);
        User::factory()->create(['status' => 'fired'])->citizenships()->attach($russia);

        $this->actingAs($this->colleague())->get('/directories/citizenships')->assertForbidden();
        $this->actingAs($this->colleague())->post('/directories/citizenships', ['name' => 'Казахстан'])->assertForbidden();

        $this->actingAs($this->admin);
        $this->get('/directories/citizenships')->assertInertia(fn (Assert $page) => $page
            ->component('directories/citizenships')
            ->where('items.0.name', 'Россия')
            ->where('items.0.users_count', 1)
        );

        $this->post('/directories/citizenships', ['name' => 'Казахстан'])->assertSessionHasNoErrors();
        $this->post('/directories/citizenships', ['name' => 'Казахстан'])->assertSessionHasErrors('name');
        $this->put("/directories/citizenships/{$russia->id}", ['name' => 'Российская Федерация'])->assertSessionHasNoErrors();
        $this->assertSame(['Российская Федерация'], $holder->citizenships()->pluck('name')->all());

        $this->delete("/directories/citizenships/{$russia->id}")->assertSessionHasNoErrors();
        $this->assertCount(0, $holder->fresh()->citizenships);
    }
}
