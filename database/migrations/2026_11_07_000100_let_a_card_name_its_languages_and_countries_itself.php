<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The languages, the countries and the kinds of work, written on the card
     * itself instead of pointed at in a directory.
     *
     * These three were lists the company kept, with a page each for renaming
     * and striking off. They are now what the rest of the sheet's answers are:
     * a word on one person's card, offered to the next person from what has
     * been written on the cards already. What a directory gave — one place to
     * rename an entry for everybody, a count of who stands behind it, a refusal
     * to delete one in use — goes with them.
     *
     * The level a language is spoken at stays where it was, beside the language
     * it belongs to: it is an answer about one person, which is why it was in
     * the link and not in the directory.
     *
     * Nothing anybody has written is lost: every link is copied across by name
     * before the lists are dropped.
     */
    public function up(): void
    {
        Schema::create('user_languages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->enum('level', ['beginner', 'intermediate', 'advanced']);
            $table->timestamps();

            // One line per language per person, as the link was: a list that
            // held two levels for one language would be a card answering the
            // same question twice.
            $table->unique(['user_id', 'name']);
        });

        Schema::create('user_citizenships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->timestamps();

            $table->unique(['user_id', 'name']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('employment_type', 100)->nullable()->after('employee_number');
        });

        // What is on file, copied across by name before the lists holding the
        // names are dropped. Through the builder rather than as SQL of its own,
        // because the same migration runs against MySQL here and SQLite under
        // the tests, and an «UPDATE … JOIN» is MySQL's alone.
        DB::table('user_languages')->insertUsing(
            ['user_id', 'name', 'level', 'created_at', 'updated_at'],
            DB::table('language_user')
                ->join('languages', 'languages.id', '=', 'language_user.language_id')
                ->select('language_user.user_id', 'languages.name', 'language_user.level', 'language_user.created_at', 'language_user.updated_at'),
        );

        DB::table('user_citizenships')->insertUsing(
            ['user_id', 'name', 'created_at', 'updated_at'],
            DB::table('citizenship_user')
                ->join('citizenships', 'citizenships.id', '=', 'citizenship_user.citizenship_id')
                ->select('citizenship_user.user_id', 'citizenships.name', 'citizenship_user.created_at', 'citizenship_user.updated_at'),
        );

        // A handful of rows, so one update apiece rather than a join.
        foreach (DB::table('employment_types')->get(['id', 'name']) as $kind) {
            DB::table('users')->where('employment_type_id', $kind->id)->update(['employment_type' => $kind->name]);
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['employment_type_id']);
            $table->dropColumn('employment_type_id');
        });

        Schema::dropIfExists('language_user');
        Schema::dropIfExists('citizenship_user');
        Schema::dropIfExists('languages');
        Schema::dropIfExists('citizenships');
        Schema::dropIfExists('employment_types');
    }

    /**
     * The three lists again, built from what the cards say.
     *
     * A directory made this way is the same directory it was unless somebody
     * spelled an entry a second way in the meantime, in which case it comes
     * back as two — which is the whole of what a directory was for.
     */
    public function down(): void
    {
        foreach (['languages', 'citizenships', 'employment_types'] as $list) {
            Schema::create($list, function (Blueprint $table) {
                $table->id();
                $table->string('name', 100)->unique();
                $table->timestamps();
            });
        }

        $kept = fn (string $list, Collection $names) => $names->isEmpty() || DB::table($list)->insert(
            $names->map(fn (string $name) => ['name' => $name, 'created_at' => now(), 'updated_at' => now()])->all(),
        );

        $kept('languages', DB::table('user_languages')->distinct()->pluck('name'));
        $kept('citizenships', DB::table('user_citizenships')->distinct()->pluck('name'));
        $kept('employment_types', DB::table('users')->whereNotNull('employment_type')->distinct()->pluck('employment_type'));

        Schema::create('citizenship_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('citizenship_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['citizenship_id', 'user_id']);
        });

        Schema::create('language_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('language_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('level', ['beginner', 'intermediate', 'advanced']);
            $table->timestamps();

            $table->unique(['language_id', 'user_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('employment_type_id')->nullable()->after('employee_number')->constrained()->nullOnDelete();
        });

        DB::table('language_user')->insertUsing(
            ['language_id', 'user_id', 'level', 'created_at', 'updated_at'],
            DB::table('user_languages')
                ->join('languages', 'languages.name', '=', 'user_languages.name')
                ->select('languages.id', 'user_languages.user_id', 'user_languages.level', 'user_languages.created_at', 'user_languages.updated_at'),
        );

        DB::table('citizenship_user')->insertUsing(
            ['citizenship_id', 'user_id', 'created_at', 'updated_at'],
            DB::table('user_citizenships')
                ->join('citizenships', 'citizenships.name', '=', 'user_citizenships.name')
                ->select('citizenships.id', 'user_citizenships.user_id', 'user_citizenships.created_at', 'user_citizenships.updated_at'),
        );

        foreach (DB::table('employment_types')->get(['id', 'name']) as $kind) {
            DB::table('users')->where('employment_type', $kind->name)->update(['employment_type_id' => $kind->id]);
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('employment_type');
        });

        Schema::dropIfExists('user_languages');
        Schema::dropIfExists('user_citizenships');
    }
};
