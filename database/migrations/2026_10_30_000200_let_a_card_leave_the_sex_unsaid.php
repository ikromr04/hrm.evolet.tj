<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A card may be filled in without saying whether the person is a man or a
 * woman.
 *
 * The column was written as an enum of two values and nothing else, so the
 * question had to be answered before a colleague could be put on the books at
 * all. It is one line of a card like any other now: answered where it is known,
 * left empty where it is not.
 *
 * Through the schema builder and not a hand-written ALTER, because the suite
 * runs this against sqlite while the company runs it against MySQL, and the two
 * do not spell the change the same way. The list of values is restated here
 * because a change to a column states the column whole — which is exactly why
 * it is written out in full rather than guessed at.
 *
 * Nothing is rewritten: every row on file already says male or female, and they
 * keep saying it. Only the refusal to accept nothing is lifted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('sex', ['male', 'female'])->nullable()->change();
        });
    }

    /**
     * Back to a required column.
     *
     * Which can only be done while nobody is standing on the freedom it takes
     * away: a card left empty since would have no value to put back, and the
     * database would be free to invent one. So the rows are counted first, and
     * the reversal refuses rather than deciding somebody's sex for them.
     */
    public function down(): void
    {
        $unsaid = DB::table('users')->whereNull('sex')->count();

        if ($unsaid > 0) {
            throw new RuntimeException(
                "{$unsaid} cards leave the sex unsaid; fill them in before making the column required again.",
            );
        }

        Schema::table('users', function (Blueprint $table) {
            $table->enum('sex', ['male', 'female'])->nullable(false)->change();
        });
    }
};
