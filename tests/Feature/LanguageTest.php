<?php

namespace Tests\Feature;

use App\Models\Language;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class LanguageTest extends TestCase
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

    public function test_only_admins_manage_the_languages_directory()
    {
        $english = Language::create(['name' => 'Английский']);
        $speaker = User::factory()->create();
        $speaker->languages()->attach($english, ['level' => 'advanced']);
        User::factory()->create(['status' => 'fired'])->languages()->attach($english, ['level' => 'beginner']);

        $this->actingAs($this->colleague())->post('/directories/languages', ['name' => 'Хинди'])->assertForbidden();

        $this->actingAs($this->admin);
        $this->get('/directories/languages')->assertInertia(fn (Assert $page) => $page
            ->component('directories/languages')
            ->where('items.0.name', 'Английский')
            ->where('items.0.users_count', 1)
        );

        $this->post('/directories/languages', ['name' => 'Хинди'])->assertSessionHasNoErrors();
        $this->post('/directories/languages', ['name' => 'Хинди'])->assertSessionHasErrors('name');
        $this->put("/directories/languages/{$english->id}", ['name' => 'Английский язык'])->assertSessionHasNoErrors();
        $this->assertSame('Английский язык', $english->fresh()->name);

        $this->delete("/directories/languages/{$english->id}")->assertSessionHasNoErrors();
        $this->assertCount(0, $speaker->fresh()->languages);
    }

    public function test_languages_show_in_the_list_and_profile_and_can_be_filtered()
    {
        $english = Language::create(['name' => 'Английский']);
        $tajik = Language::create(['name' => 'Таджикский']);
        $speaker = User::factory()->create();
        $speaker->languages()->attach([$english->id => ['level' => 'beginner'], $tajik->id => ['level' => 'advanced']]);
        User::factory()->create()->languages()->attach($tajik, ['level' => 'advanced']);

        $viewer = $this->colleague();
        $this->actingAs($viewer);

        // The best known first, for every colleague: languages are public.
        $this->get("/employees/{$speaker->id}")->assertInertia(fn (Assert $page) => $page->where('employee.languages', [
            ['id' => $tajik->id, 'name' => 'Таджикский', 'level' => 'advanced'],
            ['id' => $english->id, 'name' => 'Английский', 'level' => 'beginner'],
        ]));

        $this->get("/employees?language[]={$english->id}")->assertInertia(fn (Assert $page) => $page
            ->where('employees.total', 1)
            ->where('employees.data.0.id', $speaker->id)
            ->where('filters.language', [$english->id])
            ->has('options.languages', 2)
        );

        // Two languages ask for people who know both, not either.
        $this->get("/employees?language[]={$tajik->id}")->assertInertia(fn (Assert $page) => $page->where('employees.total', 2));
        $this->get("/employees?language[]={$tajik->id}&language[]={$english->id}")->assertInertia(fn (Assert $page) => $page
            ->where('employees.total', 1)
            ->where('employees.data.0.id', $speaker->id)
        );
    }

    public function test_seeded_employees_speak_tajik_and_russian()
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(0, User::whereDoesntHave('languages', fn ($q) => $q->where('name', 'Таджикский'))->count());
        $this->assertSame(0, User::whereDoesntHave('languages', fn ($q) => $q->where('name', 'Русский'))->count());
        $this->assertTrue(User::whereHas('languages', fn ($q) => $q->where('name', 'Английский'))->exists());
    }
}
