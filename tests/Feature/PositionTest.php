<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Position;
use App\Models\PositionDuty;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\PositionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_the_duties_of_a_department_are_read_after_the_common_ones()
    {
        $position = Position::create(['name' => 'Аналитик']);
        $regulation = Department::create(['name' => 'Отдел правового регулирования', 'abbreviation' => 'ОПР']);

        // Each group is numbered from the top — the common duties are a group
        // and so is every department — and the list is read group by group, the
        // ones everybody carries first.
        $position->duties()->createMany([
            ['name' => 'Ведёт закреплённые за ним препараты', 'order' => 0],
            ['name' => 'Готовит отчёт по рынку', 'order' => 1],
            ['name' => 'Проверяет документы на соответствие закону', 'department_id' => $regulation->id, 'order' => 0],
            ['name' => 'Готовит ответы регулятору', 'department_id' => $regulation->id, 'order' => 1],
        ]);

        $this->assertSame([
            'Ведёт закреплённые за ним препараты',
            'Готовит отчёт по рынку',
            'Проверяет документы на соответствие закону',
            'Готовит ответы регулятору',
        ], $position->fresh()->duties->pluck('name')->all());
    }

    public function test_a_duty_of_a_department_goes_when_that_department_does()
    {
        $position = Position::create(['name' => 'Аналитик']);
        $regulation = Department::create(['name' => 'Отдел правового регулирования', 'abbreviation' => 'ОПР']);
        $europe = Department::create(['name' => 'Отдел мониторинга Эволет Европы', 'abbreviation' => 'ОМФК Европа']);

        $common = $position->duties()->create(['name' => 'Готовит отчёт по рынку', 'order' => 0]);
        $theirs = $position->duties()->create([
            'name' => 'Готовит ответы регулятору',
            'department_id' => $regulation->id,
            'order' => 0,
        ]);
        $elsewhere = $position->duties()->create([
            'name' => 'Сверяет цены по Европе',
            'department_id' => $europe->id,
            'order' => 0,
        ]);

        $this->assertTrue($theirs->department->is($regulation));

        // A duty that names a department is about that department and means
        // nothing without it, so it goes with it. What the position answers for
        // everywhere, and what it answers for in the other department, stay.
        $regulation->delete();

        $this->assertNull(PositionDuty::find($theirs->id));
        $this->assertSame(
            [$common->id, $elsewhere->id],
            $position->fresh()->duties->pluck('id')->all(),
        );
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
            $holders = Position::firstWhere('name', $title)->users()->with('departments')->get();

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
}
