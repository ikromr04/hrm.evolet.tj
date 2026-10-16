<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * «КАТЕГОРИЯ» and «ГРАЙД» were added because «Профиль должности» opens with
     * them, and the company has since said it keeps neither: there is no ladder
     * written down to grade a job against, so the two fields were two boxes
     * nobody was going to fill. «01 ОБЩАЯ ИНФОРМАЦИЯ» is five rows rather than
     * seven, and the list of positions is three columns rather than five.
     *
     * Nothing goes with the columns. They stood for a day and were empty in
     * every row, so there is no value anywhere to carry anywhere else first.
     *
     * The migration that added them is left exactly as it is: it has been run on
     * every machine already, and a schema the application no longer wants is a
     * step forward rather than a line edited out of history.
     */
    public function up(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->dropColumn(['category', 'grade']);
        });
    }

    /**
     * Both come back as they were — nullable, after the name — so that a
     * machine rolling this step back lands on the schema it rolled forward
     * from. Empty, which is the whole of what they ever held.
     */
    public function down(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->string('category', 100)->nullable()->after('name');
            $table->string('grade', 100)->nullable()->after('category');
        });
    }
};
