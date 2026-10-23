<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserDetail;
use App\Support\Access;
use App\Support\EmployeeFields;
use Database\Seeders\PositionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * What a person sees and may change on their own card.
 *
 * Their own card is not a special case of somebody else's: a colleague who has
 * no business in anybody's passport still has a passport of their own, and a
 * company may well let nobody correct their own hire date. So the same lines are
 * asked about twice, and a position answers both.
 */
class OwnProfileFieldsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RoleSeeder::class, PositionSeeder::class]);
    }

    /** Somebody whose position holds exactly these rights, plus the staff list. */
    private function withRights(string ...$rights): User
    {
        $role = Role::create(['name' => 'p-'.Role::count(), 'title' => 'Роль '.Role::count(), 'guard_name' => 'web']);
        $role->syncPermissions(['employees.view', ...$rights]);

        return User::factory()
            ->has(UserDetail::factory()->state([
                'home_address' => 'Душанбе, Рудаки 55',
                'phone' => '905554433',
                'passport_number' => '1234567',
            ]), 'details')
            ->create(['surname' => 'Ятимов'])
            ->assignRole($role);
    }

    public function test_a_person_sees_the_lines_of_their_own_card_their_position_allows()
    {
        $employee = $this->withRights(
            EmployeeFields::permission('phone', EmployeeFields::OWN),
            EmployeeFields::permission('passport_number', EmployeeFields::OWN),
        );

        $this->actingAs($employee)
            ->get('/profile')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('employee.private.phone', '905554433')
                ->where('employee.private.passport.number', '1234567')
                // Not asked for, so not sent — their own card is no exception.
                ->missing('employee.private.home_address')
                ->where('visibleFields', fn ($fields) => collect($fields)->sort()->values()->all() === ['name', 'passport_number', 'phone', 'surname'])
            );
    }

    public function test_what_a_position_reads_on_other_cards_says_nothing_about_its_own()
    {
        // Reads a colleague's address and nothing of their own card.
        $employee = $this->withRights(EmployeeFields::permission('home_address'));
        $colleague = User::factory()->has(UserDetail::factory()->state(['home_address' => 'Хуҷанд, Ленина 1']), 'details')->create();

        $this->actingAs($employee)
            ->get("/employees/{$colleague->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('employee.private.home_address', 'Хуҷанд, Ленина 1'));

        $this->actingAs($employee)
            ->get('/profile')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->missing('employee.private.home_address')
                // Only the two lines nobody can close.
                ->where('visibleFields', fn ($fields) => collect($fields)->sort()->values()->all() === ['name', 'surname'])
            );
    }

    public function test_a_person_changes_their_own_line_only_where_the_position_says_so()
    {
        $employee = $this->withRights(
            EmployeeFields::permission('phone', EmployeeFields::OWN),
            EmployeeFields::permission('email', EmployeeFields::OWN),
        );

        // Sees the block, may change nothing in it.
        $this->actingAs($employee)
            ->put("/employees/{$employee->id}/contacts", ['email' => $employee->email, 'phone' => '900000000'])
            ->assertForbidden();

        $employee->roles->first()->givePermissionTo(EmployeeFields::editPermission('phone', EmployeeFields::OWN));
        $this->actingAs($employee->fresh())
            ->put("/employees/{$employee->id}/contacts", ['email' => $employee->email, 'phone' => '900000000'])
            ->assertRedirect();

        // Phones are stored in E.164, whatever was typed into the form.
        $this->assertSame('+992900000000', $employee->fresh()->details->phone);
    }

    public function test_the_right_to_change_a_colleagues_line_does_not_reach_ones_own()
    {
        $employee = $this->withRights(
            EmployeeFields::permission('phone'),
            EmployeeFields::editPermission('phone'),
            EmployeeFields::permission('phone', EmployeeFields::OWN),
        );
        $colleague = User::factory()->has(UserDetail::factory(), 'details')->create();

        // A colleague's telephone is theirs to correct.
        $this->actingAs($employee)
            ->put("/employees/{$colleague->id}/contacts", ['email' => $colleague->email, 'phone' => '911111111'])
            ->assertSessionHasNoErrors();

        // Their own is not, however plainly they can see it.
        $this->actingAs($employee)
            ->put("/employees/{$employee->id}/contacts", ['email' => $employee->email, 'phone' => '922222222'])
            ->assertForbidden();
    }

    public function test_a_position_starts_off_reading_its_own_card_whole()
    {
        // Taking a person's own card away from them is a decision somebody makes
        // on purpose, so a new position starts with all of it.
        foreach (EmployeeFields::keys() as $field) {
            // The surname and the name are read without a right at all, so there is
            // none of them to start with either.
            if (! in_array($field, EmployeeFields::ALWAYS_VISIBLE, true)) {
                $this->assertContains(EmployeeFields::permission($field, EmployeeFields::OWN), Access::defaults(), $field);
            }

            $this->assertNotContains(EmployeeFields::editPermission($field, EmployeeFields::OWN), Access::defaults(), $field);
        }

        $employee = User::factory()->has(UserDetail::factory(), 'details')->create()->assignRole('analyst');

        $this->actingAs($employee)
            ->get('/profile')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('visibleFields', fn ($fields) => count($fields) === count(EmployeeFields::keys()))
                ->where('editableFields', [])
            );
    }

    public function test_an_administrator_reads_and_changes_their_own_card_whole()
    {
        $admin = User::factory()->has(UserDetail::factory(), 'details')->create()->assignRole('sysadmin');

        $this->actingAs($admin)
            ->get('/profile')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('visibleFields', fn ($fields) => count($fields) === count(EmployeeFields::keys()))
                ->where('editableFields', fn ($fields) => count($fields) === count(EmployeeFields::keys()))
            );
    }

    public function test_the_two_catalogues_travel_side_by_side()
    {
        $sysadmin = User::factory()->create()->assignRole('sysadmin');

        foreach (['/directories/roles', '/directories/access'] as $url) {
            $this->actingAs($sysadmin)
                ->get($url)
                ->assertOk()
                ->assertInertia(fn (AssertableInertia $page) => $page
                    ->has('fields', count(EmployeeFields::GROUPS))
                    ->has('profileFields', count(EmployeeFields::GROUPS))
                    // The same lines, a different pair of rights behind each.
                    ->where('fields', fn ($groups) => collect($groups)->firstWhere('key', 'contacts')['fields'][1]['permission'] === 'employees.field.phone')
                    ->where('profileFields', fn ($groups) => collect($groups)->firstWhere('key', 'contacts')['fields'][1]['permission'] === 'profile.field.phone'
                        && collect($groups)->firstWhere('key', 'contacts')['fields'][1]['editPermission'] === 'profile.edit.phone')
                );
        }
    }

    public function test_the_profile_is_a_section_of_the_table_without_actions()
    {
        $this->actingAs(User::factory()->create()->assignRole('sysadmin'))
            ->get('/directories/access')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('sections', fn ($sections) => collect($sections)->firstWhere('key', 'profile')['title'] === 'Профиль'
                    // Nothing of the plain kind: a person always has a card, and
                    // what is on it is decided line by line.
                    && collect($sections)->firstWhere('key', 'profile')['rights'] === [])
            );
    }
}
