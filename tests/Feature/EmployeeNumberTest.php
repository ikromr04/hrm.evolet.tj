<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserDetail;
use Database\Seeders\PositionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * The three lines «Основное» holds beside the position and the department: the
 * number the company files somebody under, the kind of work they were taken on
 * for and the place they work from.
 */
class EmployeeNumberTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RoleSeeder::class, PositionSeeder::class]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'surname' => 'Азимова',
            'name' => 'Нилуфар',
            'sex' => 'female',
            // The role is left out rather than sent empty: this window carries
            // it only for whoever picks it, and an empty one is refused.
            'placements' => [],
            ...$overrides,
        ];
    }

    public function test_an_account_is_numbered_as_it_is_created()
    {
        $employee = User::factory()->create();

        $this->assertSame(User::numberOf($employee->id), $employee->fresh()->employee_number);
        $this->assertSame(5, mb_strlen($employee->fresh()->employee_number));
    }

    /**
     * What a number may be: the company's own business, down to a shape nobody
     * here prescribes.
     *
     * It used to be refused unless it was five digits or more and nothing but
     * digits. That is no longer asked: a card takes the number it is given, of
     * whatever shape, as long as it fits the column and nobody else carries it.
     * The two things still asked are the two that are not about taste — a
     * number two cards share names neither of them, and a box left empty is no
     * number rather than a number of none.
     */
    public function test_a_number_is_taken_as_given_but_must_be_the_only_one()
    {
        $admin = User::factory()->create()->assignRole('sysadmin');
        $employee = User::factory()->has(UserDetail::factory(), 'details')->create();
        $this->actingAs($admin);

        // Short, and written with a letter in it: both stand.
        foreach (['1234', 'А0001', '004217'] as $number) {
            $this->put("/employees/{$employee->id}/personal", $this->payload(['employee_number' => $number]))
                ->assertSessionHasNoErrors();
            $this->assertSame($number, $employee->fresh()->employee_number);
        }

        // Longer than the column, which is the one shape still refused.
        $this->put("/employees/{$employee->id}/personal", $this->payload(['employee_number' => str_repeat('7', 21)]))
            ->assertSessionHasErrors('employee_number');

        // No two cards carry the same one.
        $other = User::factory()->has(UserDetail::factory(), 'details')->create();
        $this->put("/employees/{$other->id}/personal", $this->payload(['employee_number' => '004217']))
            ->assertSessionHasErrors('employee_number');

        // A box left empty is no number at all.
        $this->put("/employees/{$employee->id}/personal", $this->payload(['employee_number' => ' ']))
            ->assertSessionHasNoErrors();
        $this->assertNull($employee->fresh()->employee_number);
    }

    public function test_a_window_saves_only_the_lines_it_carries()
    {
        $admin = User::factory()->create()->assignRole('sysadmin');
        $employee = User::factory()->has(UserDetail::factory(), 'details')->create(['surname' => 'Азимова', 'name' => 'Нилуфар']);
        $employee->details()->update(['nationality' => 'таджичка']);
        $this->actingAs($admin);

        // «Основное» carries neither the name nor the role, and a save from it
        // leaves both as they were rather than emptying them.
        $this->put("/employees/{$employee->id}/personal", ['employee_number' => '004217'])->assertSessionHasNoErrors();

        $employee->refresh();
        $this->assertSame('004217', $employee->employee_number);
        $this->assertSame('Азимова', $employee->surname);
        $this->assertSame('таджичка', $employee->details->nationality);
    }

    /**
     * The kind of work: a word on the card, filed as it was typed.
     *
     * It used to point at a list the company kept — matched to an entry of it
     * without regard to case, and written into the list when the box named
     * something new. That list is gone, as the branch somebody worked from went
     * before it: the kind of work is a line of the account like the number
     * beside it, and what the next card is offered is what the cards already
     * say. So what the pair of them proved about pointing at a list is proved
     * here about the word that replaced it.
     */
    public function test_the_kind_of_work_is_a_word_on_the_card()
    {
        $admin = User::factory()->create()->assignRole('sysadmin');
        $employee = User::factory()->has(UserDetail::factory(), 'details')->create();
        $this->actingAs($admin);

        $this->put("/employees/{$employee->id}/personal", $this->payload([
            'employment_type' => '  Полная занятость  ',
        ]))->assertSessionHasNoErrors();

        // Kept as it was typed, down to the case, and trimmed of what the box
        // picked up around it: there is no entry for it to be matched to.
        $this->assertSame('Полная занятость', $employee->fresh()->employment_type);

        // The card reads it back by name, and offers what the cards say to
        // whoever opens the box again.
        $this->get("/employees/{$employee->id}")->assertInertia(fn (AssertableInertia $page) => $page
            ->where('employee.employment_type', 'Полная занятость')
            ->where('employee.employee_number', User::numberOf($employee->id))
            ->where('options.employment_types', ['Полная занятость'])
        );

        // A word nobody has typed here before is no different from one that has
        // been: it is simply what this card now says.
        $this->put("/employees/{$employee->id}/personal", $this->payload(['employment_type' => 'Подработка']))
            ->assertSessionHasNoErrors();
        $this->assertSame('Подработка', $employee->fresh()->employment_type);

        // And the word another card carries is that card's own, offered to this
        // one and nothing more — changing this line changed nobody else's.
        $other = User::factory()->has(UserDetail::factory(), 'details')->create(['employment_type' => 'Полная занятость']);
        $this->assertSame('Полная занятость', $other->fresh()->employment_type);
        $this->get("/employees/{$employee->id}")->assertInertia(fn (AssertableInertia $page) => $page
            ->where('options.employment_types', ['Подработка', 'Полная занятость'])
        );

        // An empty box is no answer at all.
        $this->put("/employees/{$employee->id}/personal", $this->payload(['employment_type' => '']))
            ->assertSessionHasNoErrors();
        $this->assertNull($employee->fresh()->employment_type);
    }
}
