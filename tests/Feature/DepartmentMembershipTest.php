<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Position;
use App\Models\User;
use App\Support\Membership;
use App\Support\Placements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Belonging to a department follows from holding one of its positions.
 *
 * That is the whole rule, and these are the ways it is asked: give somebody a
 * position and they are in the department, take it away and they are out of it,
 * move the position to another department and they move with it, crown the
 * position and they head it.
 *
 * department_user is still written, because everything that reads who is where
 * reads it — equipment access, the chart's counts, the staff lists, the
 * filters, section 01 of a department's profile. App\Support\Membership is the
 * one place that writes it, so these go through that rather than through each
 * caller.
 */
class DepartmentMembershipTest extends TestCase
{
    use RefreshDatabase;

    public function test_holding_a_position_is_what_puts_somebody_in_a_department()
    {
        $department = Department::create(['name' => 'Отдел Дизайна']);
        $position = Position::create(['department_id' => $department->id, 'name' => 'Дизайнер']);
        $employee = User::factory()->create();

        $employee->positions()->attach($position);
        Membership::refresh($employee);

        $this->assertSame([$department->id], $employee->departments()->pluck('departments.id')->all());
        $this->assertFalse($department->heads()->exists());

        // And taking it away takes the department with it, which is the whole
        // point: the two used to drift apart and had to be kept together by
        // hand.
        $employee->positions()->detach($position);
        Membership::refresh($employee);

        $this->assertFalse($employee->departments()->exists());
    }

    public function test_a_crowned_position_makes_its_holders_heads()
    {
        $department = Department::create(['name' => 'Отдел Дизайна']);
        $leading = Position::create(['department_id' => $department->id, 'name' => 'Арт-директор', 'is_head' => true]);
        $employee = User::factory()->create();

        $employee->positions()->attach($leading);
        Membership::refresh($employee);

        $this->assertSame([$employee->id], $department->heads()->pluck('users.id')->all());

        // Uncrowned, they stay in the department: they still hold the job.
        $leading->update(['is_head' => false]);
        Membership::refresh(Membership::behind($leading));

        $this->assertFalse($department->heads()->exists());
        $this->assertTrue($department->users()->whereKey($employee->id)->exists());
    }

    /**
     * Somebody holding two positions in one department is in it once, and leads
     * it if either of them is crowned — which is the shape of the head who
     * holds two positions in the department she heads, and the reason the
     * backfill would not guess which of the two leads.
     */
    public function test_two_positions_in_one_department_are_one_membership()
    {
        $department = Department::create(['name' => 'Отдел финансов']);
        $plain = Position::create(['department_id' => $department->id, 'name' => 'Тех аналитик-дизайнер']);
        $leading = Position::create(['department_id' => $department->id, 'name' => 'Младший специалист', 'is_head' => true]);
        $employee = User::factory()->create();

        $employee->positions()->attach([$plain->id, $leading->id]);
        Membership::refresh($employee);

        $this->assertSame(1, $department->users()->count());
        $this->assertSame([$employee->id], $department->heads()->pluck('users.id')->all());

        // The crowned one goes and the plain one stays: still in, no longer
        // leading.
        $employee->positions()->detach($leading);
        Membership::refresh($employee);

        $this->assertTrue($department->users()->whereKey($employee->id)->exists());
        $this->assertFalse($department->heads()->exists());
    }

    public function test_moving_a_position_moves_everybody_holding_it()
    {
        $was = Department::create(['name' => 'Отдел Дизайна']);
        $now = Department::create(['name' => 'Отдел сбыта']);
        $position = Position::create(['department_id' => $was->id, 'name' => 'Аналитик', 'is_head' => true]);
        $employee = User::factory()->create();

        $employee->positions()->attach($position);
        Membership::refresh($employee);
        $this->assertSame([$was->id], $employee->departments()->pluck('departments.id')->all());

        // The holders are read before the move, because afterwards the position
        // no longer says which department it was in.
        $holders = Membership::behind($position);
        $position->update(['department_id' => $now->id]);
        Membership::refresh($holders);

        $this->assertSame([$now->id], $employee->departments()->pluck('departments.id')->all());
        $this->assertSame([$employee->id], $now->heads()->pluck('users.id')->all());
        $this->assertFalse($was->users()->exists());
    }

    /**
     * A position nobody has filed in a department yet puts nobody anywhere: it
     * is a directory entry waiting to be placed, and there are three of them on
     * file.
     */
    public function test_a_position_with_no_department_puts_nobody_anywhere()
    {
        $unplaced = Position::create(['name' => 'Юрист']);
        $employee = User::factory()->create();

        $employee->positions()->attach($unplaced);
        Membership::refresh($employee);

        $this->assertFalse($employee->departments()->exists());
    }

