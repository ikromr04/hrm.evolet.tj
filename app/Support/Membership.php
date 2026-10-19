<?php

namespace App\Support;

use App\Models\Department;
use App\Models\Position;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as Employees;
use Illuminate\Support\Facades\DB;

/**
 * Who belongs to which department, worked out rather than written down.
 *
 * Somebody belongs to a department because they hold one of its positions, and
 * they lead it because the position they hold is a leading one of it. Both
 * facts already exist elsewhere — position_user says who holds what,
 * positions.department_id says where a position sits, positions.is_head says
 * which positions lead — so department_user says nothing of its own any more.
 * It is the same question answered in advance.
 *
 * It is kept all the same, and kept correct from here. Equipment access reads
 * it, the chart counts with it, the staff lists and the filters join on it, and
 * section 01 of a department's profile names its heads out of it; replacing all
 * of that with a join through two tables at once is how a change like this goes
 * wrong. So the table stays and becomes derived: this class writes it, and
 * nothing else does.
 *
 * Which means every place a position changes hands has to say so. Giving a
 * position, taking it away, moving a position to another department, marking it
 * leading or unmarking it, and deleting it are all changes of membership now,
 * and each of them calls {@see refresh()} with the people it moved.
 *
 * One kind of row is left alone. People who no longer work here keep what they
 * have: a dismissed or transferred colleague's membership is a record of where
 * they were, and a position being reorganised today is no reason to rewrite it.
 * The directory's own form never touched them either.
 *
 * Everything else follows the positions, and nothing is filed by hand. A
 * membership no position of that department accounts for is not a membership —
 * it was written before the rule and is deleted the first time this class reads
 * that person. See the migration that took the by_hand column away.
 */
final class Membership
{
    /**
     * Work out where these people belong and write it down.
     *
     * The rows are replaced: a department one of their positions sits in is a
     * membership, one no position of theirs sits in is not, and leading follows
     * the flag on the positions they hold there. Several positions in one
     * department are one membership, and it leads the department if any one of
     * them does.
     */
    public static function refresh(User|iterable $employees): void
    {
        $employees = $employees instanceof User ? [$employees] : $employees;

        foreach ($employees as $employee) {
            if ($employee->isActive()) {
                self::write($employee);
            }
        }
    }

    /**
     * Whoever a change to this position could move: the people holding it.
     *
     * Read before the change, not after. A position that is deleted takes its
     * holders with it, and a position moved to another department has to be
     * answered for in the department it is leaving as well as the one it
     * arrives in — by then the row no longer says which department that was,
     * but the people are the same people either way, and what they belong to is
     * worked out from scratch.
     */
    public static function behind(Position $position): Employees
    {
        return $position->users()->get();
    }

    /**
     * And whoever a change to this department could move: everybody holding one
     * of its positions. What the editor needs when it marks which of them lead.
     */
    public static function within(Department $department): Employees
    {
        return User::query()
            ->whereHas('positions', fn ($query) => $query->where('positions.department_id', $department->id))
            ->get();
    }

    /** One person's memberships, made to agree with the positions they hold. */
    private static function write(User $employee): void
    {
        $derived = self::derive($employee);

        $filed = DB::table('department_user')
            ->where('user_id', $employee->id)
            ->get(['id', 'department_id', 'is_head']);

        $gone = [];
        $now = now();

        foreach ($filed as $row) {
            $department = (int) $row->department_id;
            $leads = $derived[$department] ?? null;

            // Nothing accounts for it: the department belongs to the job, and
            // there is no job of it to belong through.
            if ($leads === null) {
                $gone[] = $row->id;

                continue;
            }

            // A position accounts for it, so it says what the position says —
            // the leading included, which is the crown on the job and not a
            // fact of its own.
            unset($derived[$department]);

            if ((bool) $row->is_head !== $leads) {
                DB::table('department_user')
                    ->where('id', $row->id)
                    ->update(['is_head' => $leads, 'updated_at' => $now]);
            }
        }

        if ($gone !== []) {
            DB::table('department_user')->whereIn('id', $gone)->delete();
        }

        $added = collect($derived)
            ->map(fn (bool $leads, int $department) => [
                'user_id' => $employee->id,
                'department_id' => $department,
                'is_head' => $leads,
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->values();

        if ($added->isNotEmpty()) {
            DB::table('department_user')->insert($added->all());
        }

        $employee->unsetRelation('departments');
    }

    /**
     * The departments this person's positions put them in, each said to be led
     * or not.
     *
     * A position nobody has filed in a department yet puts them nowhere — it is
     * a directory entry waiting to be placed, and there is no department for it
     * to mean.
     *
     * @return array<int, bool> department id => whether they lead it
     */
    private static function derive(User $employee): array
    {
        $held = DB::table('position_user')
            ->join('positions', 'positions.id', '=', 'position_user.position_id')
            ->where('position_user.user_id', $employee->id)
            ->whereNotNull('positions.department_id')
            ->get(['positions.department_id', 'positions.is_head']);

        $departments = [];

        foreach ($held as $position) {
            $id = (int) $position->department_id;
            $departments[$id] = ($departments[$id] ?? false) || (bool) $position->is_head;
        }

        return $departments;
    }
}
