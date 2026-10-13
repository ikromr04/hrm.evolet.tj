<?php

use Database\Seeders\PositionSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A position belongs to a department. It used to belong to the company as a
     * whole — one «Аналитик» row held by people sitting in two departments — and
     * the work of the two is not the same work, which is why the duties had to
     * name a department to tell them apart. The pair is the real thing, so the
     * position carries the department and its name only has to be unique inside
     * one: «Аналитик» may exist in two departments, never twice in one.
     *
     * Nullable, because the data cannot always say where a position belongs: one
     * nobody holds names no department by itself, and the forms, not the column,
     * are where a department becomes required from now on.
     *
     * A department that is deleted leaves its positions behind rather than taking
     * them: a position is a directory entry with holders and duties of its own,
     * and losing a department is not a reason to lose those. It reads as unplaced
     * afterwards and somebody re-files it — the same way a sub-department comes
     * loose when its parent goes.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('positions', 'department_id')) {
            Schema::table('positions', function (Blueprint $table) {
                $table->foreignId('department_id')->nullable()->after('id')->constrained()->nullOnDelete();
            });
        }

        // The name has to stop being unique before the positions held in several
        // departments can be split into one per department.
        if (Schema::hasIndex('positions', 'positions_name_unique')) {
            Schema::table('positions', fn (Blueprint $table) => $table->dropUnique('positions_name_unique'));
        }

        $this->placeEveryPosition();

        if (! Schema::hasIndex('positions', 'positions_department_id_name_unique')) {
            Schema::table('positions', fn (Blueprint $table) => $table->unique(['department_id', 'name']));
        }
    }

    /**
     * Every position gets the department its own evidence points at, and the ones
     * that point at several are split into a position per department.
     *
     * A position is placed by what the seeder says it leads, if it says anything:
     * the head of a department leads the one it is named after whoever happens to
     * hold the title, and that is firmer than reading the holders. Otherwise the
     * departments its holders sit in are the answer — one of them and the
     * position simply gets it, several and there is a position per department,
     * same name and the same duties copied into each, with every holder moved to
     * the one for the department they sit in.
     *
     * Nothing is thrown away: a holder the data cannot place, and a position with
     * no holders to read, keep what they have and are reported rather than
     * guessed at.
     */
    private function placeEveryPosition(): void
    {
        $departments = DB::table('departments')->pluck('id', 'name');

        // A run that stopped half way left the positions it had already placed
        // with a department, so only the ones still unplaced are picked up —
        // and a name is read as a whole, copies of it included, so the
        // departments come out the same on a second pass as on the first.
        $pending = DB::table('positions')->whereNull('department_id')->orderBy('id')->get(['id', 'name']);
        $unplaced = [];
        $undecided = [];

        foreach ($pending as $position) {
            $family = DB::table('positions')->where('name', $position->name)->pluck('id');
            $holders = DB::table('position_user')->whereIn('position_id', $family)->distinct()->pluck('user_id');

            $leads = PositionSeeder::HEADS[$position->name] ?? null;
            $wanted = $leads !== null && isset($departments[$leads])
                ? collect([$departments[$leads]])
                : DB::table('department_user')->whereIn('user_id', $holders)->distinct()->orderBy('department_id')->pluck('department_id');

            if ($wanted->isEmpty()) {
                $unplaced[] = $position->name;

                continue;
            }

            foreach ($this->spread($position, $wanted, $holders) as $userId) {
                $undecided[] = "{$position->name}: #{$userId}";
            }
        }

        $this->say('Должности без отдела', $unplaced);
        $this->say('Сотрудники, которых не удалось распределить', $undecided);
    }

    /**
     * What the data could not decide, said out loud while the migration runs:
     * these are the rows somebody has to look at, and a migration that fixed
     * them quietly would be guessing.
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
     * The position keeps the first of its departments and gets a copy of itself
     * for each of the rest; then every holder is moved to the copy for their own
     * department. The first department is written last, so a run that stops in
     * the middle leaves the position unplaced and the next one picks it up again
     * and finds the copies it had already made.
     *
     * @param  Collection<int, int>  $wanted  the departments this position is held in
     * @param  Collection<int, int>  $holders
     * @return list<int> the holders the data could not place
     */
    private function spread(object $position, Collection $wanted, Collection $holders): array
    {
        $byDepartment = [$wanted->first() => $position->id];
        $undecided = [];

        foreach ($wanted->skip(1) as $departmentId) {
            $byDepartment[$departmentId] = $this->copy($position, $departmentId);
        }

        foreach ($holders as $userId) {
            $own = DB::table('department_user')
                ->where('user_id', $userId)
                ->whereIn('department_id', $wanted)
                ->pluck('department_id');

            // Sitting nowhere, or in two of the departments the position was
            // held in, is not something to decide for somebody: they stay on
            // the copy they are already on and are reported.
            if ($own->count() !== 1) {
                $undecided[] = $userId;

                continue;
            }

            DB::table('position_user')
                ->whereIn('position_id', array_values($byDepartment))
                ->where('user_id', $userId)
                ->update(['position_id' => $byDepartment[$own->first()], 'updated_at' => now()]);
        }

        DB::table('positions')->where('id', $position->id)->update([
            'department_id' => $wanted->first(),
            'updated_at' => now(),
        ]);

        return $undecided;
    }

    /**
     * The same position in another department: the name as it stands and the
     * duties as they stand, because the holders there answer for the same work
     * until somebody says otherwise. One already there — from a run that stopped
     * half way — is used as it is rather than made twice.
     */
    private function copy(object $position, int $departmentId): int
    {
        $existing = DB::table('positions')
            ->where('name', $position->name)
            ->where('department_id', $departmentId)
            ->value('id');

        if ($existing !== null) {
            return $existing;
        }

        $now = now();
        $id = DB::table('positions')->insertGetId([
            'department_id' => $departmentId,
            'name' => $position->name,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $duties = DB::table('position_duties')
            ->where('position_id', $position->id)
            ->orderBy('order')
            ->orderBy('id')
            ->get(['name', 'order'])
            ->map(fn (object $duty) => [
                'position_id' => $id,
                'name' => $duty->name,
                'order' => $duty->order,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

        if ($duties->isNotEmpty()) {
            DB::table('position_duties')->insert($duties->all());
        }

        return $id;
    }

    /**
     * The column goes and the name is free again — but the two are not the whole
     * of what happened here, and the rest does not come back.
     *
     * The copies this migration made stay, with their duties and their holders:
     * which of the positions sharing a name was the original is not written down
     * anywhere, and merging them back by name would move people onto a row
     * picked at random. For the same reason the old unique index on the name is
     * not restored — the copies would collide on it and the migration would stop
     * dead. Somebody who really wants one «Аналитик» again has to say which one.
     */
    public function down(): void
    {
        if (! Schema::hasColumn('positions', 'department_id')) {
            return;
        }

        Schema::table('positions', function (Blueprint $table) {
            // The key goes before the index, because on MySQL the key is held up
            // by it: a foreign key needs an index to read through, and this is
            // the only one it has. SQLite has no way of dropping a key and no
            // need to.
            if (Schema::getConnection()->getDriverName() !== 'sqlite') {
                $table->dropForeign(['department_id']);
            }

            if (Schema::hasIndex('positions', 'positions_department_id_name_unique')) {
                $table->dropUnique('positions_department_id_name_unique');
            }

            $table->dropColumn('department_id');
        });
    }
};
