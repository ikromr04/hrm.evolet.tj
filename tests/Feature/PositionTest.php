<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Position;
use App\Models\PositionDuty;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\PositionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class PositionTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_creates_all_positions_once()
    {
        $this->seed(PositionSeeder::class);
        $this->seed(PositionSeeder::class);

        $this->assertSame(39, Position::count());
        $this->assertTrue(Position::where('name', 'Руководитель отдела дизайна')->exists());
    }

    public function test_seeder_files_every_position_in_a_department()
    {
        $this->seed(DepartmentSeeder::class);
        $this->seed(PositionSeeder::class);
        $this->seed(PositionSeeder::class);

        $this->assertSame(0, Position::whereNull('department_id')->count());
        $this->assertSame('Отдел Дизайна', Position::firstWhere('name', 'Руководитель отдела дизайна')->department->name);
        $this->assertSame('Отдел цифрового маркетинга', Position::firstWhere('name', 'Копирайтер')->department->name);
    }

    public function test_a_position_seeded_before_the_departments_waits_to_be_placed()
    {
        // Which is how most of the suite seeds it: a position is kept on file
        // before anybody says where it sits, and the column lets it.
        $this->seed(PositionSeeder::class);

        $this->assertSame(39, Position::whereNull('department_id')->count());
        $this->assertNull(Position::firstWhere('name', 'Копирайтер')->department);
    }

    public function test_seeder_leaves_a_department_somebody_changed_alone()
    {
        $this->seed(DepartmentSeeder::class);
        $this->seed(PositionSeeder::class);

        $registration = Department::firstWhere('name', 'Отдел регистрации');
        Position::firstWhere('name', 'Копирайтер')->update(['department_id' => $registration->id]);

        $this->seed(PositionSeeder::class);

        $this->assertSame('Отдел регистрации', Position::firstWhere('name', 'Копирайтер')->department->name);
    }

    public function test_seeder_writes_the_duties_of_every_position_once()
    {
        $this->seed(PositionSeeder::class);
        $this->seed(PositionSeeder::class);

        $this->assertSame(0, Position::doesntHave('duties')->count());
        $this->assertSame(
            collect(PositionSeeder::DUTIES)->flatten()->count(),
            PositionDuty::count(),
        );

        // Numbered on the page by this column, so it has to be the place in the
        // list the seeder reads from, and the first duty has to be the first one.
        $duties = Position::firstWhere('name', 'Уборщица')->duties;

        $this->assertSame(PositionSeeder::DUTIES['Уборщица'], $duties->pluck('name')->all());
        $this->assertSame(range(0, $duties->count() - 1), $duties->pluck('order')->all());
    }

    public function test_a_position_keeps_the_duties_somebody_wrote_for_it()
    {
        $this->seed(PositionSeeder::class);

        $position = Position::firstWhere('name', 'Переводчик');
        $position->duties()->delete();
        $position->duties()->create(['name' => 'Переводит то, что принесли', 'order' => 0]);

        $this->seed(PositionSeeder::class);

        $this->assertSame(['Переводит то, что принесли'], $position->fresh()->duties->pluck('name')->all());
    }

    public function test_an_employee_can_hold_several_positions()
    {
        $user = User::factory()->create();
        Position::create(['name' => 'Переводчик']);
        Position::create(['name' => 'Копирайтер']);
        $user->positions()->attach(Position::pluck('id'));

        $this->assertSame(['Копирайтер', 'Переводчик'], $user->fresh()->positions->pluck('name')->all());
    }

    public function test_a_position_belongs_to_one_department_and_outlives_it()
    {
        $marketing = Department::create(['name' => 'Отдел цифрового маркетинга', 'abbreviation' => 'ОЦМ']);
        $position = Position::create(['name' => 'Копирайтер', 'department_id' => $marketing->id]);
        $position->duties()->create(['name' => 'Пишет тексты для рассылок', 'order' => 0]);
        $holder = User::factory()->create();
        $holder->positions()->attach($position);

        $this->assertTrue($position->department->is($marketing));
        $this->assertTrue($marketing->positions->contains($position));

        // A position is a directory entry with holders and duties of its own,
        // and losing a department is not a reason to lose those: it comes loose
        // and reads as unplaced until somebody re-files it.
        $marketing->delete();

        $this->assertNull($position->fresh()->department_id);
        $this->assertCount(1, $position->fresh()->duties);
        $this->assertCount(1, $holder->fresh()->positions);
    }

    public function test_the_same_name_may_be_used_in_two_departments_but_not_twice_in_one()
    {
        $analytics = Department::create(['name' => 'Отдел аналитики', 'abbreviation' => 'ОА']);
        $regulation = Department::create(['name' => 'Отдел правового регулирования', 'abbreviation' => 'ОПР']);

        Position::create(['name' => 'Аналитик', 'department_id' => $analytics->id]);
        Position::create(['name' => 'Аналитик', 'department_id' => $regulation->id]);

        $this->assertSame(2, Position::where('name', 'Аналитик')->count());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Должность «Аналитик» в этом отделе уже есть.');

        Position::create(['name' => 'Аналитик', 'department_id' => $analytics->id]);
    }

    public function test_a_position_waiting_to_be_placed_still_cannot_repeat_a_name()
    {
        // The index on the pair cannot police these — MySQL lets any number of
        // rows share a name while the department is null — so the model does,
        // and "no department" counts as a group of its own.
        Position::create(['name' => 'Аналитик']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Должность «Аналитик» уже есть среди должностей без отдела.');

        Position::create(['name' => 'Аналитик']);
    }

    public function test_a_position_reads_as_its_name_and_the_department_it_belongs_to()
    {
        $producers = Department::create(['name' => 'Отдел анализа производителей', 'abbreviation' => 'ОАП']);
        $science = Department::create(['name' => 'Научный отдел']);

        $theirs = Position::create(['name' => 'Администратор', 'department_id' => $producers->id]);
        $others = Position::create(['name' => 'Администратор', 'department_id' => $science->id]);
        $unplaced = Position::create(['name' => 'Уборщица']);

        // The same word and different work: on screen the department is the only
        // thing telling the two apart, said the way the department says it —
        // short name where there is one, full name where there is not.
        $this->assertSame('Администратор (ОАП)', $theirs->display_name);
        $this->assertSame('Администратор (Научный отдел)', $others->display_name);
        // Nothing to put in brackets, so there are no brackets.
        $this->assertSame('Уборщица', $unplaced->display_name);
    }

    public function test_duties_are_read_back_in_the_order_they_were_entered()
    {
        $position = Position::create(['name' => 'Юрист']);

        // Written in the order somebody thought of them, and the one they thought
        // of last stays last whatever its name — a list of duties is not a list
        // sorted by anything.
        $position->duties()->createMany([
            ['name' => 'Ведение договоров', 'order' => 0],
            ['name' => 'Арбитраж', 'order' => 1],
        ]);

        $this->assertSame(['Ведение договоров', 'Арбитраж'], $position->fresh()->duties->pluck('name')->all());
    }

    public function test_an_employee_carries_the_duties_picked_for_them_and_not_the_whole_list()
    {
        $position = Position::create(['name' => 'Аналитик']);
        [$drugs, $market, $prices] = $position->duties()->createMany([
            ['name' => 'Ведёт закреплённые за ним препараты', 'order' => 0],
            ['name' => 'Готовит отчёт по рынку', 'order' => 1],
            ['name' => 'Сверяет цены по Европе', 'order' => 2],
        ])->all();

        $employee = User::factory()->create();
        $employee->positions()->attach($position);

        // The list is what the position answers for between its holders, so
        // holding it hands nobody the whole of it.
        $this->assertCount(0, $employee->duties);

        $employee->duties()->attach([$prices->id, $drugs->id]);

        // Read the way the list reads, not the way they were picked.
        $this->assertSame([
            'Ведёт закреплённые за ним препараты',
            'Сверяет цены по Европе',
        ], $employee->fresh()->duties->pluck('name')->all());

        $this->assertTrue($drugs->users->contains($employee));
        $this->assertCount(0, $market->users);
    }

    public function test_a_duty_nobody_wrote_down_any_more_is_nobody_s_duty()
    {
        $position = Position::create(['name' => 'Аналитик']);
        [$drugs, $market] = $position->duties()->createMany([
            ['name' => 'Ведёт закреплённые за ним препараты', 'order' => 0],
            ['name' => 'Готовит отчёт по рынку', 'order' => 1],
        ])->all();

        $employee = User::factory()->create();
        $employee->duties()->attach([$drugs->id, $market->id]);

        $market->delete();

        $this->assertSame(['Ведёт закреплённые за ним препараты'], $employee->fresh()->duties->pluck('name')->all());

        // And the whole position going takes its duties and everybody's hold on
        // them with it.
        $position->delete();

        $this->assertCount(0, $employee->fresh()->duties);
    }

    public function test_duties_belong_to_their_position_and_go_when_it_does()
    {
        $position = Position::create(['name' => 'Юрист']);
        $duty = $position->duties()->create(['name' => 'Ведение договоров', 'order' => 0]);
        $employee = User::factory()->create();
        $employee->positions()->attach($position);

        $this->assertTrue($duty->position->is($position));

        $position->delete();

        $this->assertSame(0, PositionDuty::count());
        $this->assertCount(0, $employee->fresh()->positions);
    }

    public function test_each_head_title_is_held_by_someone_in_the_unit_they_lead()
    {
        $this->seed(DatabaseSeeder::class);

        foreach (PositionSeeder::HEADS as $title => $department) {
            $position = Position::firstWhere('name', $title);
            $holders = $position->users()->with('departments')->get();

            $this->assertSame($department, $position->department->name);
            $this->assertCount(1, $holders, "{$title} should have exactly one holder");
            $this->assertContains($department, $holders->first()->departments->pluck('name'));
        }
    }

    public function test_every_employee_but_the_admin_has_a_position()
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(0, User::withoutRole('admin')->doesntHave('positions')->count());
        $this->assertCount(0, User::role('admin')->first()->positions);
        $this->assertSame(0, User::where('sex', 'male')->whereHas('positions', fn ($q) => $q->where('name', 'Уборщица'))->count());
    }

    public function test_a_seeded_employee_carries_some_of_their_duties_and_rarely_all()
    {
        $this->seed(DatabaseSeeder::class);

        // Everybody with a position answers for part of its list; nobody is
        // handed the list because they hold the title.
        $this->assertSame(0, User::has('positions')->doesntHave('duties')->count());

        User::with('positions.duties', 'duties')->has('positions')->get()->each(function (User $employee) {
            $theirs = $employee->positions->flatMap(fn (Position $position) => $position->duties)->pluck('id');

            $this->assertTrue($employee->duties->pluck('id')->every(fn (int $id) => $theirs->contains($id)));
            $this->assertLessThanOrEqual($theirs->count(), $employee->duties->count());
        });
    }
}