    /**
     * Dismissed and transferred people are not touched, exactly as the
     * directory's own form never touched them: their membership is a record of
     * where they were, and reorganising a position today is not a reason to
     * rewrite it.
     */
    public function test_people_who_no_longer_work_here_keep_what_they_have()
    {
        $department = Department::create(['name' => 'Отдел Дизайна']);
        $position = Position::create(['department_id' => $department->id, 'name' => 'Дизайнер']);

        foreach (['fired', 'transferred'] as $status) {
            $employee = User::factory()->create(['status' => $status]);
            $department->users()->attach($employee, ['is_head' => true]);

            // Neither holding the position nor losing it changes anything.
            $employee->positions()->attach($position);
            Membership::refresh($employee);
            $employee->positions()->detach($position);
            Membership::refresh($employee);

            $this->assertTrue($department->users()->whereKey($employee->id)->exists(), $status);
            $this->assertTrue($department->heads()->whereKey($employee->id)->exists(), $status);
        }
    }

    /**
     * A membership nothing accounts for is not kept.
     *
     * Rows like it were written before the rule, when a department could be
     * filed on somebody by hand; the migration that settled the rule deleted
     * the ones on file and this class deletes any that appear since. A
     * department belongs to the job, so without a job in it there is nothing to
     * belong through.
     */
    public function test_a_membership_no_position_accounts_for_is_removed()
    {
        $elsewhere = Department::create(['name' => 'Отдел Дизайна']);
        $unexplained = Department::create(['name' => 'Департамент контрактного производства']);
        $real = Position::create(['department_id' => $elsewhere->id, 'name' => 'Дизайнер']);
        $employee = User::factory()->create();

        $employee->positions()->attach($real);
        $employee->departments()->attach($unexplained, ['is_head' => false]);
        $employee->departments()->attach($elsewhere, ['is_head' => false]);

        Membership::refresh($employee);

        // The one the position accounts for stays; the other one goes.
        $this->assertSame([$elsewhere->id], $employee->departments()->pluck('departments.id')->all());

        // And giving them a job there is what puts them back in it.
        $employee->positions()->attach(Position::create([
            'department_id' => $unexplained->id,
            'name' => 'Технолог',
            'is_head' => true,
        ]));
        Membership::refresh($employee);

        $this->assertSame([$employee->id], $unexplained->heads()->pluck('users.id')->all());
    }

    /**
     * Leading a department is the crown on a job and nothing else.
     *
     * A headship written on the membership itself is not kept either: whoever
     * is to lead holds a position marked as leading, and until somebody marks
     * one nobody leads. Marking it says it of everybody holding that job.
     */
    public function test_a_headship_follows_the_crown_on_the_job()
    {
        $department = Department::create(['name' => 'Отдел платежной реконсиляции']);
        $shared = Position::create(['department_id' => $department->id, 'name' => 'Видеомейкер']);
        $head = User::factory()->create();
        $colleague = User::factory()->create();
        $head->positions()->attach($shared);
        $colleague->positions()->attach($shared);
        $head->departments()->attach($department, ['is_head' => true]);
        $colleague->departments()->attach($department, ['is_head' => false]);

        Membership::refresh([$head, $colleague]);

        // Both hold the same uncrowned job, so neither leads the department.
        $this->assertSame([], $department->heads()->pluck('users.id')->all());

        // Crowning the job says it of both of them.
        $shared->update(['is_head' => true]);
        Membership::refresh(Membership::behind($shared));

        $this->assertEqualsCanonicalizing([$head->id, $colleague->id], $department->heads()->pluck('users.id')->all());
    }

    /**
     * The card cannot file somebody in a department without giving them a job
     * in it: the form may still name the department — it is how a position is
     * looked up — but what it files is the position, and a placement naming no
     * position files nothing.
     */
    public function test_the_card_cannot_file_a_department_without_a_position_in_it()
    {
        $department = Department::create(['name' => 'Отдел Дизайна']);
        $position = Position::create(['department_id' => $department->id, 'name' => 'Дизайнер']);
        $employee = User::factory()->create();

        Placements::save($employee, [['department_id' => $department->id, 'position_id' => null]]);

        $this->assertFalse($employee->departments()->exists());
        $this->assertSame(0, DB::table('department_user')->count());

        // The same form naming the job files both, and the department follows.
        Placements::save($employee, [['department_id' => $department->id, 'position_id' => $position->id]]);

        $this->assertSame([$department->id], $employee->fresh()->departments()->pluck('departments.id')->all());
    }
}
