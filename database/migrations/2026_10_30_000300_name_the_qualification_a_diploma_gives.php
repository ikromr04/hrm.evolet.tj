<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a diploma made of somebody: бакалавр, магистр, инженер.
 *
 * The record already held where they studied, the faculty and the speciality,
 * which is what they studied — and nothing for what they came out as, although
 * «Личный листок» has been printing a column headed «Специальность,
 * квалификация» all along and filling half of it.
 *
 * Empty where it is not known, like the diploma number beside it: a record
 * entered while somebody is still studying has no qualification to name yet,
 * and the rows already on file have none either. A column added as required
 * would have to invent one for every one of them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_educations', function (Blueprint $table) {
            $table->string('qualification', 150)->nullable()->after('specialty');
        });
    }

    public function down(): void
    {
        Schema::table('user_educations', function (Blueprint $table) {
            $table->dropColumn('qualification');
        });
    }
};
