<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a request changed, kept on the request once it has been answered.
 *
 * The page that reads a request builds its table by comparing the sheet that
 * was sent with the card as it stands. That is the right comparison while the
 * sheet is waiting — the card may have been corrected by the office in the
 * meantime, and the question is «is anything still being asked for» — but it
 * stops being true the moment somebody presses «Принять»: the sheet is written
 * onto the card, the card then says exactly what the sheet said, and the page
 * answers «без изменений» to every line of a request that changed seven of
 * them. Whoever accepted it is left looking at a record of nothing.
 *
 * So the comparison is made once, against the card as it was, and kept. Filled
 * on the decision — both decisions, because a refusal's reading is a record
 * too and a card corrected afterwards would move it the same way. Null on the
 * rows that were answered before this column existed, and on every request
 * still waiting: the page compares live where there is nothing kept, which is
 * what it has always done.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sheet_change_requests', function (Blueprint $table) {
            $table->json('lines')->nullable()->after('papers');
        });
    }

    public function down(): void
    {
        Schema::table('sheet_change_requests', function (Blueprint $table) {
            $table->dropColumn('lines');
        });
    }
};
