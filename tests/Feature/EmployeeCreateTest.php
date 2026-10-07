<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Equipment;
use App\Models\EquipmentType;
use App\Models\Language;
use App\Models\Position;
use App\Models\User;
use App\Notifications\AccountCreated;
use App\Support\EmployeeFields;
use Database\Seeders\EquipmentTypeSeeder;
use Database\Seeders\PositionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;
use ReflectionClass;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Putting a new colleague on the books, from the form that opens as a page of
 * its own. Its first step creates the account they sign in with and files them
 * under a role, a position and a department; the steps after it fill the rest
 * of the profile in and may each be skipped.
 */
class EmployeeCreateTest extends TestCase
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
            'patronymic' => 'Рустамовна',
            'sex' => 'female',
            'email' => 'nilufar@evolet.tj',
            'birth_date' => '1990-04-17',
            'birth_place' => 'г. Худжанд',
            'citizenship' => ['Таджикистан'],
            'hired_at' => '2026-03-02',
            'roles' => [],
            'positions' => [],
            'departments' => [],
            ...$overrides,
        ];
    }

    /**
     * The one account that passes every check, whatever the rights say: these
     * tests are not about what a position may do.
     */
    private function sysadmin(): User
    {
        return User::factory()->create()->assignRole('sysadmin');
    }

    public function test_the_form_is_a_page_of_its_own_open_to_managers_only()
    {
        $this->actingAs($this->sysadmin())
            ->get('/employees/create')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('employees/create')
                ->has('options.roles')
                ->has('options.languages')
                ->has('options.stock')
            );

        $this->actingAs($this->colleague())->get('/employees/create')->assertForbidden();
    }

    public function test_an_admin_adds_a_colleague_and_lands_on_their_profile()
    {
        Notification::fake();
        $position = Position::query()->firstOrFail();
        $department = Department::create(['name' => 'Отдел разработки']);

        $response = $this->actingAs($this->sysadmin())->post('/employees', $this->payload([
            'roles' => ['specialist'],
            'positions' => [$position->id],
            'departments' => [$department->id],
        ]));

        $employee = User::firstWhere('email', 'nilufar@evolet.tj');
        $response->assertRedirect("/employees/{$employee->id}");

        $this->assertSame('Азимова', $employee->surname);
        $this->assertSame('female', $employee->sex);
        $this->assertSame('active', $employee->status);
        $this->assertSame('2026-03-02', $employee->details->hired_at->toDateString());
        // The first step also carries the personal facts of the profile's card.
        $this->assertSame('1990-04-17', $employee->details->birth_date->toDateString());
        $this->assertSame('г. Худжанд', $employee->details->birth_place);
        $this->assertSame(['Таджикистан'], $employee->citizenships->pluck('name')->all());
        $this->assertTrue($employee->hasRole('specialist'));
        $this->assertSame([$position->id], $employee->positions->pluck('id')->all());
        $this->assertSame([$department->id], $employee->departments->pluck('id')->all());

        Notification::assertSentTo($employee, AccountCreated::class);
    }

    public function test_the_password_is_generated_and_mailed_rather_than_typed()
    {
        Notification::fake();

        // A password sent from the browser is ignored: the one that works is
        // the one generated here and mailed to the new colleague.
        $this->actingAs($this->sysadmin())->post('/employees', $this->payload(['password' => 'подсунутый-пароль']));

        $employee = User::firstWhere('email', 'nilufar@evolet.tj');
        $this->assertFalse(Hash::check('подсунутый-пароль', $employee->password));

        Notification::assertSentTo($employee, AccountCreated::class, function (AccountCreated $mail) use ($employee) {
            $password = (new ReflectionClass($mail))->getProperty('password')->getValue($mail);
            $body = implode(' ', $mail->toMail($employee)->introLines);

            // Between eight and twelve characters, and it is in the letter.
            $this->assertGreaterThanOrEqual(8, strlen($password));
            $this->assertLessThanOrEqual(12, strlen($password));
            $this->assertTrue(Hash::check($password, $employee->password));

            return str_contains($body, $password) && str_contains($body, $employee->email);
        });
    }

    public function test_the_form_needs_a_name_and_a_free_address()
    {
        $taken = User::factory()->create(['email' => 'taken@evolet.tj']);
        $admin = $this->sysadmin();

        $this->actingAs($admin)->post('/employees', [])
            ->assertSessionHasErrors(['surname', 'name', 'sex', 'email']);

        $this->actingAs($admin)->post('/employees', $this->payload(['email' => $taken->email]))
            ->assertSessionHasErrors('email');

        // A hire date in the future would read as negative service.
        $this->actingAs($admin)->post('/employees', $this->payload(['hired_at' => now()->addWeek()->toDateString()]))
            ->assertSessionHasErrors('hired_at');

        $this->assertSame(2, User::count());
    }

    public function test_the_wizard_keeps_the_new_colleague_on_the_list_to_carry_on()
    {
        Notification::fake();

        // The first step of the wizard asks to continue: it stays where it is
        // and is handed whom it has just created, to fill the rest in.
        $this->actingAs($this->sysadmin())
            ->from('/employees')
            ->post('/employees', $this->payload(['continue' => true]))
            ->assertRedirect('/employees')
            ->assertSessionHas('employee', fn (array $employee) => $employee['name'] === 'Азимова Нилуфар');
    }

    public function test_the_one_position_that_cannot_be_handed_out()
    {
        // Every position is given like any other, and one is given to nobody:
        // there is a single system administrator and never a second.
        $this->actingAs($this->sysadmin())
            ->post('/employees', $this->payload(['roles' => ['sysadmin']]))
            ->assertSessionHasErrors('roles.0');

        $this->assertNull(User::firstWhere('email', 'nilufar@evolet.tj'));

        $this->actingAs($this->sysadmin())
            ->post('/employees', $this->payload(['roles' => ['admin']]))
            ->assertSessionHasNoErrors();

        $this->assertTrue(User::firstWhere('email', 'nilufar@evolet.tj')->hasRole('admin'));
    }

    public function test_an_unknown_role_position_or_department_is_refused()
    {
        $admin = $this->sysadmin();

        $this->actingAs($admin)->post('/employees', $this->payload(['roles' => ['ceo']]))->assertSessionHasErrors('roles.0');
        $this->actingAs($admin)->post('/employees', $this->payload(['positions' => [9999]]))->assertSessionHasErrors('positions.0');
        $this->actingAs($admin)->post('/employees', $this->payload(['departments' => [9999]]))->assertSessionHasErrors('departments.0');

        $this->assertSame(1, User::count());
    }

    public function test_an_ordinary_colleague_cannot_add_anybody()
    {
        $this->actingAs($this->colleague())
            ->post('/employees', $this->payload())
            ->assertForbidden();

        $this->assertNull(User::firstWhere('email', 'nilufar@evolet.tj'));
    }

    /** Somebody whose position holds exactly these rights, plus the staff list. */
    private function withRights(string ...$rights): User
    {
        $role = Role::create(['name' => 'r-'.Role::count(), 'title' => 'Роль '.Role::count(), 'guard_name' => 'web']);
        $role->syncPermissions(['employees.view', ...$rights]);

        return User::factory()->create()->assignRole($role);
    }

    /**
     * Puts a colleague on the books the way the wizard's first step does.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function startWizard(User $creator, array $overrides = []): User
    {
        Notification::fake();

        $this->actingAs($creator)
            ->from('/employees/create')
            ->post('/employees', $this->payload(['continue' => true, ...$overrides]))
            ->assertSessionHasNoErrors()
            ->assertRedirect('/employees/create');

        return User::firstWhere('email', $overrides['email'] ?? 'nilufar@evolet.tj');
    }

    public function test_adding_a_colleague_is_a_right_of_its_own()
    {
        // Lines of a card to change are not the right to add anybody.
        $editor = $this->withRights('employees.field.phone', 'employees.edit.phone', 'employees.field.passport_number', 'employees.edit.passport_number');

        $this->actingAs($editor)->get('/employees/create')->assertForbidden();
        $this->actingAs($editor)->post('/employees', $this->payload())->assertForbidden();
        $this->assertNull(User::firstWhere('email', 'nilufar@evolet.tj'));

        // Nor is the button offered: the list is told so, and the dashboard reads
        // the right from the shared map every page carries.
        $this->actingAs($editor)->get('/employees')->assertInertia(fn (AssertableInertia $page) => $page->where('canCreate', false));
        $this->assertFalse($this->actingAs($editor)->get('/employees')->viewData('page')['props']['auth']['can']['employees.create']);

        $creator = $this->withRights('employees.create');

        $this->actingAs($creator)->get('/employees/create')->assertOk();
        $this->actingAs($creator)->get('/employees')->assertInertia(fn (AssertableInertia $page) => $page->where('canCreate', true));
        $this->assertTrue($this->actingAs($creator)->get('/employees')->viewData('page')['props']['auth']['can']['employees.create']);

        // The system administrator holds no rights and still passes this one.
        $this->actingAs($this->sysadmin())->get('/employees')->assertInertia(fn (AssertableInertia $page) => $page->where('canCreate', true));
    }

    public function test_whoever_adds_a_colleague_fills_the_whole_card_in()
    {
        // Not one line of anybody's card is theirs to change, and still the whole
        // card of the person they are adding is.
        $creator = $this->withRights('employees.create');
        $position = Position::query()->firstOrFail();
        $department = Department::create(['name' => 'Отдел разработки']);
        $language = Language::create(['name' => 'Английский']);

        // The first step offers everything it asks for, the one position nobody
        // is handed excepted.
        $this->actingAs($creator)->get('/employees/create')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('options.roles', fn ($roles) => collect($roles)->pluck('name')->contains('specialist') && ! collect($roles)->pluck('name')->contains('sysadmin'))
            ->has('options.positions', Position::count())
            ->has('options.departments', 1)
        );

        $employee = $this->startWizard($creator, [
            'roles' => ['specialist'],
            'positions' => [$position->id],
            'departments' => [$department->id],
        ]);

        $this->assertTrue($employee->hasRole('specialist'));
        $this->assertSame([$employee->id], session(EmployeeFields::CREATING));

        // Stepping back to the first step corrects what it filed.
        $this->actingAs($creator)->put("/employees/{$employee->id}/personal", [
            ...$this->payload(['surname' => 'Азимова-Каримова', 'roles' => ['translator'], 'positions' => [], 'departments' => [$department->id]]),
        ])->assertSessionHasNoErrors();
        $this->actingAs($creator)->put("/employees/{$employee->id}/employment", ['hired_at' => '2026-04-01'])->assertSessionHasNoErrors();

        $this->actingAs($creator)->put("/employees/{$employee->id}/contacts", [
            'email' => 'nilufar.a@evolet.tj', 'phone' => '901112233', 'sos_phone' => '902223344', 'sos_contact' => 'Мама',
        ])->assertSessionHasNoErrors();
        $this->actingAs($creator)->put("/employees/{$employee->id}/languages", [
            'languages' => [['id' => $language->id, 'level' => Language::LEVELS[0]]],
        ])->assertSessionHasNoErrors();
        $this->actingAs($creator)->put("/employees/{$employee->id}/passport", [
            'passport_series' => 'A', 'passport_number' => '1234567', 'passport_issued_at' => '2020-01-01', 'passport_issued_by' => 'ОВД',
        ])->assertSessionHasNoErrors();
        $this->actingAs($creator)->put("/employees/{$employee->id}/family", [
            'marital_status' => 'married', 'spouse_name' => 'Азимов Рустам', 'spouse_birth_date' => '1988-02-02',
            'has_children' => true, 'children' => [['full_name' => 'Азимова Сабина', 'birth_date' => '2015-05-05']],
        ])->assertSessionHasNoErrors();
        $this->actingAs($creator)->post("/employees/{$employee->id}/educations/many", [
            'records' => [['institution' => 'ТНУ', 'faculty' => 'Экономический', 'specialty' => 'Финансы', 'started_year' => 2008, 'graduated_year' => 2012]],
        ])->assertSessionHasNoErrors();
        $this->actingAs($creator)->post("/employees/{$employee->id}/experiences/many", [
            'records' => [['organization' => 'ООО «Ориён»', 'position' => 'Аналитик', 'country' => 'Таджикистан', 'started_month' => 3, 'started_year' => 2013, 'ended_month' => 8, 'ended_year' => 2020]],
        ])->assertSessionHasNoErrors();

        $employee->refresh();
        $this->assertSame('Азимова-Каримова', $employee->surname);
        $this->assertTrue($employee->hasRole('translator'));
        $this->assertSame([], $employee->positions->pluck('id')->all());
        $this->assertSame('2026-04-01', $employee->details->hired_at->toDateString());
        $this->assertSame('nilufar.a@evolet.tj', $employee->email);
        $this->assertSame('+992901112233', $employee->details->phone);
        $this->assertCount(1, $employee->languages);
        $this->assertSame('1234567', $employee->details->passport_number);
        $this->assertSame('married', $employee->details->marital_status);
        $this->assertCount(1, $employee->children);
        $this->assertCount(1, $employee->educations);
        $this->assertCount(1, $employee->workExperiences);
    }

    public function test_the_whole_card_is_only_the_one_being_added()
    {
        $creator = $this->withRights('employees.create');
        $existing = User::factory()->create();
        $this->startWizard($creator);

        // Somebody already on the books is edited under the rights to their lines,
        // and this creator has none.
        $this->actingAs($creator)->put("/employees/{$existing->id}/passport", ['passport_number' => '7654321'])->assertForbidden();
        $this->actingAs($creator)->put("/employees/{$existing->id}/personal", $this->payload(['email' => $existing->email]))->assertForbidden();
        $this->actingAs($creator)->post("/employees/{$existing->id}/educations/many", ['records' => []])->assertForbidden();
    }

    public function test_adding_ends_once_the_card_is_opened()
    {
        $creator = $this->withRights('employees.create');
        $employee = $this->startWizard($creator);

        $this->actingAs($creator)->put("/employees/{$employee->id}/passport", ['passport_number' => '1234567'])->assertSessionHasNoErrors();

        // «Закончить позже» and «Готово» both lead to the card; from there on it
        // is edited under the ordinary rights to its lines.
        $this->actingAs($creator)->get("/employees/{$employee->id}")->assertOk();
        $this->assertNull(session(EmployeeFields::CREATING));

        $this->actingAs($creator)->put("/employees/{$employee->id}/passport", ['passport_number' => '7654321'])->assertForbidden();
        $this->assertSame('1234567', $employee->details()->first()->passport_number);
    }

    public function test_losing_the_right_closes_the_card_being_added()
    {
        $creator = $this->withRights('employees.create');
        $employee = $this->startWizard($creator);

        $creator->roles()->first()->revokePermissionTo('employees.create');

        $this->actingAs($creator->fresh())->put("/employees/{$employee->id}/passport", ['passport_number' => '1234567'])->assertForbidden();
    }

    public function test_handing_hardware_out_still_takes_the_right_to_issue_it()
    {
        $this->seed(EquipmentTypeSeeder::class);
        $laptop = Equipment::factory()->ofType(EquipmentType::firstWhere('name', 'Ноутбуки'))->create();

        // Without it the last step offers nothing and the save is refused.
        $creator = $this->withRights('employees.create');
        $this->actingAs($creator)->get('/employees/create')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('canIssue', false)
            ->where('options.stock', [])
        );
        $employee = $this->startWizard($creator);
        $this->actingAs($creator)->post("/employees/{$employee->id}/equipment", ['equipment' => [$laptop->id]])->assertForbidden();
        $this->assertSame('stock', $laptop->refresh()->status);

        // With it, the workplace is put together in the same go.
        $keeper = $this->withRights('employees.create', 'equipment.issue');
        $this->actingAs($keeper)->get('/employees/create')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('canIssue', true)
            ->has('options.stock', 1)
        );
        $other = $this->startWizard($keeper, ['email' => 'second@evolet.tj']);
        $this->actingAs($keeper)->post("/employees/{$other->id}/equipment", ['equipment' => [$laptop->id]])->assertSessionHasNoErrors();
        $this->assertSame($other->id, $laptop->refresh()->holder_user_id);
    }
}
