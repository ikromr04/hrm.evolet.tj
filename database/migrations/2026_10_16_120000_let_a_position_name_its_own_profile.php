<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The heading of the printed sheet, which is not «Профиль должности» but
     * «ПРОФИЛЬ ДОЛЖНОСТИ РУКОВОДИТЕЛЯ ОТДЕЛА ДИЗАЙНА» — the name of the
     * position in the genitive, which is a case nothing here invents.
     *
     * So it is typed, exactly as a department types the heading of its
     * «Положение о работе»: one line, once, by whoever keeps the positions, and
     * from then on it is theirs. It belongs to the position rather than to a
     * section of the document, because it names the whole document.
     *
     * Nullable, and null is the normal state: a position that has never been
     * given a heading reads one worked out from its name, which is already
     * almost right and is corrected in the field rather than in the code.
     */
    public function up(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            // Room for a long position's name after the two words in front of
            // it, and no more: this is a heading on a sheet of paper, and it is
            // the same line the department's own heading is.
            $table->string('profile_title', 200)->nullable()->after('grade');
        });
    }

    /**
     * The headings somebody typed go with the column. Nothing else reads them,
     * and nothing can restore them: they are names in a case the application
     * cannot work out, which is why they were typed in the first place.
     */
    public function down(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->dropColumn('profile_title');
        });
    }
};
