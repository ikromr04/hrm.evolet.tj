<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The heading of the printed sheet, which is not «Положение о работе» but
     * «ПОЛОЖЕНИЕ О РАБОТЕ ДЕПАРТАМЕНТА АНАЛИТИКИ И СТАТИСТИКИ» — the
     * department's name in the genitive, which is a case nothing here invents.
     *
     * So it is typed. One line, once, by whoever keeps the department, and from
     * then on it is theirs: the company writes its own headings correctly and no
     * rule we could write would. It belongs to the department rather than to a
     * section of the document, because it names the whole document.
     *
     * Nullable, and null is the normal state: a department that has never been
     * given a heading reads one worked out from its name, which is already
     * almost right and is corrected in the field rather than in the code.
     */
    public function up(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            // Room for a long department's name after the four words in front
            // of it, and no more: this is a heading on a sheet of paper.
            $table->string('regulation_title', 200)->nullable()->after('abbreviation');
        });
    }

    /**
     * The headings somebody typed go with the column. Nothing else reads them,
     * and nothing can restore them: they are sentences in a case the
     * application cannot work out, which is why they were typed in the first
     * place.
     */
    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->dropColumn('regulation_title');
        });
    }
};
