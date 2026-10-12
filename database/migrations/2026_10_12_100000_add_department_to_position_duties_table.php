<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An analyst in one department does not do what an analyst in another does,
     * and until now a duty belonged to the position alone, so the two had to
     * share one list. The work really belongs to the pair — a position in a
     * department — so a duty may name the department it is done in.
     *
     * Nullable, because that is the duty every holder of the position carries
     * wherever they sit, which is what every row already on file is: the duties
     * written so far stay as they are and become the common ones, so nothing is
     * lost and nothing has to be sorted out by hand.
     *
     * Cascading, because a duty that names a department is about that department
     * and means nothing without it: when the department goes its duties go with
     * it, and the common ones of the same position stay.
     */
    public function up(): void
    {
        Schema::table('position_duties', function (Blueprint $table) {
            $table->foreignId('department_id')->nullable()->after('position_id')->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('position_duties', function (Blueprint $table) {
            // MySQL will not let the column go while the key still points
            // through it; SQLite, which the suite runs on, has no way of
            // dropping one and no need to.
            if (Schema::getConnection()->getDriverName() !== 'sqlite') {
                $table->dropForeign(['department_id']);
            }

            $table->dropColumn('department_id');
        });
    }
};
