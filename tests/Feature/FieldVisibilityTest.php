<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\User;
use App\Models\UserChild;
use App\Models\UserDetail;
use App\Support\EmployeeFields;
use Database\Seeders\PositionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Which lines of a card a position reads.
 *
 * A position is given the fields it needs rather than "personal data" as a lump:
 * the head of a department has business knowing a telephone number and none at
 * all knowing a passport. What is not open does not travel to the page at all —
 * not in the card, not in a column of the list, and not through a filter or the
 * search, which would tell whose it is without ever showing it.
 */
class FieldVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RoleSeeder::class, PositionSeeder::class]);
    }

    /** Somebody whose position reads exactly these fields, and the staff list. */
    private function reader(string ...$fields): User
    {
        $role = Role::create(['name' => 'reader-'.Role::count(), 'title' => 'Читатель '.Role::count(), 'guard_name' => 'web']);
        $role->syncPermissions(['employees.view', ...array_map(EmployeeFields::permission(...), $fields)]);

        // Фамилия на «Я»: список сортируется по фамилии, и смотрящий не должен
        // оказаться первой строкой вместо того, кого проверяет тест.
        return User::factory()->create(['surname' => 'Ятимов'])->assignRole($role);
    }

    private function colleagueWithEverything(): User
    {
        $employee = User::factory()
            ->has(UserDetail::factory()->state([
                'home_address' => 'Душанбе, Рудаки 55',
                'phone' => '905554433',
                'passport_number' => '1234567',
                'marital_status' => 'married',
                'spouse_name' => 'Азимова Нигина',
            ]), 'details')
            ->create(['surname' => 'Азимов', 'name' => 'Далер', 'patronymic' => 'Саидович']);

        UserChild::factory()->for($employee)->create();

        return $employee;
    }

    public function test_a_card_shows_the_lines_the_position_reads_and_no_others()
    {
        $employee = $this->colleagueWithEverything();

        $this->actingAs($this->reader('phone', 'patronymic'))
            ->get("/employees/{$employee->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                // The name is never hidden, and what was asked for is there.
                ->where('employee.surname', 'Азимов')
                ->where('employee.patronymic', 'Саидович')
                ->where('employee.private.phone', '905554433')
                // Everything else is absent rather than empty.
                ->missing('employee.private.home_address')
                ->missing('employee.private.marital_status')
                ->missing('employee.email')
                ->where('employee.private.passport.number', null)
                // The surname and the name need no right, so they are always there.
                ->where('visibleFields', fn ($fields) => collect($fields)->sort()->values()->all() === ['name', 'patronymic', 'phone', 'surname'])
            );
    }

    public function test_a_field_nobody_may_read_is_not_a_column_either()
    {
        $this->colleagueWithEverything();

        $this->actingAs($this->reader('positions'))
            ->get('/employees?sort=name')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('employees.data.0.surname', 'Азимов')
                ->missing('employees.data.0.patronymic')
                ->missing('employees.data.0.email')
                ->missing('employees.data.0.departments')
                ->where('employees.data.0.positions', [])
                ->where('visibleFields', fn ($fields) => collect($fields)->sort()->values()->all() === ['name', 'positions', 'surname'])
            );
    }

    public function test_a_department_is_searched_by_its_short_name_only_where_that_line_is_read()
    {
        $employee = $this->colleagueWithEverything();
        $employee->departments()->attach(Department::create(['name' => 'Отдел Дизайна', 'abbreviation' => 'ОД']));

        // The column is open, and the short name is what it prints, so it is
        // what somebody types to find the people of a department.
        $this->actingAs($this->reader('departments'))
            ->get('/employees?q='.urlencode('ОД'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('employees.data', 1)
                ->where('employees.data.0.id', $employee->id)
            );

        // Closed: matching by it would say who sits where without ever showing it.
        $this->actingAs($this->reader('positions'))
            ->get('/employees?q='.urlencode('ОД'))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('employees.data', 0));
    }

    public function test_a_hidden_field_cannot_be_filtered_or_sorted_by()
    {
        $reader = $this->reader('phone');

        // The address is not readable, so narrowing the list by it is refused
        // rather than quietly ignored.
        $this->actingAs($reader)->get('/employees?address='.urlencode('Рудаки'))->assertSessionHasErrors('address');
        $this->actingAs($reader)->get('/employees?sort=home_address')->assertSessionHasErrors('sort');
        $this->actingAs($reader)->get('/employees?department[]=1')->assertSessionHasErrors('department');

        // What is readable still works.
        $this->actingAs($reader)->get('/employees?phone=905')->assertSessionHasNoErrors();
        $this->actingAs($reader)->get('/employees?sort=phone')->assertSessionHasNoErrors();
    }

    public function test_the_search_does_not_match_what_the_viewer_cannot_read()
    {
        $employee = $this->colleagueWithEverything();

        // The address is somebody else's business: a word from it finds nobody.
        $this->actingAs($this->reader('phone'))
            ->get('/employees?q='.urlencode('Рудаки'))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('employees.data', 0));

        // For a viewer who reads the address, the same word finds them.
        $this->actingAs($this->reader('home_address'))
            ->get('/employees?q='.urlencode('Рудаки'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('employees.data', 1)
                ->where('employees.data.0.id', $employee->id)
            );
    }

    public function test_a_person_reads_their_own_card_the_way_their_position_says()
    {
        $employee = $this->colleagueWithEverything();
        // A position that reads nothing of anybody else's card. Its own is
        // another question, and the set every position starts with answers it
        // with the whole card — see OwnProfileFieldsTest for the narrow cases.
        $this->mayLookAround($employee);

        $this->actingAs($employee)
            ->get('/profile')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('employee.private.home_address', 'Душанбе, Рудаки 55')
                ->where('employee.private.passport.number', '1234567')
                ->where('visibleFields', fn ($fields) => count($fields) === count(EmployeeFields::keys()))
            );

        // And their own row in the list, whatever the columns show.
        $this->actingAs($employee)
            ->get('/employees')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('employees.data.0.private.home_address', 'Душанбе, Рудаки 55'));
    }

    public function test_an_administrator_reads_every_line()
    {
        $employee = $this->colleagueWithEverything();

        $this->actingAs(User::factory()->create()->assignRole('sysadmin'))
            ->get("/employees/{$employee->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('employee.private.home_address', 'Душанбе, Рудаки 55')
                ->where('visibleFields', fn ($fields) => count($fields) === count(EmployeeFields::keys()))
            );
    }

    public function test_a_field_is_given_to_one_person_the_way_any_other_right_is()
    {
        $employee = $this->colleagueWithEverything();
        $reader = $this->reader();

        $this->actingAs($reader)
            ->get("/employees/{$employee->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page->missing('employee.private.home_address'));

        // The exception on a card beats the position, fields included.
        $this->actingAs(User::factory()->create()->assignRole('sysadmin'))
            ->put("/employees/{$reader->id}/access", ['permission' => 'employees.field.home_address', 'allowed' => true])
            ->assertRedirect();

        $this->actingAs($reader->fresh())
            ->get("/employees/{$employee->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('employee.private.home_address', 'Душанбе, Рудаки 55'));
    }

    public function test_the_position_dialog_and_the_access_table_offer_the_field_list()
    {
        $sysadmin = User::factory()->create()->assignRole('sysadmin');

        foreach (['/directories/roles', '/directories/access'] as $url) {
            $this->actingAs($sysadmin)
                ->get($url)
                ->assertOk()
                ->assertInertia(fn (AssertableInertia $page) => $page
                    ->has('fields', count(EmployeeFields::GROUPS))
                    ->where('fields', fn ($groups) => collect($groups)->firstWhere('key', 'passport')['fields'][0]['permission'] === 'employees.field.passport_number')
                );
        }
    }

    public function test_a_position_is_given_its_fields_in_its_own_dialog()
    {
        $role = Role::findByName('analyst');

        $this->actingAs(User::factory()->create()->assignRole('sysadmin'))
            ->put("/directories/roles/{$role->id}", [
                'title' => 'Аналитик',
                'permissions' => ['employees.view', 'employees.field.phone', 'employees.field.email'],
            ])
            ->assertRedirect();

        $this->assertSame(
            ['employees.field.email', 'employees.field.phone', 'employees.view'],
            $role->fresh()->permissions->pluck('name')->sort()->values()->all(),
        );
    }
}
