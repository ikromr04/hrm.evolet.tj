<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserChild;
use App\Models\UserDetail;
use App\Models\UserRelative;
use App\Support\EmployeeFields;
use Database\Seeders\PositionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The "Семья" card of the profile: marital status, the spouse and the children.
 */
class EmployeeFamilyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RoleSeeder::class, PositionSeeder::class]);
    }

    private function payload(array $overrides = []): array
    {
        return [
            'marital_status' => 'married',
            // A month and a year: the day is not asked for of anybody in the
            // block, and «1992-03» is the whole of what the form sends.
            'spouse_birth_date' => '1992-03',
            'spouse_name' => 'Азимова Нигина',
            'spouse_occupation' => 'Учитель, СОШ № 12',
            'has_children' => null,
            'children' => [],
            // Both lists are replaced wholesale, so both travel even when empty
            // — see UpdateFamilyRequest, which asks for them.
            'relatives' => [],
            ...$overrides,
        ];
    }

    /**
     * The rest of the family as the form sends it: who they are to this person,
     * what they are called, and the two lines that may be left unanswered.
     *
     * The month and the year, as the spouse and the children now send them:
     * «Состав семьи» is one list of people, asked the same three things.
     *
     * @return list<array<string, mixed>>
     */
    private function relatives(): array
    {
        return [
            ['relation' => 'Мать', 'full_name' => 'Рахимова Зарина Курбоновна', 'birth_date' => '1971-03', 'occupation' => 'Пенсионерка'],
            // Nobody remembered the month, and a student's line says enough.
            ['relation' => 'Брат', 'full_name' => 'Рахимов Далер Саидович', 'birth_date' => '', 'occupation' => ''],
        ];
    }

    /**
     * An empty list alone cannot say whether the employee has no children or
     * whether nobody has filled the card in yet, so a flag keeps them apart.
     */
    public function test_an_untouched_card_is_not_the_same_as_having_no_children()
    {
        $admin = User::factory()->create()->assignRole('sysadmin');
        $employee = User::factory()->has(UserDetail::factory(), 'details')->create();
        $employee->details()->update(['has_children' => null]);

        // Saved without an answer, the question stays open.
        $this->actingAs($admin)
            ->put("/employees/{$employee->id}/family", $this->payload())
            ->assertSessionHasNoErrors();
        $this->assertNull($employee->refresh()->details->has_children);

        // Ticking "детей нет" answers it.
        $this->actingAs($admin)
            ->put("/employees/{$employee->id}/family", $this->payload(['has_children' => false]))
            ->assertSessionHasNoErrors();
        $this->assertFalse($employee->refresh()->details->has_children);
    }

    public function test_rows_on_file_always_mean_there_are_children()
    {
        $admin = User::factory()->create()->assignRole('sysadmin');
        $employee = User::factory()->has(UserDetail::factory(), 'details')->create();

        // Even told otherwise, the rows win: the two cannot drift apart.
        $this->actingAs($admin)
            ->put("/employees/{$employee->id}/family", $this->payload([
                'has_children' => false,
                'children' => [['full_name' => 'Азимов Далер', 'birth_date' => '2015-09']],
            ]))
            ->assertSessionHasNoErrors();

        $this->assertTrue($employee->refresh()->details->has_children);
    }

    public function test_an_admin_saves_the_spouse_and_the_children()
    {
        $admin = User::factory()->create()->assignRole('sysadmin');
        $employee = User::factory()->has(UserDetail::factory(), 'details')->create();

        $this->actingAs($admin)
            ->put("/employees/{$employee->id}/family", $this->payload([
                'children' => [
                    ['full_name' => 'Азимов Далер', 'birth_date' => '2015-09', 'occupation' => '3 класс, СОШ № 12'],
                    // A child nobody wrote a month or a school down for.
                    ['full_name' => 'Азимова Мадина', 'birth_date' => '', 'occupation' => ''],
                ],
            ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $employee->refresh();
        $this->assertSame('married', $employee->details->marital_status);
        $this->assertSame('Азимова Нигина', $employee->details->spouse_name);
        // «1992-03» went in and the first of March came out: the column is a
        // date because there is none for a month, and the first of it is the
        // day the card keeps rather than one anybody was told.
        $this->assertSame('1992-03-01', $employee->details->spouse_birth_date->toDateString());
        $this->assertSame('Учитель, СОШ № 12', $employee->details->spouse_occupation);
        // The relation orders by birth date, and a missing one sorts first.
        $this->assertSame(
            [['Азимова Мадина', null, null], ['Азимов Далер', '2015-09-01', '3 класс, СОШ № 12']],
            $employee->children->map(fn (UserChild $c) => [$c->full_name, $c->birth_date?->toDateString(), $c->occupation])->all(),
        );
    }

    public function test_the_children_are_replaced_wholesale()
    {
        $admin = User::factory()->create()->assignRole('sysadmin');
        $employee = User::factory()->has(UserDetail::factory(), 'details')->create();
        UserChild::factory(3)->for($employee)->create();

        $this->actingAs($admin)
            ->put("/employees/{$employee->id}/family", $this->payload([
                'children' => [['full_name' => 'Азимов Далер', 'birth_date' => '2015-09']],
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame(['Азимов Далер'], $employee->refresh()->children->pluck('full_name')->all());
    }

    public function test_an_unmarried_employee_may_leave_the_card_empty()
    {
        $admin = User::factory()->create()->assignRole('sysadmin');
        $employee = User::factory()->has(UserDetail::factory(), 'details')->create();

        $this->actingAs($admin)
            ->put("/employees/{$employee->id}/family", [
                'marital_status' => '',
                'spouse_name' => '',
                'spouse_birth_date' => '',
                'spouse_occupation' => '',
                'children' => [],
                'relatives' => [],
            ])
            ->assertSessionHasNoErrors();

        $employee->refresh();
        $this->assertNull($employee->details->marital_status);
        $this->assertNull($employee->details->spouse_name);
        $this->assertNull($employee->details->spouse_occupation);
        $this->assertCount(0, $employee->children);
        $this->assertCount(0, $employee->relatives);
    }

    /**
     * «Женат» with nobody named is half an answer.
     *
     * The spouse is asked for by the status and by nothing else: everybody
     * else leaves the two lines empty, and a card that insisted on a name from
     * them would be asking the unmarried to invent one.
     */
    public function test_a_married_card_names_the_spouse()
    {
        $admin = User::factory()->create()->assignRole('sysadmin');
        $employee = User::factory()->has(UserDetail::factory(), 'details')->create();

        // Whatever the card said before, since a factory may well have married
        // this one off and named the spouse: what matters is that a refused save
        // leaves the line exactly as it found it.
        $before = $employee->details->spouse_name;

        $this->actingAs($admin)
            ->put("/employees/{$employee->id}/family", $this->payload([
                'spouse_name' => '',
                'spouse_birth_date' => '',
            ]))
            ->assertSessionHasErrors(['spouse_name', 'spouse_birth_date']);

        $this->assertSame($before, $employee->refresh()->details->spouse_name);
    }

    /**
     * And «Есть» is an answer that needs a row behind it.
     *
     * The whole point of the three-way answer is to tell «детей нет» from
     * «ещё не спрашивали»; a card that says there are children and names none
     * is a third thing nobody can read, and somebody would have to go back and
     * ask about it.
     */
    public function test_children_said_to_exist_are_named()
    {
        $admin = User::factory()->create()->assignRole('sysadmin');
        $employee = User::factory()->has(UserDetail::factory(), 'details')->create();

        $this->actingAs($admin)
            ->put("/employees/{$employee->id}/family", $this->payload([
                'has_children' => true,
                'children' => [],
            ]))
            ->assertSessionHasErrors('children');

        $this->assertCount(0, $employee->refresh()->children);
    }

    /**
     * The rest of the family is a list like the children: saved whole, and
     * whatever was on file before gives way to it.
     */
    public function test_an_admin_saves_the_rest_of_the_family()
    {
        $admin = User::factory()->create()->assignRole('sysadmin');
        $employee = User::factory()->has(UserDetail::factory(), 'details')->create();
        UserRelative::factory(3)->for($employee)->create();

        $this->actingAs($admin)
            ->put("/employees/{$employee->id}/family", $this->payload(['relatives' => $this->relatives()]))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertSame(
            [
                ['Мать', 'Рахимова Зарина Курбоновна', '1971-03', 'Пенсионерка'],
                ['Брат', 'Рахимов Далер Саидович', null, null],
            ],
            $employee->refresh()->relatives
                ->map(fn (UserRelative $r) => [$r->relation, $r->full_name, $r->birth_date?->format('Y-m'), $r->occupation])
                ->all(),
        );
    }

    /** And an empty list takes the family off the card, as it takes the children off. */
    public function test_an_empty_list_takes_the_rest_of_the_family_off_the_card()
    {
        $admin = User::factory()->create()->assignRole('sysadmin');
        $employee = User::factory()->has(UserDetail::factory(), 'details')->create();
        UserRelative::factory(2)->for($employee)->create();

        $this->actingAs($admin)
            ->put("/employees/{$employee->id}/family", $this->payload())
            ->assertSessionHasNoErrors();

        $this->assertCount(0, $employee->refresh()->relatives);
    }

    /**
     * What they are to this person and what they are called make the row a row.
     *
     * A row with neither answers nothing, and the card prints it as a blank line
     * between two it was meant to explain.
     */
    public function test_a_relative_without_the_relation_or_a_name_is_refused()
    {
        $admin = User::factory()->create()->assignRole('sysadmin');
        $employee = User::factory()->has(UserDetail::factory(), 'details')->create();

        $this->actingAs($admin)
            ->put("/employees/{$employee->id}/family", $this->payload([
                'relatives' => [['relation' => '', 'full_name' => '', 'birth_date' => '1971-03']],
            ]))
            ->assertSessionHasErrors(['relatives.0.relation', 'relatives.0.full_name']);

        $this->assertCount(0, $employee->refresh()->relatives);
    }

    /**
     * A month and a year, written «1971-03», and nothing else.
     *
     * The day is not asked for of anybody in the block, so a full date is a
     * form built against a page that no longer exists — and «1971-03-05» would
     * leave the card holding a day nobody will ever be asked to confirm, which
     * is the whole thing the month was there to avoid. A bare year is half the
     * answer, and nobody is born in a month still to come.
     *
     * Asked of all three at once, because all three are asked the same way.
     */
    public function test_a_date_of_birth_that_is_not_a_month_is_refused()
    {
        $admin = User::factory()->create()->assignRole('sysadmin');
        $employee = User::factory()->has(UserDetail::factory(), 'details')->create();

        foreach (['1971-03-05', '03.1971', '1971', now()->addMonth()->format('Y-m')] as $said) {
            $this->actingAs($admin)
                ->put("/employees/{$employee->id}/family", $this->payload([
                    'spouse_birth_date' => $said,
                    'children' => [['full_name' => 'Азимов Далер', 'birth_date' => $said]],
                    'relatives' => [['relation' => 'Мать', 'full_name' => 'Рахимова Зарина', 'birth_date' => $said]],
                ]))
                ->assertSessionHasErrors([
                    'spouse_birth_date',
                    'children.0.birth_date',
                    'relatives.0.birth_date',
                ]);
        }

        $employee->refresh();
        $this->assertCount(0, $employee->children);
        $this->assertCount(0, $employee->relatives);
    }

    /**
     * And this month is as late as anybody can have been born, which is a month
     * the card does accept: a ceiling of «today» would have turned the first of
     * this month away for the rest of it.
     */
    public function test_the_month_now_running_is_accepted_and_kept_as_its_first_day()
    {
        $admin = User::factory()->create()->assignRole('sysadmin');
        $employee = User::factory()->has(UserDetail::factory(), 'details')->create();
        $month = now()->format('Y-m');
        $first = now()->startOfMonth()->toDateString();

        $this->actingAs($admin)
            ->put("/employees/{$employee->id}/family", $this->payload([
                'spouse_birth_date' => $month,
                'children' => [['full_name' => 'Азимов Далер', 'birth_date' => $month]],
                'relatives' => [['relation' => 'Мать', 'full_name' => 'Рахимова Зарина', 'birth_date' => $month]],
            ]))
            ->assertSessionHasNoErrors();

        $employee->refresh();
        $this->assertSame($first, $employee->details->spouse_birth_date->toDateString());
        $this->assertSame($first, $employee->children->first()->birth_date->toDateString());
        $this->assertSame($first, $employee->relatives->first()->birth_date->toDateString());
    }

    /**
     * The cards already on the books keep what they said.
     *
     * The block changed shape under them: a relative had a bare year where the
     * date now is, and the spouse and the children had full dates where only a
     * month is now asked for. So the card is wound back one step, filled in the
     * way it used to be, and brought forward again — which is the only way to
     * read a migration that carries data, and the one thing a fresh database
     * can never show.
     *
     * A year becomes the first of January of it, because that is the only
     * honest reading of a year when a month is wanted. The two full dates keep
     * their month and lose their day, which is the one thing this cannot undo
     * and is meant not to: nobody ever confirmed that day and nobody will ever
     * be asked to again.
     */
    public function test_the_card_keeps_what_it_said_through_the_change_of_shape()
    {
        // Filed before the card is wound back, because a row written through
        // the models would reach for columns the old shape has not got.
        $employee = User::factory()->has(UserDetail::factory(), 'details')->create();

        Artisan::call('migrate:rollback', ['--step' => 1]);

        DB::table('user_relatives')->insert([
            'user_id' => $employee->id,
            'relation' => 'Мать',
            'full_name' => 'Рахимова Зарина Курбоновна',
            'birth_year' => 1971,
            'occupation' => 'Пенсионерка',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('user_children')->insert([
            'user_id' => $employee->id,
            'full_name' => 'Азимов Далер',
            'birth_date' => '2015-09-14',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('user_details')
            ->where('user_id', $employee->id)
            ->update(['spouse_birth_date' => '1992-03-08']);

        Artisan::call('migrate');

        $month = fn (string $table, string $column) => Carbon::parse(
            DB::table($table)->where('user_id', $employee->id)->value($column),
        )->toDateString();

        $this->assertSame('1971-01-01', $month('user_relatives', 'birth_date'));
        $this->assertSame('1992-03-01', $month('user_details', 'spouse_birth_date'));
        $this->assertSame('2015-09-01', $month('user_children', 'birth_date'));

        // And the rows are still the rows they were: a date was carried across,
        // not filed again in place of what was there.
        $this->assertSame('Рахимова Зарина Курбоновна', DB::table('user_relatives')->value('full_name'));
        $this->assertSame('Пенсионерка', DB::table('user_relatives')->value('occupation'));
    }

    /**
     * Where each of them works or studies: one line, asked of all three and
     * read back off the card as the form sent it.
     *
     * «Состав семьи» is one list of people, so the line that used to be only
     * the relatives' is now the spouse's and the children's too — and a child
     * answers it with a class at school as readily as a brother answers it with
     * a place of work.
     */
    public function test_the_whole_family_says_where_it_works_or_studies()
    {
        $admin = User::factory()->create()->assignRole('sysadmin');
        $employee = User::factory()->has(UserDetail::factory(), 'details')->create();

        $this->actingAs($admin)
            ->put("/employees/{$employee->id}/family", $this->payload([
                'spouse_occupation' => 'Врач, городская поликлиника № 3',
                'children' => [['full_name' => 'Азимов Далер', 'birth_date' => '2015-09', 'occupation' => '3 класс, СОШ № 12']],
                'relatives' => [['relation' => 'Мать', 'full_name' => 'Рахимова Зарина', 'birth_date' => '1971-03', 'occupation' => 'Пенсионерка']],
            ]))
            ->assertSessionHasNoErrors();

        $employee->refresh();
        $this->assertSame('Врач, городская поликлиника № 3', $employee->details->spouse_occupation);
        $this->assertSame('3 класс, СОШ № 12', $employee->children->first()->occupation);
        $this->assertSame('Пенсионерка', $employee->relatives->first()->occupation);

        // And the card gives back the month it was sent rather than the day it
        // had to store it under.
        $this->actingAs($admin)
            ->get("/employees/{$employee->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('employee.private.spouse_birth_date', '1992-03')
                ->where('employee.private.spouse_occupation', 'Врач, городская поликлиника № 3')
                ->where('employee.private.children.0.birth_date', '2015-09')
                ->where('employee.private.children.0.occupation', '3 класс, СОШ № 12')
                ->where('employee.private.relatives.0.birth_date', '1971-03')
                ->where('employee.private.relatives.0.occupation', 'Пенсионерка'));
    }

    /**
     * What any of them does is one line of the sheet, not a page of one — and
     * the same line, so the three are capped alike.
     */
    public function test_an_occupation_longer_than_the_column_is_refused()
    {
        $admin = User::factory()->create()->assignRole('sysadmin');
        $employee = User::factory()->has(UserDetail::factory(), 'details')->create();
        $tooLong = str_repeat('я', 201);

        $this->actingAs($admin)
            ->put("/employees/{$employee->id}/family", $this->payload([
                'spouse_occupation' => $tooLong,
                'children' => [['full_name' => 'Азимов Далер', 'occupation' => $tooLong]],
                'relatives' => [[
                    'relation' => 'Мать',
                    'full_name' => 'Рахимова Зарина',
                    'occupation' => $tooLong,
                ]],
            ]))
            ->assertSessionHasErrors([
                'spouse_occupation',
                'children.0.occupation',
                'relatives.0.occupation',
            ]);

        $employee->refresh();
        $this->assertCount(0, $employee->children);
        $this->assertCount(0, $employee->relatives);
    }

    public function test_invalid_data_is_rejected()
    {
        $admin = User::factory()->create()->assignRole('sysadmin');
        $employee = User::factory()->has(UserDetail::factory(), 'details')->create();

        $this->actingAs($admin)
            ->put("/employees/{$employee->id}/family", $this->payload([
                'marital_status' => 'divorced',
                // Nobody is born next month.
                'spouse_birth_date' => now()->addMonth()->format('Y-m'),
                'children' => [['full_name' => '', 'birth_date' => now()->addMonth()->format('Y-m')]],
            ]))
            ->assertSessionHasErrors([
                'marital_status',
                'spouse_birth_date',
                'children.0.full_name',
                'children.0.birth_date',
            ]);
    }

    public function test_the_spouse_reaches_the_profile_page()
    {
        $employee = $this->mayLookAround(User::factory()->has(UserDetail::factory(), 'details')->create());
        $employee->details()->update(['marital_status' => 'married', 'spouse_name' => 'Азимова Нигина']);

        $this->actingAs($employee)
            ->get('/profile')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('employee.private.spouse_name', 'Азимова Нигина'));

        // A colleague gets no private block at all, spouse included.
        $this->actingAs($this->colleague())
            ->get("/employees/{$employee->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('employee.private', null));
    }

    public function test_an_employee_cannot_edit_anyones_family()
    {
        $employee = User::factory()->has(UserDetail::factory(), 'details')->create();

        // Not even their own: the card is managed by HR.
        $this->actingAs($employee)
            ->put("/employees/{$employee->id}/family", $this->payload())
            ->assertForbidden();

        $this->actingAs($this->colleague())
            ->put("/employees/{$employee->id}/family", $this->payload())
            ->assertForbidden();
    }

    /**
     * Somebody whose position holds exactly these lines of the "Семья" card, to
     * read and to change, plus the staff list. Nobody retypes what they cannot
     * see, so each line comes with its right to read it.
     */
    private function withFamilyRights(string $scope, string ...$fields): User
    {
        $rights = collect($fields)->flatMap(fn (string $field) => [
            EmployeeFields::permission($field, $scope),
            EmployeeFields::editPermission($field, $scope),
        ])->all();

        $role = Role::create(['name' => 'f-'.Role::count(), 'title' => 'Роль '.Role::count(), 'guard_name' => 'web']);
        $role->syncPermissions(['employees.view', ...$rights]);

        return User::factory()->has(UserDetail::factory(), 'details')->create()->assignRole($role);
    }

    /** A card with a whole family already on file. */
    private function withFamily(User $employee): User
    {
        $employee->details()->update([
            'marital_status' => 'married',
            'spouse_name' => 'Азимова Нигина',
            // The first of the month, as every date of the block is kept.
            'spouse_birth_date' => '1992-03-01',
            'spouse_occupation' => 'Учитель, СОШ № 12',
            'has_children' => true,
        ]);
        UserChild::factory()->for($employee)->create(['full_name' => 'Азимов Далер', 'birth_date' => '2015-09-01']);
        UserRelative::factory()->for($employee)->create(['relation' => 'Мать', 'full_name' => 'Азимова Зарина']);

        return $employee->refresh();
    }

    private function assertSpouseKept(User $employee): void
    {
        $this->assertSame('Азимова Нигина', $employee->details->spouse_name);
        $this->assertSame('1992-03', $employee->details->spouse_birth_date->format('Y-m'));
        $this->assertSame('Учитель, СОШ № 12', $employee->details->spouse_occupation);
    }

    private function assertChildrenKept(User $employee): void
    {
        $this->assertTrue($employee->details->has_children);
        $this->assertSame(['Азимов Далер'], $employee->children->pluck('full_name')->all());
    }

    private function assertRelativesKept(User $employee): void
    {
        $this->assertSame(['Азимова Зарина'], $employee->relatives->pluck('full_name')->all());
    }

    /**
     * The block opens for whoever may change any line of it, so a form that only
     * offered the marital status must not wipe the spouse and the children with
     * blanks it was never meant to send.
     */
    public function test_whoever_may_change_only_the_marital_status_leaves_the_rest_alone()
    {
        $clerk = $this->withFamilyRights(EmployeeFields::OTHERS, 'marital_status');
        $employee = $this->withFamily(User::factory()->has(UserDetail::factory(), 'details')->create());

        $this->actingAs($clerk)
            ->put("/employees/{$employee->id}/family", [
                'marital_status' => 'single',
                'spouse_name' => '',
                'spouse_birth_date' => '',
                'spouse_occupation' => '',
                'has_children' => false,
                'children' => [],
                'relatives' => [],
            ])
            ->assertSessionHasNoErrors();

        $employee->refresh();
        $this->assertSame('single', $employee->details->marital_status);
        $this->assertSpouseKept($employee);
        $this->assertChildrenKept($employee);
        $this->assertRelativesKept($employee);

        // Nor is a line they may not change checked: there is nothing for them to fix in it.
        $this->actingAs($clerk)
            ->put("/employees/{$employee->id}/family", ['marital_status' => 'married', 'spouse_birth_date' => 'не дата'])
            ->assertSessionHasNoErrors();
        $this->assertSame('married', $employee->refresh()->details->marital_status);
    }

    public function test_whoever_may_change_only_the_children_replaces_just_them()
    {
        $clerk = $this->withFamilyRights(EmployeeFields::OTHERS, 'children');
        $employee = $this->withFamily(User::factory()->has(UserDetail::factory(), 'details')->create());

        $this->actingAs($clerk)
            ->put("/employees/{$employee->id}/family", [
                'marital_status' => '',
                'spouse_name' => '',
                'children' => [['full_name' => 'Азимова Мадина', 'birth_date' => '2018-05']],
                'relatives' => [],
            ])
            ->assertSessionHasNoErrors();

        $employee->refresh();
        $this->assertSame(['Азимова Мадина'], $employee->children->pluck('full_name')->all());
        $this->assertSame('married', $employee->details->marital_status);
        $this->assertSpouseKept($employee);
        $this->assertRelativesKept($employee);

        // "Детей нет" is part of the children's line, and so theirs to tick.
        $this->actingAs($clerk)
            ->put("/employees/{$employee->id}/family", ['has_children' => false, 'children' => []])
            ->assertSessionHasNoErrors();

        $employee->refresh();
        $this->assertFalse($employee->details->has_children);
        $this->assertCount(0, $employee->children);
        $this->assertSame('married', $employee->details->marital_status);
        $this->assertRelativesKept($employee);
    }

    /**
     * And the rest of the family is a line of its own: whoever may change it
     * replaces that list and nothing else on the card.
     */
    public function test_whoever_may_change_only_the_rest_of_the_family_replaces_just_them()
    {
        $clerk = $this->withFamilyRights(EmployeeFields::OTHERS, 'relatives');
        $employee = $this->withFamily(User::factory()->has(UserDetail::factory(), 'details')->create());

        $this->actingAs($clerk)
            ->put("/employees/{$employee->id}/family", [
                'marital_status' => '',
                'spouse_name' => '',
                'has_children' => false,
                'children' => [],
                'relatives' => $this->relatives(),
            ])
            ->assertSessionHasNoErrors();

        $employee->refresh();
        $this->assertSame(
            ['Рахимова Зарина Курбоновна', 'Рахимов Далер Саидович'],
            $employee->relatives->pluck('full_name')->all(),
        );
        $this->assertSame('married', $employee->details->marital_status);
        $this->assertSpouseKept($employee);
        $this->assertChildrenKept($employee);
    }

    public function test_whoever_may_change_every_line_saves_the_whole_card()
    {
        $clerk = $this->withFamilyRights(EmployeeFields::OTHERS, 'marital_status', 'spouse', 'children', 'relatives');
        $employee = $this->withFamily(User::factory()->has(UserDetail::factory(), 'details')->create());

        $this->actingAs($clerk)
            ->put("/employees/{$employee->id}/family", [
                'marital_status' => '',
                'spouse_name' => '',
                'spouse_birth_date' => '',
                'spouse_occupation' => '',
                'has_children' => null,
                'children' => [],
                'relatives' => [],
            ])
            ->assertSessionHasNoErrors();

        $employee->refresh();
        $this->assertNull($employee->details->marital_status);
        $this->assertNull($employee->details->spouse_name);
        $this->assertNull($employee->details->spouse_birth_date);
        $this->assertNull($employee->details->spouse_occupation);
        $this->assertNull($employee->details->has_children);
        $this->assertCount(0, $employee->children);
        $this->assertCount(0, $employee->relatives);
    }

    public function test_whoever_may_change_no_line_of_the_family_is_turned_away()
    {
        // Reading all of it is not changing any of it.
        $reader = $this->withFamilyRights(EmployeeFields::OTHERS);
        $reader->roles->first()->givePermissionTo(array_map(
            fn (string $field) => EmployeeFields::permission($field),
            ['marital_status', 'spouse', 'children', 'relatives'],
        ));
        $employee = $this->withFamily(User::factory()->has(UserDetail::factory(), 'details')->create());

        $this->actingAs($reader)
            ->put("/employees/{$employee->id}/family", $this->payload())
            ->assertForbidden();

        $employee->refresh();
        $this->assertSpouseKept($employee);
        $this->assertChildrenKept($employee);
        $this->assertRelativesKept($employee);
    }

    /**
     * One's own card is read against the profile rights, line by line all the
     * same, and those rights open nobody else's.
     */
    public function test_ones_own_family_is_saved_line_by_line_by_the_profile_rights()
    {
        $employee = $this->withFamily($this->withFamilyRights(EmployeeFields::OWN, 'marital_status'));

        $this->actingAs($employee)
            ->put("/employees/{$employee->id}/family", [
                'marital_status' => 'single',
                'spouse_name' => '',
                'spouse_birth_date' => '',
                'children' => [],
            ])
            ->assertSessionHasNoErrors();

        $employee->refresh();
        $this->assertSame('single', $employee->details->marital_status);
        $this->assertSpouseKept($employee);
        $this->assertChildrenKept($employee);
        $this->assertRelativesKept($employee);

        $parent = $this->withFamily($this->withFamilyRights(EmployeeFields::OWN, 'children'));

        $this->actingAs($parent)
            ->put("/employees/{$parent->id}/family", [
                'marital_status' => '',
                'children' => [['full_name' => 'Азимова Мадина', 'birth_date' => '']],
            ])
            ->assertSessionHasNoErrors();

        $parent->refresh();
        $this->assertSame(['Азимова Мадина'], $parent->children->pluck('full_name')->all());
        $this->assertSame('married', $parent->details->marital_status);
        $this->assertSpouseKept($parent);
        $this->assertRelativesKept($parent);

        $this->actingAs($parent)
            ->put("/employees/{$employee->id}/family", $this->payload())
            ->assertForbidden();
    }
}
