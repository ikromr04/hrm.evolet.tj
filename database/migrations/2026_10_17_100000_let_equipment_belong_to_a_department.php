<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Some hardware is nobody's in particular. A printer in the accounts
     * office, the router in the ceiling, the air conditioner on the wall: the
     * department answers for them, and there is no colleague to sign for one.
     * Until now every unit out of the stock was signed out to a person, so
     * those things were either filed under whoever happened to set them up or
     * left on the balance sheet, where they read as spare.
     *
     * Which of the two it is belongs to the handover and not to the category:
     * a printer may well sit on one person's desk, and a department keeps a
     * spare laptop for whoever needs one. So nothing is marked in advance —
     * whoever hands a unit over says who gets it, a colleague or a department,
     * and both answers are open to every unit of every category.
     *
     * The holder is a department of either level, because that is how the
     * company speaks of such things — «принтер ДАС», «роутер отдела
     * аналитики» — and because the equipment table already reads a unit under
     * a department and the unit inside it. So the unit's own row gains a
     * department beside the colleague it may be with, and the two are kept
     * apart: a unit is held by one or by the other, never by both, and the
     * database says so itself below.
     */
    public function up(): void
    {
        Schema::table('equipment', function (Blueprint $table) {
            // The department a unit is signed out to, empty for everything
            // signed out to a person and for everything on the balance sheet.
            //
            // The key lets go rather than cascading: a department that is
            // dissolved does not take the company's printers with it. They
            // fall back to holding nobody, which is what the status column
            // beside this one has always meant by an empty holder, and
            // whoever keeps the fleet signs them out again.
            if (! Schema::hasColumn('equipment', 'holder_department_id')) {
                $table->foreignId('holder_department_id')->nullable()->after('holder_user_id')->constrained('departments')->nullOnDelete();
            }
        });

        $this->oneHolder();
    }

    public function down(): void
    {
        $this->withoutOneHolder();

        Schema::table('equipment', function (Blueprint $table) {
            // A column carrying a foreign key does not come off on SQLite:
            // the key lives inside the table's own definition there, and the
            // driver refuses to rebuild the table around it. The suite builds
            // its database forward from nothing, so this is only ever asked of
            // MySQL — where the key goes with the column — and up() finds
            // whatever a rollback on SQLite happened to leave behind.
            if (Schema::getConnection()->getDriverName() !== 'sqlite') {
                $table->dropConstrainedForeignId('holder_department_id');
            }
        });
    }

    /** What a row that breaks the rule is told, in either database. */
    private const ONE_HOLDER = 'Оборудование не может числиться и за сотрудником, и за отделом.';

    /**
     * The rule the database keeps itself: a unit is held by a colleague or by a
     * department, and never by both at once.
     *
     * It is the invariant a pair of columns cannot carry, and the one that
     * matters: a unit standing against two names would be counted twice, shown
     * twice and asked back from two people, and no reading of the fleet could
     * say which of the two was wrong. Whether a category's units may be held by
     * a department at all is a rule about meaning, and the model keeps that
     * one; this keeps the shape, so no code path, no seeder and no console
     * session can write a row that belongs to a person and a department
     * together.
     *
     * The documents' owner rule next door is a `check` on MySQL and a pair of
     * triggers only on SQLite. This one is triggers on both, because MySQL will
     * not have it the other way: a column named in a foreign key that sets it
     * null on delete may not also be named in a check — the key writes that
     * column itself, and the server refuses to be held to a condition over a
     * value it is about to assign. The key is the half worth keeping (a
     * dissolved department must not take the printers with it), so the rule
     * takes the shape the table leaves for it. The two triggers say exactly
     * what the check would have said, at exactly the moments it would have
     * been asked.
     */
    private function oneHolder(): void
    {
        $sqlite = Schema::getConnection()->getDriverName() === 'sqlite';
        $held = 'new.holder_user_id is not null and new.holder_department_id is not null';
        $message = self::ONE_HOLDER;

        foreach (['insert', 'update'] as $when) {
            if ($this->triggers("equipment_one_holder_on_{$when}") > 0) {
                continue;
            }

            $refuse = $sqlite
                ? "for each row when {$held} begin select raise(abort, '{$message}'); end"
                : "for each row begin if {$held} then signal sqlstate '45000' set message_text = '{$message}'; end if; end";

            DB::statement("create trigger equipment_one_holder_on_{$when} before {$when} on equipment {$refuse}");
        }
    }

    /** The same rule taken off again. */
    private function withoutOneHolder(): void
    {
        DB::statement('drop trigger if exists equipment_one_holder_on_insert');
        DB::statement('drop trigger if exists equipment_one_holder_on_update');
    }

    /**
     * Whether the rule is already on the table, so that a migration run twice —
     * rolled back on SQLite, where the column stays, and run forward again —
     * does not try to write what the table already carries. Asked of the
     * catalogue rather than written as "if not exists", which MySQL learned
     * only recently and SQLite spells its own way.
     */
    private function triggers(string $name): int
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return (int) DB::table('sqlite_master')->where('type', 'trigger')->where('name', $name)->count();
        }

        return (int) DB::table('information_schema.triggers')
            ->whereRaw('trigger_schema = database()')
            ->where('trigger_name', $name)
            ->count();
    }
};
