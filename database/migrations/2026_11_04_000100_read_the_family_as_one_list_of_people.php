<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * «Состав семьи» read as one list of people.
 *
 * The sheet asks the same three things of everybody a card names — what they
 * are called, when they were born, where they work or study — and the card was
 * answering them unevenly. The spouse and the children were a name and a date
 * with nothing said about their days; a mother or a brother had the line about
 * the work and a bare year where the date should have been. One list of people
 * wants one set of answers, so the spouse and the children gain the line that
 * was only the relatives', and the relatives gain the date that was only the
 * spouse's and the children's.
 *
 * The date is a month and a year, for all three of them. A personnel office is
 * told that a brother was born in March of 1971 and is never told which day of
 * it; the same is true of a wife rather more often than anybody admits. A card
 * that insists on the day gets one invented, and an invented day is worse than
 * an absent one, because it reads as having been checked.
 *
 * The columns stay dates all the same — a month is a date with its day left
 * out, and no database has a type for one — and the day they carry is the first
 * of the month. That is the convention the whole card now reads them by, and
 * what the payload prints as «1971-03».
 *
 * What is on file is carried across rather than asked for again. A relative
 * born in 1971 keeps that year as the first of January 1971: the year is all
 * anybody ever wrote down, and the first of January is the only honest reading
 * of a year when a month is wanted. A spouse or a child whose full date is on
 * file keeps the month of it and loses the day — the one thing here that cannot
 * be undone, and meant not to be. Nobody ever confirmed that day and from now
 * on nobody will ever be asked to, so keeping it would be keeping a number the
 * card no longer stands behind.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_details', function (Blueprint $table) {
            // Beside the spouse's name and date, where the card asks about
            // them, and as long as the relatives' line has been from the
            // start: it is the same answer about a different person.
            $table->string('spouse_occupation', 200)->nullable()->after('spouse_birth_date');
        });

        Schema::table('user_children', function (Blueprint $table) {
            $table->string('occupation', 200)->nullable()->after('birth_date');
        });

        Schema::table('user_relatives', function (Blueprint $table) {
            $table->date('birth_date')->nullable()->after('full_name');
        });

        // The years already on file, one statement per year rather than one per
        // row, and through the query builder rather than an expression written
        // out by hand: the suite runs this against sqlite while the company runs
        // it against MySQL, and the two do not spell a date built out of a
        // number the same way.
        foreach (DB::table('user_relatives')->whereNotNull('birth_year')->distinct()->pluck('birth_year') as $year) {
            DB::table('user_relatives')
                ->where('birth_year', $year)
                ->update(['birth_date' => sprintf('%04d-01-01', $year)]);
        }

        Schema::table('user_relatives', function (Blueprint $table) {
            $table->dropColumn('birth_year');
        });

        // And the two dates that used to be asked for in full, put back to the
        // first of their month. A row left carrying a day would be read as a
        // month everywhere on the card and still hold a number nobody can be
        // asked to confirm.
        $this->keepTheMonth('user_details', 'spouse_birth_date');
        $this->keepTheMonth('user_children', 'birth_date');
    }

    /**
     * Back to the shape the card had, which is not the same as back to what it
     * said.
     *
     * The year comes out of the date, and that is the whole of what the column
     * used to hold, so nothing is lost there. The months go with the two
     * columns that gain them here; the days the up() rubbed out are gone for
     * good and no reversal can invent them again.
     */
    public function down(): void
    {
        Schema::table('user_relatives', function (Blueprint $table) {
            $table->unsignedSmallInteger('birth_year')->nullable()->after('full_name');
        });

        DB::table('user_relatives')
            ->whereNotNull('birth_date')
            ->orderBy('id')
            ->each(fn (object $row) => DB::table('user_relatives')
                ->where('id', $row->id)
                ->update(['birth_year' => Carbon::parse($row->birth_date)->year]));

        Schema::table('user_relatives', function (Blueprint $table) {
            $table->dropColumn('birth_date');
        });

        Schema::table('user_children', function (Blueprint $table) {
            $table->dropColumn('occupation');
        });

        Schema::table('user_details', function (Blueprint $table) {
            $table->dropColumn('spouse_occupation');
        });
    }

    /**
     * One column of dates, each row put back to the first of its month.
     *
     * Row by row and in PHP, because every database says «the month of this
     * date» differently and the suite and the company do not run the same one.
     * A row already on the first is left alone rather than written over with
     * itself.
     */
    private function keepTheMonth(string $table, string $column): void
    {
        DB::table($table)
            ->whereNotNull($column)
            ->orderBy('id')
            ->each(function (object $row) use ($table, $column) {
                $date = Carbon::parse($row->{$column});

                if ($date->day === 1) {
                    return;
                }

                DB::table($table)
                    ->where('id', $row->id)
                    ->update([$column => $date->startOfMonth()->toDateString()]);
            });
    }
};
