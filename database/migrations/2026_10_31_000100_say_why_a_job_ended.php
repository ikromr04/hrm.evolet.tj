<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Why a previous job ended — part 06 of the sheet, which prints the reason
 * beside the dates and had nothing to print.
 *
 * Asked for only of a job that ended: somebody who still works there has no
 * reason to give, and a card that insisted on one would be asking them to
 * invent it. Written out rather than chosen from a list, because a labour book
 * says what it says — «по собственному желанию», «перевод на другую работу» —
 * and a company meets a new wording every other year.
 *
 * The country the work was done in stops being asked for in the same breath.
 * The column stays, holding what was written in it before, and only learns to
 * be empty: a card filed from now on says nothing about it, and dropping it
 * would throw away what the cards already on file do say. See the create page,
 * which no longer offers the field.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_work_experiences', function (Blueprint $table) {
            $table->string('reason', 200)->nullable()->after('ended_year');
            $table->string('country', 100)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('user_work_experiences', function (Blueprint $table) {
            $table->dropColumn('reason');
        });

        // The country is left as it is. A column that has learned to be empty
        // cannot be made to forget it again without inventing a country for
        // every row written since, and a migration that invented data on the
        // way down would be a migration nobody dared run.
    }
};
