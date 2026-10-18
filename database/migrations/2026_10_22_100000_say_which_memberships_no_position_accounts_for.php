<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which memberships somebody filed by hand, and which a position accounts
     * for.
     *
     * From now on belonging to a department follows from holding one of its
     * positions, and App\Support\Membership writes department_user from
     * position_user rather than anybody writing it directly. That works for
     * every row a position stands behind: give somebody the position and the
     * membership appears, take it away and the membership goes.
     *
     * It does not work for the rows no position stands behind, and there are
     * thirteen of them on file. Every one is a second or third department of
     * somebody whose only real position is somewhere else, and nobody has
     * decided yet whether they are a record of something true or leftovers of
     * the old way of filing. Derived plainly they would all be deleted on the
     * first save that touched those people, which is a decision made by a
     * migration rather than by the company.
     *
     * Two heads are in the same position for the same reason. Both hold a job
     * in the unit they lead, so the membership is accounted for, but neither
     * job is a crowned one — one of them holds two and nothing says which
     * leads, the other's is held by a colleague who is not a head — and the
     * migration before this one would not guess for either. Derived plainly
     * they would simply stop being heads, which is the same silent loss in a
     * smaller place.
     *
     * So the rows say for themselves where they came from. A row marked by
     * hand carries something no position accounts for — the membership itself,
     * or the leading on it — and Membership adopts it only once the positions
     * say everything it says, leaving it exactly as it is while they would take
     * something away. A row not marked is derived, and Membership owns it.
     *
     * The other direction is settled here rather than marked: six people hold
     * a position of a department the table never filed them under, and those
     * memberships are written, so that afterwards department_user says exactly
     * what the positions say. See addWhatThePositionsAlreadySay().
     *
     * This is a transitional column. When the company has said what those rows
     * are — a membership to keep for a reason or a leftover to drop, a position
     * to crown or a head to retire — they go one way or the other and the
     * column goes with them. Until then the card's placement form is the one
     * other thing that writes them: a placement naming a department and no
     * position is a membership by hand, which is exactly how the thirteen were
     * written in the first place.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('department_user', 'by_hand')) {
            Schema::table('department_user', function (Blueprint $table) {
                $table->boolean('by_hand')->default(false)->after('is_head');
            });
        }

        $this->markWhatNoPositionAccountsFor();
        $this->addWhatThePositionsAlreadySay();
    }

    /**
     * Every membership on file is read once, and the ones no position of that
     * department backs are marked.
     *
     * Read as it stands rather than from a list of thirteen ids written down
     * here: the number is what the local copy has, and the database this runs
     * against is allowed to have moved on. A row that is already marked stays
     * marked, so a second run of the migration says the same thing as the
     * first.
     */
    private function markWhatNoPositionAccountsFor(): void
    {
        $held = fn ($query) => $query
            ->selectRaw('1')
            ->from('position_user')
            ->join('positions', 'positions.id', '=', 'position_user.position_id')
            ->whereColumn('position_user.user_id', 'department_user.user_id')
            ->whereColumn('positions.department_id', 'department_user.department_id');

        $rows = DB::table('department_user')
            ->join('departments', 'departments.id', '=', 'department_user.department_id')
            ->join('users', 'users.id', '=', 'department_user.user_id')
            ->where(fn ($query) => $query
                // The membership itself is unexplained: no position of that
                // department at all.
                ->whereNotExists($held)
                // Or only the headship is: they hold a position there, and it
                // is not a crowned one, so deriving plainly would demote them.
                ->orWhere(fn ($query) => $query
                    ->where('department_user.is_head', true)
                    ->whereNotExists(fn ($query) => $held($query)->where('positions.is_head', true))))
            ->orderBy('users.surname')
            ->orderBy('departments.name')
            ->get([
                'department_user.id',
                'department_user.is_head',
                'departments.name as department',
                'users.name',
                'users.surname',
            ]);

        DB::table('department_user')->whereIn('id', $rows->pluck('id'))->update(['by_hand' => true]);

        [$heads, $memberships] = $rows->partition(fn (object $row) => (bool) $row->is_head);
        $name = fn (object $row) => "{$row->surname} {$row->name} — {$row->department}";

        $this->say(
            'Отделы, записанные сотрудникам вручную — ни одна должность их не объясняет',
            $memberships->map($name)->all()
        );
        $this->say(
            'Руководители, за которыми не закреплена руководящая должность — отметьте её, иначе руководство за ними только на этих записях',
            $heads->map($name)->all()
        );

        if ($rows->isNotEmpty()) {
            echo PHP_EOL.'Все эти записи сохранены как есть. Решения по ним принимаются отдельно.'.PHP_EOL;
        }
    }

    /**
     * The memberships the positions already imply and the table never got.
     *
     * Six people hold a position of a department they are not filed under. The
     * pair was nobody's to record before the card asked for the two together,
     * so the card has been showing those departments all along by reading
     * through the position, while the table behind the counts, the filters and
     * equipment access never heard of them.
     *
     * They are written now, in one place and named, rather than left to appear
     * one at a time as each of those people is next edited — which is what
     * would happen anyway, with the staff counts shifting for no reason
     * anybody could see. After this the table says exactly what the positions
     * say, which is the whole point of deriving it.
     *
     * Only people who still work here. A dismissed or transferred colleague's
     * filing is a record of where they were, and Membership does not touch it
     * either.
     */
    private function addWhatThePositionsAlreadySay(): void
    {
        $implied = DB::table('position_user')
            ->join('positions', 'positions.id', '=', 'position_user.position_id')
            ->join('users', 'users.id', '=', 'position_user.user_id')
            ->join('departments', 'departments.id', '=', 'positions.department_id')
            ->where('users.status', 'active')
            ->whereNotNull('positions.department_id')
            ->whereNotExists(fn ($query) => $query
                ->selectRaw('1')
                ->from('department_user')
                ->whereColumn('department_user.user_id', 'position_user.user_id')
                ->whereColumn('department_user.department_id', 'positions.department_id'))
            ->orderBy('users.surname')
            ->get([
                'position_user.user_id',
                'positions.department_id',
                'positions.is_head',
                'positions.name as position',
                'departments.name as department',
                'users.name',
                'users.surname',
            ]);

        if ($implied->isEmpty()) {
            return;
        }

        $now = now();

        // Several positions of the same department make one membership, and it
        // leads the department if any one of them does.
        $rows = $implied
            ->groupBy(fn (object $row) => $row->user_id.':'.$row->department_id)
            ->map(fn ($held) => [
                'user_id' => $held->first()->user_id,
                'department_id' => $held->first()->department_id,
                'is_head' => $held->contains(fn (object $row) => (bool) $row->is_head),
                'by_hand' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->values();

        DB::table('department_user')->insert($rows->all());

        $this->say(
            'Отделы, записанные по должности — сотрудник занимает в них должность, а в списке отдела его не было',
            $implied
                ->map(fn (object $row) => "{$row->surname} {$row->name} — {$row->department} (должность «{$row->position}»)")
                ->all()
        );
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
     * The column goes and every row reads the same way again.
     *
     * The memberships this migration wrote stay, with the six people in the
     * departments they hold a position in. Which rows those were is not
     * recorded anywhere — the column said where a row came from, not when —
     * and a down() that guessed would delete somebody's real filing to undo a
     * correction. They were true before this ran and they are true after it:
     * the card was already showing those departments by reading through the
     * position. Somebody who really wants them gone has to say which.
     */
    public function down(): void
    {
        if (! Schema::hasColumn('department_user', 'by_hand')) {
            return;
        }

        Schema::table('department_user', fn (Blueprint $table) => $table->dropColumn('by_hand'));
    }
};
