<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Citizenship used to be one line typed into the card, with commas between
     * the countries of someone who holds several. It becomes a directory of its
     * own, like the languages, and a person is linked to any number of its
     * entries. What was typed is split on commas and semicolons, and each
     * country goes into the directory once, whatever case it was written in.
     */
    public function up(): void
    {
        Schema::create('citizenships', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });

        Schema::create('citizenship_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('citizenship_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['citizenship_id', 'user_id']);
        });

        $ids = [];
        $now = now();

        DB::table('user_details')->whereNotNull('citizenship')->orderBy('id')->each(function (object $row) use (&$ids, $now) {
            foreach (preg_split('/[,;]/u', $row->citizenship) as $country) {
                $country = trim($country);
                if ($country === '') {
                    continue;
                }

                $key = mb_strtolower($country);
                $ids[$key] ??= DB::table('citizenships')->insertGetId(['name' => $country, 'created_at' => $now, 'updated_at' => $now]);

                DB::table('citizenship_user')->insertOrIgnore([
                    'citizenship_id' => $ids[$key],
                    'user_id' => $row->user_id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        });

        Schema::table('user_details', function (Blueprint $table) {
            $table->dropColumn('citizenship');
        });
    }

    /**
     * Back to one line on the card, the countries joined with commas.
     */
    public function down(): void
    {
        Schema::table('user_details', function (Blueprint $table) {
            $table->string('citizenship')->nullable()->after('birth_place');
        });

        DB::table('citizenship_user')
            ->join('citizenships', 'citizenships.id', '=', 'citizenship_user.citizenship_id')
            ->orderBy('citizenship_user.id')
            ->get(['citizenship_user.user_id', 'citizenships.name'])
            ->groupBy('user_id')
            ->each(fn ($rows, $userId) => DB::table('user_details')
                ->where('user_id', $userId)
                ->update(['citizenship' => $rows->pluck('name')->implode(', ')]));

        Schema::dropIfExists('citizenship_user');
        Schema::dropIfExists('citizenships');
    }
};
