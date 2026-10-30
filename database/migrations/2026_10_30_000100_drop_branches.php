<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The branches go: the list, and the line on every card that pointed at it.
 *
 * A place somebody works from is not a thing this company files its people
 * under, so the directory is taken out rather than left standing empty for
 * somebody to wonder about. Nothing is lost by it: no card ever named one.
 *
 * Written as a migration of its own rather than as a change to the two that
 * made the table, because those two have run on the live database and a
 * migration that has run is a record of what happened. This one records what
 * happens next.
 *
 * The rights that went with the list — reading and editing «Филиалы», and the
 * card's own «Филиал» line — are not rows this file has any business with:
 * what rights exist is decided in App\Support\Access, and PermissionSeeder
 * deletes every row the catalogue no longer names. Taking them out of the
 * catalogue is what removes them, and the seeder is what tells the database.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // The key before the column it hangs off: MySQL will not let go of
            // a column a constraint is still holding.
            $table->dropForeign(['branch_id']);
            $table->dropColumn('branch_id');
        });

        Schema::dropIfExists('branches');
    }

    /**
     * The list and the line back as they were, empty.
     *
     * Which is as much as a reversal can honestly promise: the rows are gone,
     * and this cannot say which card pointed at which place. Nothing pointed
     * anywhere when the list was taken out, so there is nothing it is hiding.
     */
    public function down(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('branch_id')->nullable()->after('employment_type_id')->constrained()->nullOnDelete();
        });
    }
};
