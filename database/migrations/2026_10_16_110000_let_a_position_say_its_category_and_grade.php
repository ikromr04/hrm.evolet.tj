<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * «КАТЕГОРИЯ» and «ГРАЙД» — two fields of «01 ОБЩАЯ ИНФОРМАЦИЯ», the table
     * at the top of «Профиль должности»: «Менеджер» and «Менеджер 1» for the
     * head of a department, the company's own words for what a position is
     * worth and where in its own ladder it stands.
     *
     * Nothing in the application can work either of them out. They are not read
     * off the structure — two positions in one department sit in different
     * grades — and they are not anybody's personal detail either: they belong to
     * the job, which is why they are columns here and not on the card of
     * whoever holds it. So they are typed, by the same people who keep the list
     * of positions, and they stay empty until somebody does.
     *
     * Plain strings rather than a list to choose from. The categories and the
     * grades are a scale the company is still writing down, and a directory of
     * them would have to be filled in before a single profile could be
     * finished; when the words have settled, a list is a migration that reads
     * what was typed.
     */
    public function up(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            // Room for a phrase rather than a word, because «Руководитель
            // среднего звена» is one of them and the sheet prints it whole.
            $table->string('category', 100)->nullable()->after('name');
            $table->string('grade', 100)->nullable()->after('category');
        });
    }

    /**
     * Both go with the column, and nothing can put them back: they were typed
     * because nothing here could work them out, which is the whole reason they
     * exist.
     */
    public function down(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->dropColumn(['category', 'grade']);
        });
    }
};
