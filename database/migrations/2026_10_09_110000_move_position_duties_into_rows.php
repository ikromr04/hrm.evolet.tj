<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What a position is responsible for used to be one block of text typed into
     * the directory. There is never only one duty, and the pages read them one by
     * one — a position's own page highlights, on every holder, the duties that
     * belong to it — so they become rows, as the citizenships and the languages
     * did, and each position keeps them in the order they were entered.
     *
     * The text on file is carried over: it is split on newlines and semicolons and
     * on nothing else, because a duty is a sentence and a sentence has commas in
     * it.
     */
    public function up(): void
    {
        // A run that failed half way leaves its table behind — MySQL cannot take a
        // CREATE TABLE back — and is not recorded as done, so the next run would
        // stop at "already exists". Nothing else ever made this table.
        Schema::dropIfExists('position_duties');

        Schema::create('position_duties', function (Blueprint $table) {
            // Spelled out: a host whose default is MyISAM caps a key at 1000 bytes,
            // which a 255-character utf8mb4 column overruns, and drops the foreign
            // key below without a word. Three hundred characters is also what the
            // form accepts for one duty.
            $table->engine('InnoDB');
            $table->id();
            $table->foreignId('position_id')->constrained()->cascadeOnDelete();
            $table->string('name', 300);
            // The order they were entered in, which is the order they are read in.
            // Called "order" rather than "position", which this table already uses
            // for the position the duty belongs to.
            $table->unsignedSmallInteger('order')->default(0);
            $table->timestamps();
        });

        $now = now();

        DB::table('positions')->whereNotNull('duties')->orderBy('id')->each(function (object $row) use ($now) {
            $order = 0;

            foreach (preg_split('/[\r\n;]+/u', $row->duties) as $duty) {
                $duty = trim($duty);

                if ($duty === '') {
                    continue;
                }

                DB::table('position_duties')->insert([
                    'position_id' => $row->id,
                    'name' => mb_substr($duty, 0, 300),
                    'order' => $order++,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        });

        Schema::table('positions', function (Blueprint $table) {
            $table->dropColumn('duties');
        });
    }

    /**
     * Back to one block of text in the directory, the duties joined with newlines
     * — which is how they were written there in the first place.
     */
    public function down(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->text('duties')->nullable()->after('name');
        });

        DB::table('position_duties')
            ->orderBy('order')
            ->orderBy('id')
            ->get(['position_id', 'name'])
            ->groupBy('position_id')
            ->each(fn ($rows, $positionId) => DB::table('positions')
                ->where('id', $positionId)
                ->update(['duties' => $rows->pluck('name')->implode("\n")]));

        Schema::dropIfExists('position_duties');
    }
};
