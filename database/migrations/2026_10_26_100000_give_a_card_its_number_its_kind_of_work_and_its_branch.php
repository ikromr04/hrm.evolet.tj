<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Three lines «Основное» asks for and the card had no answer to: the number
     * a person is filed under, the kind of work they were taken on for and the
     * branch they work at.
     *
     * The number is the company's own, not ours: it may be typed in, it may be
     * left empty, and it is written in at least five digits. Nobody types one
     * for somebody just added, so a card starts with its id in those five —
     * 00001, 00002 — and every card already on the books is given the same now.
     *
     * The other two are directories, like the citizenships and the languages:
     * what they hold is the company's to write down and rename. A person has
     * one of each or neither, so these are two columns on the account rather
     * than two tables between — and deleting an entry leaves the cards that
     * named it standing, with that one line empty.
     */
    public function up(): void
    {
        // A run that failed half way leaves its tables behind — MySQL cannot take
        // a CREATE TABLE back — and is not recorded as done, so the next run
        // would stop at "already exists". Nothing else ever made these two.
        foreach (['employment_type_id', 'branch_id', 'employee_number'] as $column) {
            if (Schema::hasColumn('users', $column)) {
                Schema::table('users', function (Blueprint $table) use ($column) {
                    // The key goes with the column; a plain index does not.
                    if ($column !== 'employee_number') {
                        $table->dropForeign([$column]);
                    }

                    $table->dropColumn($column);
                });
            }
        }

        Schema::dropIfExists('employment_types');
        Schema::dropIfExists('branches');

        foreach (['employment_types', 'branches'] as $list) {
            Schema::create($list, function (Blueprint $table) {
                // Spelled out: a host whose default is MyISAM caps a key at 1000
                // bytes, which a 255-character utf8mb4 name overruns, and drops
                // the foreign keys below without a word. A hundred characters is
                // also what the directory and the card accept.
                $table->engine('InnoDB');
                $table->id();
                $table->string('name', 100)->unique();
                $table->timestamps();
            });
        }

        Schema::table('users', function (Blueprint $table) {
            // Not a number as the database counts: it is written with its
            // leading noughts, and nothing is ever added to it.
            $table->string('employee_number', 20)->nullable()->unique()->after('patronymic');
            // An entry that goes out of the directory takes nothing with it: the
            // cards that named it simply stop naming anything.
            $table->foreignId('employment_type_id')->nullable()->after('sex')->constrained()->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->after('employment_type_id')->constrained()->nullOnDelete();
        });

        // Everybody on the books gets the number their id spells, which is what
        // a card added from now on is given the moment it is created. Padded
        // here rather than by the database: LPAD is MySQL's own, and the tests
        // run this migration on sqlite.
        DB::table('users')->orderBy('id')->pluck('id')->each(
            fn (int $id) => DB::table('users')->where('id', $id)->update(['employee_number' => User::numberOf($id)]),
        );
    }

    /**
     * The three lines go, and the two lists with them: what was written in them
     * is the company's, and there is nowhere else to put it back to.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['employment_type_id']);
            $table->dropForeign(['branch_id']);
            $table->dropColumn(['employee_number', 'employment_type_id', 'branch_id']);
        });

        Schema::dropIfExists('employment_types');
        Schema::dropIfExists('branches');
    }
};
