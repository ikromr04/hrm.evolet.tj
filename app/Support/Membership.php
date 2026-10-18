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
 * Two kinds of row are left alone.
 *
 * People who no longer work here keep what they have. A dismissed or
 * transferred colleague's membership is a record of where they were, and a
 * position being reorganised today is no reason to rewrite it. The directory's
 * own form never touched them either.
 *
 * And a row marked by hand carries something the positions do not account for:
 * the membership itself, for the thirteen nobody can explain, or the leading on
 * it, for the two heads whose job in their own unit is not a crowned one. Such
 * a row is adopted as soon as the positions say everything it says, and left
 * exactly as it stands while they would take something away from it. The
 * company has not decided what those rows are, and deciding by deletion to make
 * the derivation tidy would be losing data to make a point. See the migration
 * that added department_user.by_hand.
 */
final class Membership
{
    /**
     * Work out where these people belong and write it down.
     *
     * The derived rows are replaced: a department one of their positions sits
     * in is a membership, one no position of theirs sits in is not, and leading
     * follows the flag on the positions they hold there. Several positions in
     * one department are one membership, and it leads the department if any one
     * of them does.
     *
     * $byHand is the card's placement form speaking, and only it. The form may
     * still name a department without naming a position in it, which is a
     * membership nothing accounts for, so the form says which departments those
     * are and the hand-written rows are made to match — the ones it no longer
     * names go, because the form hands back the whole of somebody's filing.
     * Every other caller passes nothing and the hand-written rows are not its
     * business.
     *
     * @param  list<int>|null  $byHand  departments filed by hand; null leaves the hand-written rows alone
     */
    public static function refresh(User|iterable $employees, ?array $byHand = null): void
    {
        $employees = $employees instanceof User ? [$employees] : $employees;

        foreach ($employees as $employee) {
            if ($employee->isActive()) {
                self::write($employee, $byHand);
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

    /**
     * One person's memberships, made to agree with the positions they hold.
     *
     * @param  list<int>|null  $byHand
     */
    private static function write(User $employee, ?array $byHand): void
    {
        $derived = self::derive($employee);
        $byHand = $byHand === null ? null : array_map('intval', $byHand);

        $filed = DB::table('department_user')
            ->where('user_id', $employee->id)
            ->get(['id', 'department_id', 'is_head', 'by_hand']);

        $gone = [];
        $now = now();

        foreach ($filed as $row) {
            $department = (int) $row->department_id;
            $leads = $derived[$department] ?? null;

            // A position accounts for this one, so it says what the position
            // says.
            //
            // Unless it was written by hand, in which case it is adopted as
            // soon as the positions account for everything it claims, and left
            // alone only while they would take something away from it. A row
            // claiming a headship no crowned position explains is the second
            // kind: two heads on file hold a position in the unit they lead
            // without that position being the crowned one, and deriving them
            // plainly would quietly demote them. Crowning a position they hold
            // is an answer; this class is not.
            if ($leads !== null) {
                unset($derived[$department]);

                if ($row->by_hand && $row->is_head && ! $leads) {
                    continue;
                }

                if ((bool) $row->is_head !== $leads || $row->by_hand) {
                    DB::table('department_user')
                        ->where('id', $row->id)
                        ->update(['is_head' => $leads, 'by_hand' => false, 'updated_at' => $now]);
                }

                continue;
            }

            // Nothing accounts for it. Left to itself it is a row this class
            // does not own and will not touch; named by the card's form, it is
            // somebody's own filing and stays as that.
            if ($byHand === null) {
                if (! $row->by_hand) {
                    $gone[] = $row->id;
                }

                continue;
            }

            if (! in_array($department, $byHand, true)) {
                $gone[] = $row->id;

                continue;
            }

            // Already hand-written, so it is left exactly as it stands —
            // including a headship nothing accounts for, which is not this
            // class's to take away. One that has just lost the position behind
            // it loses the leading with it.
            if (! $row->by_hand) {
                DB::table('department_user')
                    ->where('id', $row->id)
                    ->update(['is_head' => false, 'by_hand' => true, 'updated_at' => $now]);
            }
        }

        if ($gone !== []) {
            DB::table('department_user')->whereIn('id', $gone)->delete();
        }

        $filedIds = $filed->pluck('department_id')->map(fn (mixed $id) => (int) $id)->all();

        $added = collect($derived)
            ->map(fn (bool $leads, int $department) => [
                'user_id' => $employee->id,
                'department_id' => $department,
                'is_head' => $leads,
                'by_hand' => false,
            ])
            ->values()
            // A department the form named with no position in it is a
            // membership nothing accounts for, which is what by_hand is for.
            ->merge(collect($byHand ?? [])
                ->unique()
                ->reject(fn (int $department) => isset($derived[$department]) || in_array($department, $filedIds, true))
                ->map(fn (int $department) => [
                    'user_id' => $employee->id,
                    'department_id' => $department,
                    'is_head' => false,
                    'by_hand' => true,
                ])
                ->values())
            ->map(fn (array $row) => [...$row, 'created_at' => $now, 'updated_at' => $now]);

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
