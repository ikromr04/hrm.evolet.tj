<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Four roles, and nothing else.
 *
 * Roles used to be a directory the company kept: it added them, named them and
 * ticked what each one opened. That answered a question nobody had settled —
 * what a head of department should see of somebody else's card — by asking
 * whoever was at the keyboard. So the question goes back where it belongs, and
 * the roles become the four the company actually has: a system administrator,
 * personnel, a head, and everybody else.
 *
 * What happens to the people already on file:
 *
 *  - the system administrator keeps the role and loses any other it carried;
 *  - whoever holds a leading position becomes «Руководитель», because that flag
 *    is on the job and is the only record of who leads anything;
 *  - everybody else becomes «Специалист».
 *
 * Then the rows for roles outside the four go, and with them — by the keys the
 * tables already carry — the people hung on them and the rights they held. On
 * the live database there is nothing there to lose: one role was ever created
 * on it, and it is the first of the four.
 *
 * Written as a migration of its own and not as a change to the one that made
 * the tables, because that one has run on the live database and a migration
 * that has run is a record of what happened. This records what happens next.
 */
return new class extends Migration
{
    /**
     * The four, written out here rather than read from App\Support\Access.
     *
     * A migration that has run is a record of what happened, and what happened
     * is that these four names were put in the table. Should the catalogue in the
     * code be renamed one day, this file must go on describing the day it ran.
     */
    private const ROLES = [
        'sysadmin' => 'Системный администратор',
        'hr' => 'HR-специалист',
        'head' => 'Руководитель',
        'specialist' => 'Специалист',
    ];

    public function up(): void
    {
        $now = now();
        $type = (new User)->getMorphClass();

        // The four, keeping the name of any that is already there: the one role
        // the live database has may have been renamed since, and that name is
        // the company's to choose.
        $ids = [];

        foreach (self::ROLES as $name => $title) {
            $ids[$name] = DB::table('roles')->where('name', $name)->where('guard_name', 'web')->value('id')
                ?? DB::table('roles')->insertGetId([
                    'name' => $name,
                    'title' => $title,
                    'guard_name' => 'web',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
        }

        // Who holds what, worked out before anything is written: whoever has the
        // one role keeps it, and leading a department is a question about the
        // positions held rather than about the card.
        $sysadmins = DB::table('model_has_roles')
            ->where('role_id', $ids['sysadmin'])
            ->where('model_type', $type)
            ->pluck('model_id')
            ->all();

        $heads = DB::table('position_user')
            ->join('positions', 'positions.id', '=', 'position_user.position_id')
            ->where('positions.is_head', true)
            ->distinct()
            ->pluck('position_user.user_id')
            ->all();

        DB::table('model_has_roles')->where('model_type', $type)->delete();

        DB::table('users')->orderBy('id')->chunk(500, function ($users) use ($ids, $type, $sysadmins, $heads) {
            $rows = [];

            foreach ($users as $user) {
                $role = match (true) {
                    in_array($user->id, $sysadmins) => 'sysadmin',
                    in_array($user->id, $heads) => 'head',
                    default => 'specialist',
                };

                $rows[] = ['role_id' => $ids[$role], 'model_type' => $type, 'model_id' => $user->id];
            }

            if ($rows !== []) {
                DB::table('model_has_roles')->insert($rows);
            }
        });

        DB::table('roles')->whereNotIn('name', array_keys(self::ROLES))->delete();
    }

    /**
     * There is no way back, and this says so rather than pretending.
     *
     * A reversal would have to put back role rows this deleted, the people who
     * were hung on them and the rights each one carried — and none of that is
     * anywhere to be read once it is gone. Rolling back leaves the four roles
     * standing, which is a working system, only not the one that was here
     * before.
     */
    public function down(): void
    {
        //
    }
};
