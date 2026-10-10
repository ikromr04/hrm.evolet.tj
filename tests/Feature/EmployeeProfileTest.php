<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Position;
use App\Models\User;
use App\Models\UserChild;
use App\Models\UserDetail;
use App\Models\UserEducation;
use App\Models\UserWorkExperience;
use Database\Seeders\PositionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class EmployeeProfileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RoleSeeder::class, PositionSeeder::class]);
    }

    public function test_guests_are_redirected_to_the_login_page()
    {
        $employee = User::factory()->create();

        $this->get("/employees/{$employee->id}")->assertRedirect('/login');
    }

    public function test_colleagues_see_only_the_public_profile()
    {
        $employee = User::factory()->has(UserDetail::factory(), 'details')->create();
        $employee->positions()->attach(Position::firstWhere('name', 'Переводчик'));

        $this->actingAs($this->colleague())
            ->get("/employees/{$employee->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('employees/show')
                ->where('employee.surname', $employee->surname)
                ->where('employee.positions', ['Переводчик'])
                ->where('employee.private', null)
            );
    }

    public function test_a_card_names_a_department_by_its_short_name()
    {
        $marketing = Department::create(['name' => 'Департамент маркетинга', 'abbreviation' => 'ДМ']);
        $design = Department::create(['name' => 'Отдел Дизайна', 'abbreviation' => 'ОД', 'parent_id' => $marketing->id]);
        // One that has no short name yet, which the line still has to print.
        $science = Department::create(['name' => 'Научный отдел', 'parent_id' => $marketing->id]);
        $employee = User::factory()->create();
        $employee->departments()->attach([$design->id, $science->id]);

        $this->actingAs($this->colleague())
            ->get("/employees/{$employee->id}")
            ->assertInertia(fn (Assert $page) => $page
                // Short on the card, with the spelled-out chain beside it for
                // the hover and the screen reader.
                ->where('employee.departments.0', [
                    'id' => $science->id,
                    'name' => 'Научный отдел',
                    'full_name' => 'Научный отдел',
                    'path' => 'ДМ › Научный отдел',
                    'full_path' => 'Департамент маркетинга › Научный отдел',
                    'is_head' => false,
                ])
                ->where('employee.departments.1', [
                    'id' => $design->id,
                    'name' => 'ОД',
                    'full_name' => 'Отдел Дизайна',
                    'path' => 'ДМ › ОД',
                    'full_path' => 'Департамент маркетинга › Отдел Дизайна',
                    'is_head' => false,
                ])
            );
    }

    public function test_the_profile_links_to_the_previous_and_next_colleague_in_the_same_list()
    {
        $a = $this->colleague(['surname' => 'Азимов', 'name' => 'Далер']);
        $b = User::factory()->create(['surname' => 'Азимов', 'name' => 'Фаррух']);
        $c = User::factory()->create(['surname' => 'Бобоева', 'name' => 'Нигина']);
        User::factory()->create(['surname' => 'Абдуллоев', 'name' => 'Умед', 'status' => 'fired']);

        $this->actingAs($a);

        $this->get("/employees/{$b->id}")->assertInertia(fn (Assert $page) => $page
            ->where('neighbours.prev', ['id' => $a->id, 'name' => 'Азимов Далер'])
            ->where('neighbours.next', ['id' => $c->id, 'name' => 'Бобоева Нигина'])
        );
        // Last of the list; someone who left is not in it.
        $this->get("/employees/{$c->id}")->assertInertia(fn (Assert $page) => $page->where('neighbours.next', null));

        // Their own card lives at its own address, and pages on from there to
        // the colleagues beside them in the list.
        $this->get("/employees/{$a->id}")->assertRedirect('/profile');
        $this->get('/profile')->assertInertia(fn (Assert $page) => $page
            ->where('neighbours.prev', null)
            ->where('neighbours.next', ['id' => $b->id, 'name' => 'Азимов Фаррух'])
        );
    }

    public function test_the_profile_has_no_arrows_for_someone_who_may_not_read_the_staff_list()
    {
        $user = $this->colleague();
        $user->revokePermissionTo('employees.view');
        User::factory()->create(['surname' => 'Яхёев']);

        $this->actingAs($user)->get('/profile')->assertInertia(fn (Assert $page) => $page->where('neighbours', null));
    }

    public function test_employee_sees_their_full_profile_including_passport()
    {
        $user = $this->mayLookAround(User::factory()->has(UserDetail::factory(), 'details')->create());
        UserChild::factory(2)->for($user)->create();
        UserEducation::factory()->for($user)->create();
        UserWorkExperience::factory()->for($user)->create(['organization' => 'ООО «Шифобахш»']);

        $this->actingAs($user)
            ->get('/profile')
            ->assertInertia(fn (Assert $page) => $page
                ->where('employee.private.passport.number', $user->details->passport_number)
                ->where('employee.private.passport.issued_by', $user->details->passport_issued_by)
                ->where('employee.private.birth_place', $user->details->birth_place)
                ->where('employee.private.sos_phone', $user->details->sos_phone)
                ->has('employee.private.children', 2)
                ->has('employee.private.educations', 1)
                ->where('employee.private.educations.0.institution', $user->educations->first()->institution)
                ->where('employee.private.work_experiences.0.organization', 'ООО «Шифобахш»')
            );
    }

    public function test_admin_sees_anyones_full_profile()
    {
        $admin = $this->colleague();
        $admin->assignRole('sysadmin');
        $employee = User::factory()->has(UserDetail::factory(), 'details')->create();

        $this->actingAs($admin)
            ->get("/employees/{$employee->id}")
            ->assertInertia(fn (Assert $page) => $page->where('employee.private.passport.number', $employee->details->passport_number));
    }

    public function test_unknown_employee_returns_404()
    {
        $this->actingAs($this->colleague())->get('/employees/999999')->assertNotFound();
    }
}
