<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Departments are known around the company by their short form — «ДУЧР»
     * rather than «Департамент управления человеческими ресурсами» — and that
     * is what every page now names them by. The full name stays, and the
     * directory is where both are kept.
     *
     * Nullable, because every department already on file has none until
     * somebody writes one, and a department without one is still named by its
     * full name. Fifty characters: an abbreviation is a handful of letters,
     * sometimes with a qualifier after it («ОУР Тдж»), and the limit is there
     * to keep a second full name out of the field rather than to be reached.
     */
    public function up(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->string('abbreviation', 50)->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->dropColumn('abbreviation');
        });
    }
};
