<?php

namespace Tests\Feature;

use App\Models\Language;
use App\Models\User;
use Database\Seeders\PositionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * The "Знание языков" card of the profile is edited from its own dialog.
 * Unlike the cards around it, languages are public.
 */
class EmployeeLanguagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RoleSeeder::class, PositionSeeder::class]);
    }

    public function test_an_admin_sets_languages_and_levels()
    {
        $admin = User::factory()->create()->assignRole('sysadmin');
        $english = Language::create(['name' => 'Английский']);
        $russian = Language::create(['name' => 'Русский']);
        $german = Language::create(['name' => 'Немецкий']);

        $employee = $this->colleague();
        $employee->languages()->attach($german, ['level' => 'beginner']);

        // The card is replaced wholesale, so German goes.
        $this->actingAs($admin)
            ->put("/employees/{$employee->id}/languages", [
                'languages' => [
                    ['id' => $english->id, 'level' => 'intermediate'],
                    ['id' => $russian->id, 'level' => 'advanced'],
                ],
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertSame(
            ['Английский' => 'intermediate', 'Русский' => 'advanced'],
            $employee->fresh()->languages->mapWithKeys(fn (Language $l) => [$l->name => $l->pivot->level])->all(),
        );
    }

    public function test_the_card_may_be_emptied()
    {
        $admin = User::factory()->create()->assignRole('sysadmin');
        $employee = $this->colleague();
        $employee->languages()->attach(Language::create(['name' => 'Английский']), ['level' => 'beginner']);

        $this->actingAs($admin)
            ->put("/employees/{$employee->id}/languages", ['languages' => []])
            ->assertSessionHasNoErrors();

        $this->assertCount(0, $employee->fresh()->languages);
    }

    public function test_invalid_data_is_rejected()
    {
        $admin = User::factory()->create()->assignRole('sysadmin');
        $english = Language::create(['name' => 'Английский']);
        $employee = $this->colleague();

        $this->actingAs($admin)
            ->put("/employees/{$employee->id}/languages", [
                'languages' => [
                    // A level that does not exist, then the same language twice.
                    ['id' => $english->id, 'level' => 'fluent'],
                    ['id' => $english->id, 'level' => 'advanced'],
                ],
            ])
            ->assertSessionHasErrors(['languages.0.level', 'languages.0.id']);
    }

    public function test_a_language_missing_from_the_list_is_added_by_name()
    {
        $admin = User::factory()->create()->assignRole('sysadmin');
        Language::create(['name' => 'Английский']);
        $employee = $this->colleague();

        $this->actingAs($admin)
            ->put("/employees/{$employee->id}/languages", [
                'languages' => [
                    ['name' => ' Китайский ', 'level' => 'beginner'],
                    // Already on the list under another case: the same language, not a second one.
                    ['name' => 'английский', 'level' => 'advanced'],
                ],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(['Английский', 'Китайский'], Language::orderBy('name')->pluck('name')->all());
        $this->assertSame(
            ['Английский' => 'advanced', 'Китайский' => 'beginner'],
            $employee->languages()->orderBy('name')->get()->mapWithKeys(fn (Language $l) => [$l->name => $l->pivot->level])->all(),
        );

        $this->put("/employees/{$employee->id}/languages", ['languages' => [['level' => 'beginner']]])
            ->assertSessionHasErrors(['languages.0.id', 'languages.0.name']);
    }

    public function test_the_language_list_reaches_editors_only()
    {
        $admin = User::factory()->create()->assignRole('sysadmin');
        Language::create(['name' => 'Английский']);
        $employee = $this->colleague();

        $this->actingAs($admin)
            ->get("/employees/{$employee->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page->has('options.languages', 1));

        $this->actingAs($this->colleague())
            ->get("/employees/{$employee->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('options', null));
    }

    public function test_an_employee_cannot_edit_anyones_languages()
    {
        $employee = $this->colleague();
        $english = Language::create(['name' => 'Английский']);
        $payload = ['languages' => [['id' => $english->id, 'level' => 'advanced']]];

        // Not even their own: the card is managed by HR.
        $this->actingAs($employee)->put("/employees/{$employee->id}/languages", $payload)->assertForbidden();
        $this->actingAs($this->colleague())->put("/employees/{$employee->id}/languages", $payload)->assertForbidden();
    }
}
