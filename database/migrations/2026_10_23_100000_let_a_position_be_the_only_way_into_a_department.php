<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Holding a position is the only way into a department.
     *
     * The migration before this one kept two kinds of row the positions do not
     * account for — a membership nobody could explain, and a headship no
     * crowned position stands behind — and marked them `by_hand` until the
     * company said what they were. The company has said: there is nothing to
     * keep. A department follows from the job, and a person without a job in a
     * department does not belong to it.
     *
     * So the filing of everybody who still works here is made to say exactly
     * what their positions say, and the column goes with the rule it carried.
     * What this deletes is named as it runs, because afterwards nothing
     * remembers it: a membership deleted here was somebody's row in a list, and
     * whoever wonders where it went deserves to read why.
     *
     * People who no longer work here are left alone, as every other rule in
     * this application leaves them: a dismissed colleague's filing is a record
     * of where they were, not a claim about where they belong.
     */
    public function up(): void
    {
        $this->makeTheFilingSayWhatThePositionsSay();

        if (Schema::hasColumn('department_user', 'by_hand')) {
            Schema::table('department_user', fn (Blueprint $table) => $table->dropColumn('by_hand'));
        }
    }

    /**
     * Read the two tables once and settle every row of a working colleague:
     * one no position of that department accounts for is deleted, and one that
     * claims a headship no crowned position accounts for stops claiming it.
     *
     * Worked out in PHP rather than in one statement per database: this runs
     * against MySQL in production and SQLite in the tests, and a delete joined
     * to a subquery is written differently in each.
     */
    private function makeTheFilingSayWhatThePositionsSay(): void
    {
        $filed = DB::table('department_user')
            ->join('users', 'users.id', '=', 'department_user.user_id')
            ->join('departments', 'departments.id', '=', 'department_user.department_id')
            ->where('users.status', 'active')
            ->orderBy('users.surname')
            ->orderBy('departments.name')
            ->get([
                'department_user.id',
                'department_user.user_id',
                'department_user.department_id',
                'department_user.is_head',
                'departments.name as department',
                'users.name',
                'users.surname',
            ]);

        if ($filed->isEmpty()) {
            return;
        }

        // Which department each position puts somebody in, and whether it
        // leads: several positions in one department are one membership, and it
        // leads the department if any one of them does.
        $leads = [];

        foreach (DB::table('position_user')
            ->join('positions', 'positions.id', '=', 'position_user.position_id')
            ->whereNotNull('positions.department_id')
            ->get(['position_user.user_id', 'positions.department_id', 'positions.is_head']) as $held) {
            $key = $held->user_id.':'.$held->department_id;
            $leads[$key] = ($leads[$key] ?? false) || (bool) $held->is_head;
        }

        $gone = [];
        $demoted = [];
        $name = fn (object $row) => "{$row->surname} {$row->name} — {$row->department}";

        foreach ($filed as $row) {
            $key = $row->user_id.':'.$row->department_id;

            if (! array_key_exists($key, $leads)) {
                $gone[$row->id] = $name($row);

                continue;
            }

            if ((bool) $row->is_head !== $leads[$key]) {
                $demoted[$row->id] = $name($row).($leads[$key] ? ' — теперь руководитель' : ' — больше не руководитель');
                DB::table('department_user')->where('id', $row->id)->update(['is_head' => $leads[$key], 'updated_at' => now()]);
            }
        }

        if ($gone !== []) {
            DB::table('department_user')->whereIn('id', array_keys($gone))->delete();
        }

        $this->say('Отделы, снятые с сотрудников — должности в них у них нет', array_values($gone));
        $this->say('Руководство, приведённое к должностям', array_values($demoted));
    }

    /**
     * @param  list<string>  $lines
     */
    private function say(string $what, array $lines): void
    {
        if ($lines !== []) {
            echo PHP_EOL.$what.' ('.count($lines).'):'.PHP_EOL.'  '.implode(PHP_EOL.'  ', $lines).PHP_EOL;
        }
    }

    /**
     * The column comes back empty.
     *
     * What it used to mark is gone: the rows it protected were deleted by this
     * migration and nothing wrote down which they were, so there is nothing to
     * restore them from. A down() that guessed would be inventing memberships,
     * which is worse than a column that comes back saying nothing.
     */
    public function down(): void
    {
        if (Schema::hasColumn('department_user', 'by_hand')) {
            return;
        }

        Schema::table('department_user', function (Blueprint $table) {
            $table->boolean('by_hand')->default(false)->after('is_head');
        });
    }
};
