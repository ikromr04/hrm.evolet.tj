<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A department keeps «СФД» of its own now — the same nine sections a
     * position's profile is made of, written about the unit instead of about a
     * job — so it needs the one thing a document of its own needs: the heading
     * the sheet is printed under.
     *
     * «ПРОФИЛЬ ПОДРАЗДЕЛЕНИЯ ОТДЕЛА ДИЗАЙНА» is the genitive of the name, which
     * is a case nothing here invents, so it is typed — exactly as the heading
     * of «Положение о работе» beside it is typed, and in the same column width.
     * Nullable, and null is the normal state: a department nobody has given a
     * heading reads one worked out from its name, almost right and corrected in
     * the field rather than in the code.
     *
     * Beside the regulation's heading and not instead of it: a department has
     * two documents and prints each under its own line.
     */
    public function up(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->string('profile_title', 200)->nullable()->after('regulation_title');
        });
    }

    /**
     * The headings somebody typed go with the column. Nothing else reads them
     * and nothing can restore them: they are names in a case the application
     * cannot work out, which is why they were typed in the first place.
     *
     * What a department wrote in the document itself stays where it is. Its
     * sections are rows of regulation_sections filed under the profile, which
     * this migration neither wrote nor knows about.
     */
    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->dropColumn('profile_title');
        });
    }
};
