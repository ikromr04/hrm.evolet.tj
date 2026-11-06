<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserLanguage;
use Database\Seeders\PositionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * The "Знание языков" card of the profile is edited from its own dialog.
 * Unlike the cards around it, languages are public.
 *
 * A language is a word on the card now rather than an entry of a list the
 * company kept, so what the dialog offers is what the cards already say and
 * anything else is typed in and filed as it was typed.
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

        $employee = $this->colleague();
        $employee->languages()->create(['name' => 'Немецкий', 'level' => 'beginner']);

        // The card is replaced wholesale, so German goes.
        $this->actingAs($admin)
            ->put("/employees/{$employee->id}/languages", [
                'languages' => [
                    ['name' => 'Английский', 'level' => 'intermediate'],
                    ['name' => 'Русский', 'level' => 'advanced'],
                ],
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertSame(
            ['Английский' => 'intermediate', 'Русский' => 'advanced'],
            $employee->fresh()->languages->mapWithKeys(fn (UserLanguage $l) => [$l->name => $l->level])->all(),
        );
    }

    /**
     * The card cannot be emptied, because everybody speaks something.
     *
     * This is the one of the card's lists that is not asked «есть или нет»:
     * there is no «нет» to tick, so an empty list is a part somebody skipped and
     * the sentence says what to do rather than offering a way out of it. The
     * save leaves what was on file alone, as every refused save does.
     */
    public function test_the_card_cannot_be_emptied()
    {
        $admin = User::factory()->create()->assignRole('sysadmin');
        $employee = $this->colleague();
        $employee->languages()->create(['name' => 'Английский', 'level' => 'beginner']);

        $this->actingAs($admin)
            ->put("/employees/{$employee->id}/languages", ['languages' => []])
            ->assertSessionHasErrors(['languages' => 'Добавьте хотя бы один язык.']);

        $this->assertSame(['Английский'], $employee->fresh()->languages->pluck('name')->all());
    }

    public function test_invalid_data_is_rejected()
    {
        $admin = User::factory()->create()->assignRole('sysadmin');
        $employee = $this->colleague();

        $this->actingAs($admin)
            ->put("/employees/{$employee->id}/languages", [
                'languages' => [
                    // A level that does not exist, then the same language twice
                    // — however it was spelled the second time, because one card
                    // cannot hold two levels for one language.
                    ['name' => 'Английский', 'level' => 'fluent'],
                    ['name' => 'английский', 'level' => 'advanced'],
                ],
            ])
            ->assertSessionHasErrors(['languages.0.level', 'languages.0.name', 'languages.1.name']);
    }

    /**
     * A language nobody here has been written down as speaking is typed in and
     * kept as it was typed, down to the case.
     *
     * The dialog offers the words already on the cards, and that is all it is:
     * an offer. There is no list to add an entry to first, so the one thing to
     * prove is that an unheard-of name goes through and comes back whole —
     * trimmed of what the box picked up around it, and nothing else.
     */
    public function test_a_language_no_card_has_named_before_is_written_as_typed()
    {
        $admin = User::factory()->create()->assignRole('sysadmin');
        $employee = $this->colleague();

        $this->actingAs($admin)
            ->put("/employees/{$employee->id}/languages", [
                'languages' => [
                    ['name' => ' Китайский ', 'level' => 'beginner'],
                    ['name' => 'английский', 'level' => 'advanced'],
                ],
            ])
            ->assertSessionHasNoErrors();

        $spoken = $employee->languages()->pluck('level', 'name');

        $this->assertCount(2, $spoken);
        $this->assertSame('beginner', $spoken['Китайский'] ?? null);
        $this->assertSame('advanced', $spoken['английский'] ?? null);

        // The next person to type the same word gets a line of their own with a
        // level of their own: the word is an answer about one person, and there
        // is no row behind it that the two of them share.
        $other = $this->colleague();
        $this->actingAs($admin)
            ->put("/employees/{$other->id}/languages", [
                'languages' => [['name' => 'Китайский', 'level' => 'advanced']],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(['Китайский' => 'advanced'], $other->languages()->pluck('level', 'name')->all());
        $this->assertSame('beginner', $employee->languages()->where('name', 'Китайский')->value('level'));

        // And a level with no language in front of it is no answer at all.
        $this->put("/employees/{$employee->id}/languages", ['languages' => [['level' => 'beginner']]])
            ->assertSessionHasErrors('languages.0.name');
    }

    public function test_the_language_list_reaches_editors_only()
    {
        $admin = User::factory()->create()->assignRole('sysadmin');
        $employee = $this->colleague();
        $employee->languages()->create(['name' => 'Английский', 'level' => 'beginner']);

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
        $payload = ['languages' => [['name' => 'Английский', 'level' => 'advanced']]];

        // Not even their own: the card is managed by HR.
        $this->actingAs($employee)->put("/employees/{$employee->id}/languages", $payload)->assertForbidden();
        $this->actingAs($this->colleague())->put("/employees/{$employee->id}/languages", $payload)->assertForbidden();
    }
}
