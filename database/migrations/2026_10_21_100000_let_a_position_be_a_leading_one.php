<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A position can be a leading one of its department.
     *
     * Who leads a department has been written on the person until now — the
     * is_head flag of the row saying they belong to it — and that is the wrong
     * place for it. Somebody leads a department because they hold its leading
     * job, and when the job is taken away from them they stop leading it; told
     * on the person instead, the two facts drift apart, and the directory has
     * to be asked to keep them together by hand.
     *
     * So the flag moves onto the job: «Руководитель отдела дизайна» is the
     * leading position of «Отдел Дизайна», and whoever holds it heads the
     * department. Several are allowed — two departments are led by two people
     * today, and a company that works that way is not an error to be refused.
     *
     * Only the column and its answer for the positions already on file are
     * written here. Nothing reads it yet.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('positions', 'is_head')) {
            Schema::table('positions', function (Blueprint $table) {
                $table->boolean('is_head')->default(false)->after('name');
            });
        }

        $this->markWhatTheHeadsPointAt();
    }

    /**
     * Every head on file points at the position they hold in the department
     * they lead, and that position is marked — but only where the pointing is
     * unambiguous.
     *
     * Two things make it ambiguous, and both of them are in the data:
     *
     * A head who holds two positions in their own department says nothing
     * about which of the two leads it. One head holds «Тех аналитик-дизайнер»
     * and «Младший специалист отдела финансов» in the department she heads,
     * and neither name answers the question.
     *
     * A position held by a head and by somebody else is not the head's to
     * speak for. «Видеомейкер» is held by a head and by a colleague who is not
     * one, so marking it would quietly make that colleague a head too — and
     * making somebody a head is not a thing to do by inference.
     *
     * Neither is guessed at. The rows stay as they are, the column stays false
     * for them, and they are named on the way out so that somebody can decide
     * what they are. Until somebody does, those departments keep the heads
     * they already have: the memberships are untouched by this migration, and
     * the one place that works membership out from positions leaves a head it
     * cannot account for alone.
     */
    private function markWhatTheHeadsPointAt(): void
    {
        $leading = [];
        $undecided = [];

        $heads = DB::table('department_user')
            ->join('departments', 'departments.id', '=', 'department_user.department_id')
            ->join('users', 'users.id', '=', 'department_user.user_id')
            ->where('department_user.is_head', true)
            ->orderBy('departments.name')
            ->orderBy('users.surname')
            ->get([
                'department_user.department_id',
                'department_user.user_id',
                'departments.name as department',
                'users.name',
                'users.surname',
            ]);

        foreach ($heads as $head) {
            $who = "{$head->surname} {$head->name} — {$head->department}";

            $own = DB::table('position_user')
                ->join('positions', 'positions.id', '=', 'position_user.position_id')
                ->where('position_user.user_id', $head->user_id)
                ->where('positions.department_id', $head->department_id)
                ->orderBy('positions.name')
                ->get(['positions.id', 'positions.name']);

            if ($own->count() !== 1) {
                $undecided[] = $own->isEmpty()
                    ? "{$who}: не занимает должность в этом отделе"
                    : "{$who}: занимает несколько должностей в этом отделе — ".$own->pluck('name')->implode(', ');

                continue;
            }

            $position = $own->first();
            $holders = DB::table('position_user')->where('position_id', $position->id)->count();

            if ($holders > 1) {
                $others = DB::table('position_user')
                    ->join('users', 'users.id', '=', 'position_user.user_id')
                    ->where('position_user.position_id', $position->id)
                    ->where('position_user.user_id', '!=', $head->user_id)
                    ->orderBy('users.surname')
                    ->get(['users.name', 'users.surname'])
                    ->map(fn (object $other) => "{$other->surname} {$other->name}")
                    ->implode(', ');

                $undecided[] = "{$who}: должность «{$position->name}» занимает не только руководитель — ещё {$others}";

                continue;
            }

            DB::table('positions')->where('id', $position->id)->update(['is_head' => true, 'updated_at' => now()]);
            $leading[] = "{$position->name} — {$head->department}";
        }

        $this->say('Должности, отмеченные руководящими', $leading);
        $this->say('Руководители, за которых нельзя решить — отмечены вручную', $undecided);
    }

    /**
     * What the migration did and what it refused to decide, said out loud while
     * it runs: the second list is the rows somebody has to look at, and a
     * migration that filled them in quietly would be guessing.
     *
     * @param  list<string>  $lines
     */
    private function say(string $what, array $lines): void
    {
        if ($lines !== []) {
            echo PHP_EOL.$what.' ('.count($lines).'):'.PHP_EOL.'  '.implode(PHP_EOL.'  ', $lines).PHP_EOL;
        }
    }

    /**
     * The column goes, and with it every answer written above.
     *
     * Nothing has to be put back: who leads a department is still written on
     * the membership rows, which this migration does not touch, so rolling it
     * back leaves the company exactly as it was before the flag existed. Any
     * position somebody marked leading by hand after the fact is lost with the
     * column, because there is nowhere else to keep it.
     */
    public function down(): void
    {
        if (! Schema::hasColumn('positions', 'is_head')) {
            return;
        }

        Schema::table('positions', fn (Blueprint $table) => $table->dropColumn('is_head'));
    }
};
