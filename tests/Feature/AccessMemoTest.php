<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\User;
use App\Support\EmployeeFields;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * What the pages spend on asking about rights, and what they must still answer
 * correctly while spending less.
 *
 * Every line of a card is a right, and a page that draws a column of faces used
 * to ask the whole catalogue of them once per face: «Структура компании» spent
 * three and a half thousand permission checks, and over a second of PHP, to draw
 * seventy-seven people into thirty kilobytes of JSON — because "may I see this
 * face?" was answered by working out all thirty lines of the card it belonged to,
 * and was asked twice per person. The staff list, the fleet and the equipment
 * journal all did the same thing a row at a time.
 *
 * The answers are filed for the length of one request now (App\Support\AccessMemo).
 * The first test pins the saving the way the fleet's own test pins its reads — by
 * counting, not by timing, because a stopwatch on one machine says nothing a
 * month later while "does this grow per row?" has a yes-or-no answer.
 *
 * The rest pins the part that matters more than the speed. A filed answer that
 * outlived the right it was about would be a leak rather than a saving, so the
 * key has to name everything the answer turns on — who is asking, and whose card
 * it is — and the filing has to end when the request does.
 */
class AccessMemoTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Drawing the chart asks about rights a fixed number of times, however many
     * people are on it.
     *
     * Four people are drawn, then sixteen. Before the answers were filed this
     * climbed by about sixty checks a head; it must now stay where it is.
     */
    public function test_the_company_chart_does_not_ask_the_rights_catalogue_once_per_person()
    {
        $unit = Department::create(['name' => 'Отдел']);
        $viewer = $this->colleague();
        $this->fill($unit, 4);

        $few = $this->rightsChecks(fn () => $this->actingAs($viewer)->get('/org-chart')->assertOk());

        $this->fill($unit, 12);

        $many = $this->rightsChecks(fn () => $this->actingAs($viewer)->get('/org-chart')->assertOk());

        // Room for a question or two about what the page happens to be drawing,
        // but not for a set of them per person: twelve more people used to cost
        // some seven hundred checks.
        $this->assertLessThan(
            $few + 60,
            $many,
            "the chart asked {$few} times for 4 people and {$many} for 16: the catalogue is being walked per row again",
        );
    }

    /**
     * A line closed between two visits is closed on the second one.
     *
     * The whole hazard of filing an answer about rights, and the test that fails
     * if the filing ever outlives the request it was made in.
     */
    public function test_a_line_closed_between_two_visits_is_closed_on_the_second()
    {
        $unit = Department::create(['name' => 'Отдел']);
        $colleague = User::factory()->create(['email' => 'nekto@evolet.co.uk']);
        $unit->users()->attach($colleague, ['is_head' => false]);
        $viewer = $this->colleague();

        // An address is one of the lines every position carries, so it is there
        // to begin with.
        $this->actingAs($viewer)->get("/departments/{$unit->id}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('department.members.0.email', 'nekto@evolet.co.uk'));

        $viewer->revokePermissionTo(EmployeeFields::permission('email'));

        // And the next visit must work the answer out again rather than read
        // the one filed a moment ago under the same viewer and the same scope.
        $this->actingAs($viewer->fresh())->get("/departments/{$unit->id}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('department.members.0.email', null));
    }

    /**
     * One's own card and a colleague's are two different questions, and the
     * answer filed for one is not an answer to the other.
     *
     * Asked without a visit in between, which is where the filing applies: both
     * calls below are answered out of the same request's notes, so a key that
     * left the scope out would hand this person their own rights over everybody.
     */
    public function test_the_filed_answer_tells_ones_own_card_from_a_colleagues()
    {
        // A line this person may read on their own card and on nobody else's.
        $viewer = User::factory()->create();
        $this->mayLookAround($viewer);
        $viewer->givePermissionTo(EmployeeFields::permission('home_address', EmployeeFields::OWN));
        $viewer->revokePermissionTo(EmployeeFields::permission('home_address'));
        $viewer = $viewer->fresh();

        $colleague = User::factory()->create();

        // Their own first, so the "own" answer is the one on file.
        $this->assertContains('home_address', EmployeeFields::visibleTo($viewer, $viewer));
        $this->assertNotContains('home_address', EmployeeFields::visibleTo($viewer, $colleague));

        // And the other way about, in case it is the first answer asked that
        // sticks: a fresh person, a colleague's card read before their own.
        $other = User::factory()->create();
        $this->mayLookAround($other);
        $other->givePermissionTo(EmployeeFields::permission('home_address', EmployeeFields::OWN));
        $other->revokePermissionTo(EmployeeFields::permission('home_address'));
        $other = $other->fresh();

        $this->assertNotContains('home_address', EmployeeFields::visibleTo($other, $colleague));
        $this->assertContains('home_address', EmployeeFields::visibleTo($other, $other));
    }

    /**
     * Whether anything private is readable is asked of the set of readable lines
     * rather than of the rights a second time, so it must still agree with them.
     */
    public function test_whether_anything_private_shows_agrees_with_the_lines_that_do()
    {
        $viewer = $this->colleague();
        $colleague = User::factory()->create();

        // Nothing private is in what every position carries.
        $this->assertFalse(EmployeeFields::anyPrivateVisibleTo($viewer, $colleague));

        $viewer->givePermissionTo(EmployeeFields::permission('birth_date'));
        $viewer = $viewer->fresh();

        $this->assertTrue(EmployeeFields::anyPrivateVisibleTo($viewer, $colleague));
        $this->assertContains('birth_date', EmployeeFields::visibleTo($viewer, $colleague));
    }

    /**
     * Some people in a department, so the chart has rows to draw.
     */
    private function fill(Department $unit, int $count): void
    {
        User::factory($count)->create()->each(
            fn (User $user) => $unit->users()->attach($user, ['is_head' => false]),
        );
    }

    /**
     * How many times a visit asks whether somebody may do something.
     *
     * Gate::after sees every check whichever way it was asked — a permission
     * carried by a position, a personal exception, a gate of the application's
     * own — which is the number this file is about.
     */
    private function rightsChecks(callable $visit): int
    {
        $checks = 0;
        Gate::after(function () use (&$checks) {
            $checks++;

            return null;
        });

        $visit();

        return $checks;
    }
}
