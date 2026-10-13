<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A duty named the department it was done in because one position had to
     * serve every department that held it. A position now belongs to one
     * department of its own, so the duty already knows where it is done by
     * knowing whose duty it is, and the column says the same thing twice.
     *
     * The duties written against a department keep their wording and move over
     * to that position's own list, which is where they were heading anyway.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('position_duties', 'department_id')) {
            return;
        }

        // SQLite, which the suite runs on, cannot drop a foreign key and will
        // not drop a column one still names, so there the table is written out
        // again without it. Everywhere else the key goes first and the column
        // after it, which is all MySQL asks for.
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            $this->rewriteWithoutTheColumn();

            return;
        }

        Schema::table('position_duties', function (Blueprint $table) {
            $table->dropForeign(['department_id']);
            $table->dropColumn('department_id');
        });
    }

    /**
     * The same table minus the one column, with every duty carried across under
     * the id it already has — the pages and the employees' own duties refer to
     * duties by id.
     */
    private function rewriteWithoutTheColumn(): void
    {
        Schema::create('position_duties_rewritten', function (Blueprint $table) {
            $table->id();
            $table->foreignId('position_id')->constrained()->cascadeOnDelete();
            $table->string('name', 300);
            $table->unsignedSmallInteger('order')->default(0);
            $table->timestamps();
        });

        $columns = ['id', 'position_id', 'name', 'order', 'created_at', 'updated_at'];

        DB::table('position_duties_rewritten')->insertUsing(
            $columns,
            DB::table('position_duties')->select($columns),
        );

        Schema::drop('position_duties');
        Schema::rename('position_duties_rewritten', 'position_duties');
    }

    /**
     * The column comes back, and comes back empty: which department a duty was
     * written for is not recoverable once the position itself names one, so
     * every duty reads as common again.
     */
    public function down(): void
    {
        if (Schema::hasColumn('position_duties', 'department_id')) {
            return;
        }

        Schema::table('position_duties', function (Blueprint $table) {
            $table->foreignId('department_id')->nullable()->after('position_id')->constrained()->cascadeOnDelete();
        });
    }
};
