<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\EmploymentType;
use App\Models\Equipment;
use App\Models\EquipmentType;
use App\Models\Language;
use App\Models\Position;
use App\Models\PositionDuty;
use App\Models\User;
use App\Models\UserChild;
use App\Models\UserEducation;
use App\Models\UserWorkExperience;
use App\Notifications\AccountCreated;
use App\Support\Placements;
use Database\Seeders\EquipmentTypeSeeder;
use Database\Seeders\PositionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Putting a new colleague on the books, from the page that asks for the whole
 * card at once: who they are, how to reach them, their papers and their family,
 * where they studied, where they worked and what hardware they are handed.
 *
 * It all travels in one request and is written in one transaction, so the two
 * things worth pinning hardest are that everything typed arrives — and that a
 * refusal in the last section of the page leaves nobody behind in the first.
 * Only the account section is demanded; the rest may be left empty and written
 * on the card later.
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
            'placements' => [],
            ...$overrides,
        ];
    }

    /**
     * The same card with every other section of the page filled in too, which
     * is what the one request carries when somebody fills the page out whole.
     *
     * The language is named rather than numbered on purpose: a language the
     * directory has not heard of is added to it on the way in, and that is the
     * one thing about saving this section which is not a plain write.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function wholeCard(array $overrides = []): array
    {
        return $this->payload([
            /* Контакты и языки */
            'phone' => '901112233',
            'sos_phone' => '902223344',
            'sos_contact' => 'Мама',
            'languages' => [['name' => 'Английский', 'level' => Language::LEVELS[0]]],

            /* Паспорт и семья */
            'passport_series' => 'A',
            'passport_number' => '1234567',
            'passport_issued_at' => '2020-01-01',
            'passport_issued_by' => 'ОВД',
            'marital_status' => 'married',
            'spouse_name' => 'Азимов Рустам',
            'spouse_birth_date' => '1988-02-02',
            'has_children' => true,
            'children' => [['full_name' => 'Азимова Сабина', 'birth_date' => '2015-05-05']],

            /* Образование */
            'educations' => [
                ['institution' => 'ТНУ', 'faculty' => 'Экономический', 'specialty' => 'Финансы', 'started_year' => 2008, 'graduated_year' => 2012],
            ],

            /* Трудовая деятельность */
            'experiences' => [
                [
                    'organization' => 'ООО «Ориён»',
                    'position' => 'Аналитик',
                    'country' => 'Таджикистан',
                    'started_month' => 3,
                    'started_year' => 2013,
                    'ended_month' => 8,
                    'ended_year' => 2020,
                ],
            ],

            ...$overrides,
        ]);
    }

    /**
     * A department with a position in it and two duties on that position's list:
     * the chain the form walks down, and the one it sends back.
     *
     * @return array{Department, Position, PositionDuty, PositionDuty}
     */
    private function chain(string $name = 'Отдел разработки'): array
    {
        $department = Department::create(['name' => $name]);
        $position = $department->positions()->create(['name' => 'Аналитик']);

        return [
            $department,
            $position,
            $position->duties()->create(['name' => 'Ведение договоров', 'order' => 0]),
            $position->duties()->create(['name' => 'Закрытие месяца', 'order' => 1]),
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
        [$department, $position, $duty] = $this->chain();

        $response = $this->actingAs($this->sysadmin())->post('/employees', $this->payload([
            'roles' => ['specialist'],
            'placements' => [
                ['position_id' => $position->id, 'duty_ids' => [$duty->id]],
            ],
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
        // One duty of the two the position answers for: holding it does not hand
        // over the whole list.
        $this->assertSame([$duty->id], $employee->duties->pluck('id')->all());

        Notification::assertSentTo($employee, AccountCreated::class);
    }

    /**
     * The company's own number for somebody, and the kind of work they were
     * taken on for.
     *
     * Both sit on the account rather than on the details beside it, and the kind
     * of work is named rather than numbered: one the directory has not heard of
     * is written into the directory on the way in, so a card can be filled in
     * without a trip to «Справочники» first.
     */
    public function test_the_number_and_the_kind_of_work_land_on_the_account()
    {
        Notification::fake();

        $this->actingAs($this->sysadmin())->post('/employees', $this->payload([
            'employee_number' => '00418',
            'employment_type' => 'Полная занятость',
        ]))->assertSessionHasNoErrors();

        $employee = User::firstWhere('email', 'nilufar@evolet.tj');

        // Kept as typed: the nought in front is the company's numbering and not
        // an accident, which a number would have eaten.
        $this->assertSame('00418', $employee->employee_number);
        $this->assertSame('Полная занятость', $employee->employmentType->name);
        $this->assertSame(['Полная занятость'], EmploymentType::query()->pluck('name')->all());
    }

    /**
     * The number is the one line of a card no two people may share.
     *
     * Refused before anything is written, like every other refusal on this page:
     * the whole card is one request, so a number somebody else carries leaves no
     * half-made colleague behind.
     */
    public function test_a_number_somebody_else_carries_is_refused()
    {
        Notification::fake();
        User::factory()->create(['employee_number' => '00418']);

        $this->actingAs($this->sysadmin())
            ->post('/employees', $this->payload(['employee_number' => '00418']))
            ->assertSessionHasErrors('employee_number');

        $this->assertNull(User::firstWhere('email', 'nilufar@evolet.tj'));
    }

    public function test_the_whole_card_travels_in_one_request()
    {
        Notification::fake();
        $this->seed(EquipmentTypeSeeder::class);
        [$department, $position, $duty] = $this->chain();
        $laptop = Equipment::factory()->ofType(EquipmentType::firstWhere('name', 'Ноутбуки'))->create();

        $response = $this->actingAs($this->sysadmin())->post('/employees', $this->wholeCard([
            'roles' => ['specialist'],
            'placements' => [['position_id' => $position->id, 'duty_ids' => [$duty->id]]],
            'equipment' => [$laptop->id],
            'issued_at' => '2026-03-02',
        ]));

        $response->assertSessionHasNoErrors();
        $employee = User::firstWhere('email', 'nilufar@evolet.tj');
        $response->assertRedirect("/employees/{$employee->id}");

        // Основное.
        $this->assertSame('Азимова', $employee->surname);
        $this->assertSame('female', $employee->sex);
        $this->assertSame('2026-03-02', $employee->details->hired_at->toDateString());
        $this->assertSame('г. Худжанд', $employee->details->birth_place);
        $this->assertTrue($employee->hasRole('specialist'));
        $this->assertSame([$position->id], $employee->positions->pluck('id')->all());
        $this->assertSame([$department->id], $employee->departments->pluck('id')->all());
        $this->assertSame([$duty->id], $employee->duties->pluck('id')->all());

        // Контакты и языки. The phone was typed as nine local digits and is on
        // file in E.164, as it would be from the card's own form.
        $this->assertSame('+992901112233', $employee->details->phone);
        $this->assertSame('Мама', $employee->details->sos_contact);
        // The language was named, so it is on the directory now as well.
        $this->assertSame(['Английский'], $employee->languages->pluck('name')->all());
        $this->assertNotNull(Language::firstWhere('name', 'Английский'));

        // Паспорт и семья.
        $this->assertSame('1234567', $employee->details->passport_number);
        $this->assertSame('ОВД', $employee->details->passport_issued_by);
        $this->assertSame('married', $employee->details->marital_status);
        $this->assertSame('Азимов Рустам', $employee->details->spouse_name);
        $this->assertTrue($employee->details->has_children);
        $this->assertSame(['Азимова Сабина'], $employee->children->pluck('full_name')->all());

        // Образование и трудовая деятельность.
        $this->assertSame(['ТНУ'], $employee->educations->pluck('institution')->all());
        $this->assertSame(['ООО «Ориён»'], $employee->workExperiences->pluck('organization')->all());

        // Оборудование: the workplace is put together in the same request.
        $this->assertSame($employee->id, $laptop->refresh()->holder_user_id);
        $this->assertSame('issued', $laptop->status);
        $this->assertSame('2026-03-02', $laptop->issued_at->toDateString());

        // One colleague, one letter, one password.
        Notification::assertSentToTimes($employee, AccountCreated::class, 1);
    }

    public function test_a_refusal_in_a_late_section_leaves_no_colleague_behind()
    {
        Notification::fake();
        $this->seed(EquipmentTypeSeeder::class);
        [, $position] = $this->chain();
        $laptop = Equipment::factory()->ofType(EquipmentType::firstWhere('name', 'Ноутбуки'))->create();

        // Everything about this colleague is in order except the last job they
        // held, which they left three years before they joined it. That is the
        // fifth section of six, and the account is the first.
        $this->actingAs($this->sysadmin())->post('/employees', $this->wholeCard([
            'placements' => [['position_id' => $position->id, 'duty_ids' => []]],
            'equipment' => [$laptop->id],
            'experiences' => [
                [
                    'organization' => 'ООО «Ориён»',
                    'position' => 'Аналитик',
                    'country' => 'Таджикистан',
                    'started_month' => 3,
                    'started_year' => 2021,
                    'ended_month' => 8,
                    'ended_year' => 2018,
                ],
            ],
        ]))->assertSessionHasErrors('experiences.0.ended_year');

        // Nothing of the card was written: not the account, not a row of any of
        // its lists, and the laptop is still in the store. This is the whole
        // point of the one transaction — the wizard used to leave a colleague on
        // the books from its first step, with the letter already sent.
        $this->assertNull(User::firstWhere('email', 'nilufar@evolet.tj'));
        $this->assertSame(0, UserEducation::count());
        $this->assertSame(0, UserWorkExperience::count());
        $this->assertSame(0, UserChild::count());
        $this->assertSame('stock', $laptop->refresh()->status);
        Notification::assertNothingSent();
    }

    public function test_every_section_says_what_is_wrong_under_its_own_field()
    {
        // Nine screens of boxes and one request: a refusal has to name the box
        // it belongs to, row and all, or the page has nothing to show it under.
        $this->actingAs($this->sysadmin())->post('/employees', $this->wholeCard([
            'surname' => '',
            'phone' => 'не телефон',
            'passport_issued_at' => now()->addWeek()->toDateString(),
            'languages' => [['name' => 'Английский', 'level' => 'сносно']],
            'children' => [['full_name' => '', 'birth_date' => '2015-05-05']],
            'educations' => [
                ['institution' => 'ТНУ', 'faculty' => 'Экономический', 'specialty' => 'Финансы', 'started_year' => 2010],
                // A diploma cannot predate enrolment, and the faculty is missing.
                ['institution' => 'РТСУ', 'specialty' => 'Право', 'started_year' => 2015, 'graduated_year' => 2012],
            ],
        ]))
            ->assertSessionHasErrors([
                'surname',
                'phone',
                'passport_issued_at',
                'languages.0.level',
                'children.0.full_name',
                'educations.1.faculty',
                'educations.1.graduated_year',
            ])
            // And says nothing about what was right, the good row included.
            ->assertSessionDoesntHaveErrors(['name', 'sos_phone', 'educations.0.institution', 'experiences.0.ended_year']);

        $this->assertSame(1, User::count());
    }

    public function test_a_placement_without_a_position_is_refused()
    {
        Notification::fake();
        $this->chain();

        // The job is the placement: there is nothing to file without one, and a
        // department cannot be named on its own any more.
        $this->actingAs($this->sysadmin())->post('/employees', $this->payload([
            'placements' => [['position_id' => null, 'duty_ids' => []]],
        ]))->assertSessionHasErrors('placements.0.position_id');

        $this->assertNull(User::firstWhere('email', 'nilufar@evolet.tj'));
    }

    public function test_holding_a_position_files_the_department_behind_it()
    {
        Notification::fake();
        [$department, $position] = $this->chain();

        $this->actingAs($this->sysadmin())->post('/employees', $this->payload([
            'placements' => [['position_id' => $position->id, 'duty_ids' => []]],
        ]))->assertSessionHasNoErrors();

        // Nobody wrote the department down: it came with the job.
        $employee = User::firstWhere('email', 'nilufar@evolet.tj');
        $this->assertSame([$position->id], $employee->positions->pluck('id')->all());
        $this->assertSame([$department->id], $employee->departments->pluck('id')->all());
    }

    public function test_several_placements_are_saved_and_read_back_in_order()
    {
        Notification::fake();
        [$first, $analyst, $contracts] = $this->chain('Отдел разработки');
        [$second, $designer, $month] = $this->chain('Отдел дизайна');

        $this->actingAs($this->sysadmin())->post('/employees', $this->payload([
            'placements' => [
                ['position_id' => $analyst->id, 'duty_ids' => [$contracts->id]],
                ['position_id' => $designer->id, 'duty_ids' => [$month->id]],
            ],
        ]))->assertSessionHasNoErrors();

        $employee = User::firstWhere('email', 'nilufar@evolet.tj');

        // The card's dialog opens on what was saved, in the order the picker
        // names the jobs: both are «Аналитик», so the department after the name
        // settles it — «Отдел дизайна» before «Отдел разработки» — and not the
        // order the form happened to send them in.
        $this->assertSame([
            ['position_id' => $designer->id, 'duty_ids' => [$month->id]],
            ['position_id' => $analyst->id, 'duty_ids' => [$contracts->id]],
        ], Placements::of($employee));

        // And both departments came with the jobs.
        $this->assertEqualsCanonicalizing([$first->id, $second->id], $employee->departments->pluck('id')->all());
    }

    public function test_a_duty_of_another_position_is_refused()
    {
        [$department, $position] = $this->chain('Отдел разработки');
        [, , $elsewhere] = $this->chain('Отдел дизайна');
        $admin = $this->sysadmin();

        $this->actingAs($admin)->post('/employees', $this->payload([
            'placements' => [['position_id' => $position->id, 'duty_ids' => [$elsewhere->id]]],
        ]))->assertSessionHasErrors('placements.0.duty_ids.0');

        // And duties with no position to pick them from are no duties at all.
        $this->actingAs($admin)->post('/employees', $this->payload([
            'placements' => [['position_id' => null, 'duty_ids' => [$elsewhere->id]]],
        ]))->assertSessionHasErrors('placements.0.duty_ids');

        $this->assertNull(User::firstWhere('email', 'nilufar@evolet.tj'));
    }

    public function test_the_same_position_twice_is_refused()
    {
        [, $position] = $this->chain();

        // Written out twice it would be filed once, which is not what the second
        // row says.
        $this->actingAs($this->sysadmin())->post('/employees', $this->payload([
            'placements' => [
                ['position_id' => $position->id, 'duty_ids' => []],
                ['position_id' => $position->id, 'duty_ids' => []],
            ],
        ]))->assertSessionHasErrors('placements.1.position_id');

        $this->assertNull(User::firstWhere('email', 'nilufar@evolet.tj'));
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

            // Long enough for the password page to accept it if they ever type
            // it back, and it is in the letter.
            $this->assertGreaterThanOrEqual(12, strlen($password));
            $this->assertLessThanOrEqual(16, strlen($password));
            $this->assertTrue(Hash::check($password, $employee->password));

            return str_contains($body, $password) && str_contains($body, $employee->email);
        });
    }

    /**
     * A colleague goes on the books without saying whether they are a man or a
     * woman.
     *
     * The column used to be an enum of those two and nothing else, so the
     * question had to be answered before anybody could be added at all. It is a
     * line of the card like any other now, and the card opens with it empty —
     * which is the half that a rule alone would not have proved: the staff list
     * reads a sex to pick the wording of «замужем», and a page that assumed one
     * would fall over on the first card that has none.
     */
    public function test_a_colleague_is_added_without_a_sex_and_their_card_opens()
    {
        Notification::fake();
        $admin = $this->sysadmin();

        $this->actingAs($admin)->post('/employees', $this->payload(['sex' => null]))
            ->assertSessionHasNoErrors();

        $employee = User::firstWhere('email', 'nilufar@evolet.tj');
        $this->assertNotNull($employee);
        $this->assertNull($employee->sex);

        $this->actingAs($admin)->get("/employees/{$employee->id}")->assertOk();
        $this->actingAs($admin)->get('/employees')->assertOk();

        // And a third thing is still not one of the answers.
        $this->actingAs($admin)->post('/employees', $this->payload(['email' => 'kto@evolet.co.uk', 'sex' => 'other']))
            ->assertSessionHasErrors('sex');
    }

    public function test_the_form_needs_a_name_and_a_free_address()
    {
        $taken = User::factory()->create(['email' => 'taken@evolet.tj']);
        $admin = $this->sysadmin();

        $this->actingAs($admin)->post('/employees', [])
            ->assertSessionHasErrors(['surname', 'name', 'email']);

        $this->actingAs($admin)->post('/employees', $this->payload(['email' => $taken->email]))
            ->assertSessionHasErrors('email');

        // A hire date in the future would read as negative service.
        $this->actingAs($admin)->post('/employees', $this->payload(['hired_at' => now()->addWeek()->toDateString()]))
            ->assertSessionHasErrors('hired_at');

        $this->assertSame(2, User::count());
    }

    #[DataProvider('addressesFromAnotherAlphabet')]
    public function test_an_address_with_a_letter_from_another_alphabet_is_refused(string $typed)
    {
        $admin = $this->sysadmin();

        // The password the account starts with is mailed to this address, so a
        // letter that only looks Latin locks the colleague out on day one.
        $this->actingAs($admin)->post('/employees', $this->payload(['email' => $typed]))
            ->assertSessionHasErrors('email');

        $this->assertNull(User::firstWhere('email', $typed));

        // The address that really is Latin reads the same and goes through.
        $this->actingAs($admin)->post('/employees', $this->payload(['email' => 'nilufar@evolet.tj']))
            ->assertSessionHasNoErrors();

        $this->assertNotNull(User::firstWhere('email', 'nilufar@evolet.tj'));
    }

    /**
     * Addresses nobody can tell from a Latin one by looking at them: the «а»
     * and the «е» below are Cyrillic.
     *
     * @return array<string, array{string}>
     */
    public static function addressesFromAnotherAlphabet(): array
    {
        return [
            'a stray letter in the local part' => ['nilufаr@evolet.tj'],
            'a stray letter in the domain' => ['nilufar@evolеt.tj'],
            'the whole address' => ['нилуфар@почта.рф'],
        ];
    }

    public function test_create_and_add_another_stays_on_the_page()
    {
        Notification::fake();

        // «Создать и добавить ещё» asks to carry on: the page stays where it is
        // and is handed whom it has just filed, to say so over the blank form.
        $this->actingAs($this->sysadmin())
            ->from('/employees/create')
            ->post('/employees', $this->payload(['continue' => true]))
            ->assertRedirect('/employees/create')
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
        $this->actingAs($admin)->post('/employees', $this->payload([
            'placements' => [['position_id' => 9999, 'duty_ids' => []]],
        ]))->assertSessionHasErrors('placements.0.position_id');
        $this->actingAs($admin)->post('/employees', $this->payload([
            'placements' => [['position_id' => null, 'duty_ids' => []]],
        ]))->assertSessionHasErrors('placements.0.position_id');

        [, $position] = $this->chain();
        $this->actingAs($admin)->post('/employees', $this->payload([
            'placements' => [['position_id' => 9999, 'duty_ids' => []]],
        ]))->assertSessionHasErrors('placements.0.position_id');
        $this->actingAs($admin)->post('/employees', $this->payload([
            'placements' => [['position_id' => $position->id, 'duty_ids' => [9999]]],
        ]))->assertSessionHasErrors('placements.0.duty_ids.0');

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
        Notification::fake();

        // Not one line of anybody's card is theirs to change, and still the whole
        // card of the person they are adding is: the right to add somebody is the
        // right to everything the page asks about them, and the page asks in a
        // single request, so that right is the whole of the answer.
        $creator = $this->withRights('employees.create');
        [$department, $position, $duty] = $this->chain();

        // The page offers everything it asks for, the one position nobody is
        // handed excepted. The duties are narrowed by the job in the browser, so
        // the whole of each list travels with it.
        $this->actingAs($creator)->get('/employees/create')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('options.roles', fn ($roles) => collect($roles)->pluck('name')->contains('specialist') && ! collect($roles)->pluck('name')->contains('sysadmin'))
            ->has('options.positions', Position::count())
            ->has('options.duties', PositionDuty::count())
            ->where('options.positions', fn ($positions) => collect($positions)->firstWhere('id', $position->id) === [
                'id' => $position->id,
                'name' => 'Аналитик',
                'display_name' => 'Аналитик (Отдел разработки)',
                // The department behind the job, short form and full, so a list can
                // name it under the title rather than only in brackets.
                'unit' => ['name' => 'Отдел разработки', 'full_name' => 'Отдел разработки'],
                'department_id' => $department->id,
            ])
        );

        $this->actingAs($creator)->post('/employees', $this->wholeCard([
            'roles' => ['specialist'],
            'placements' => [['position_id' => $position->id, 'duty_ids' => [$duty->id]]],
        ]))->assertSessionHasNoErrors();

        $employee = User::firstWhere('email', 'nilufar@evolet.tj');
        $this->assertTrue($employee->hasRole('specialist'));
        $this->assertSame([$position->id], $employee->positions->pluck('id')->all());
        $this->assertSame([$duty->id], $employee->duties->pluck('id')->all());
        // The department was never written here: it comes with the job.
        $this->assertSame([$department->id], $employee->departments->pluck('id')->all());
        $this->assertSame('+992901112233', $employee->details->phone);
        $this->assertCount(1, $employee->languages);
        $this->assertSame('1234567', $employee->details->passport_number);
        $this->assertSame('married', $employee->details->marital_status);
        $this->assertCount(1, $employee->children);
        $this->assertCount(1, $employee->educations);
        $this->assertCount(1, $employee->workExperiences);

        // And that is where it ends. The colleague is on the books now, and their
        // card is edited under the ordinary rights to its lines — which this
        // creator holds none of, their own doing a moment ago notwithstanding.
        $this->actingAs($creator)->put("/employees/{$employee->id}/passport", ['passport_number' => '7654321'])->assertForbidden();
        $this->assertSame('1234567', $employee->details()->first()->passport_number);
    }

    public function test_a_creator_may_not_touch_anybody_already_on_the_books()
    {
        Notification::fake();
        $creator = $this->withRights('employees.create');
        $existing = User::factory()->create();

        $this->actingAs($creator)->post('/employees', $this->payload())->assertSessionHasNoErrors();

        // Somebody already on the books is edited under the rights to their lines,
        // and this creator has none — adding one colleague opens nobody else's card.
        $this->actingAs($creator)->put("/employees/{$existing->id}/passport", ['passport_number' => '7654321'])->assertForbidden();
        $this->actingAs($creator)->put("/employees/{$existing->id}/personal", $this->payload(['email' => $existing->email]))->assertForbidden();
        $this->actingAs($creator)->post("/employees/{$existing->id}/educations/many", ['records' => []])->assertForbidden();
    }

    public function test_handing_hardware_out_still_takes_the_right_to_issue_it()
    {
        Notification::fake();
        $this->seed(EquipmentTypeSeeder::class);
        $laptop = Equipment::factory()->ofType(EquipmentType::firstWhere('name', 'Ноутбуки'))->create();

        // Handing a unit over is not a line of the card but an operation on the
        // fleet, so it keeps the fleet's own right. Without it the page leaves the
        // section out, and a card that carries units anyway is refused whole —
        // the colleague is not created either.
        $creator = $this->withRights('employees.create');
        $this->actingAs($creator)->get('/employees/create')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('canIssue', false)
            ->where('options.stock', [])
        );
        $this->actingAs($creator)->post('/employees', $this->payload(['equipment' => [$laptop->id]]))->assertForbidden();
        $this->assertNull(User::firstWhere('email', 'nilufar@evolet.tj'));
        $this->assertSame('stock', $laptop->refresh()->status);

        // The rest of the card is still theirs to file: the right they are missing
        // is about the fleet and nothing else.
        $this->actingAs($creator)->post('/employees', $this->payload())->assertSessionHasNoErrors();
        $this->assertNotNull(User::firstWhere('email', 'nilufar@evolet.tj'));

        // With it, the workplace is put together in the same request.
        $keeper = $this->withRights('employees.create', 'equipment.issue');
        $this->actingAs($keeper)->get('/employees/create')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('canIssue', true)
            ->has('options.stock', 1)
        );
        $this->actingAs($keeper)->post('/employees', $this->payload([
            'email' => 'second@evolet.tj',
            'equipment' => [$laptop->id],
        ]))->assertSessionHasNoErrors();

        $other = User::firstWhere('email', 'second@evolet.tj');
        $this->assertSame($other->id, $laptop->refresh()->holder_user_id);
    }
}
