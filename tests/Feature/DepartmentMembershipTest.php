<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Position;
use App\Models\User;
use App\Support\Membership;
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
     * The transitional rule, and the one that is not a conclusion about how the
     * company should work.
     *
     * Thirteen memberships on file have no position of that department behind
     * them — every one a second or third department of somebody whose single
     * real position is somewhere else. Derived plainly they would all be
     * deleted the first time anything touched those people, which is a decision
     * for the company and not for this class, so they are marked by hand and
     * left exactly as they are.
     *
     * They are not read as mistakes either: the moment a position accounts for
     * one, it stops being hand-written and follows the positions like the rest.
     */
    public function test_a_membership_no_position_accounts_for_is_kept()
    {
        $elsewhere = Department::create(['name' => 'Отдел Дизайна']);
        $byHand = Department::create(['name' => 'Департамент контрактного производства']);
        $real = Position::create(['department_id' => $elsewhere->id, 'name' => 'Дизайнер']);
        $employee = User::factory()->create();

        $employee->positions()->attach($real);
        $employee->departments()->attach($byHand, ['is_head' => false, 'by_hand' => true]);
        $employee->departments()->attach($elsewhere, ['is_head' => false]);

        // Anything at all happening to this person leaves the hand-written row
        // where it is, including losing the position that was accounted for.
        Membership::refresh($employee);
        $this->assertTrue($employee->departments()->whereKey($byHand->id)->exists());

        $employee->positions()->detach($real);
        Membership::refresh($employee);

        $this->assertSame([$byHand->id], $employee->departments()->pluck('departments.id')->all());

        // And once a position of that department does account for it, it is an
        // ordinary derived membership and the note comes off.
        $employee->positions()->attach(Position::create([
            'department_id' => $byHand->id,
            'name' => 'Технолог',
            'is_head' => true,
        ]));
        Membership::refresh($employee);

        $this->assertSame([$employee->id], $byHand->heads()->pluck('users.id')->all());
        $this->assertSame(0, DB::table('department_user')->where('by_hand', true)->count());
    }

    /**
     * The other half of the transitional rule, and the smaller place the same
     * loss could happen.
     *
     * Two heads on file hold a position in the unit they lead without that
     * position being a crowned one: one holds two and nothing says which leads,
     * the other's is held by a colleague who is not a head. The membership is
     * accounted for, so nothing protects it as a whole — but the leading on it
     * is not, and deriving plainly would simply stop them being heads.
     *
     * Such a row is marked by hand too, and left alone while the positions
     * would take the leading away. Crowning a position they hold is the answer,
     * and then the row is an ordinary one again.
     */
    public function test_a_headship_no_crowned_position_accounts_for_is_kept()
    {
        $department = Department::create(['name' => 'Отдел платежной реконсиляции']);
        $shared = Position::create(['department_id' => $department->id, 'name' => 'Видеомейкер']);
        $head = User::factory()->create();
        $colleague = User::factory()->create();
        $head->positions()->attach($shared);
        $colleague->positions()->attach($shared);
        $head->departments()->attach($department, ['is_head' => true, 'by_hand' => true]);
        $colleague->departments()->attach($department, ['is_head' => false]);

        Membership::refresh([$head, $colleague]);

        // Still a head, and the colleague sharing the job is still not one.
        $this->assertSame([$head->id], $department->heads()->pluck('users.id')->all());

        // Crowning the position is somebody saying it out loud: now the job
        // explains the leading, the row stops being hand-written, and the
        // colleague who holds the same job leads it too — which is exactly why
        // no migration was allowed to decide this.
        $shared->update(['is_head' => true]);
        Membership::refresh(Membership::behind($shared));

        $this->assertEqualsCanonicalizing([$head->id, $colleague->id], $department->heads()->pluck('users.id')->all());
        $this->assertSame(0, DB::table('department_user')->where('by_hand', true)->count());
    }

    /**
     * The card may still file somebody in a department without giving them a
     * job in it — a few people are filed that way, and it is not the same as
     * not belonging to it. Such a membership is one written by hand, which is
     * exactly what the thirteen are.
     */
    public function test_the_card_may_still_file_a_department_with_no_position_in_it()
    {
        $department = Department::create(['name' => 'Отдел Дизайна']);
        $employee = User::factory()->create();

        Membership::refresh($employee, byHand: [$department->id]);

        $this->assertSame([$department->id], $employee->departments()->pluck('departments.id')->all());
        $this->assertSame(1, DB::table('department_user')->where('by_hand', true)->count());

        // And the same form taking it back is the one thing that may: it hands
        // over the whole of somebody's filing, so a department it no longer
        // names is one somebody removed.
        Membership::refresh($employee, byHand: []);

        $this->assertFalse($employee->departments()->exists());
    }
}
