<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The order the papers are asked for in, kept by the list itself.
     *
     * A checklist is read from the top down and worked through in that order:
     * the passport and the labour book first, the certificates of courses
     * somebody once took last. Alphabetical order says nothing about which of
     * them a new colleague is asked for first, and the office knows what that
     * order is.
     *
     * Spaced a hundred apart, as the sections of a regulation are, so that a
     * paper put between two others needs no renumbering of the rest.
     */
    public function up(): void
    {
        Schema::table('document_types', function (Blueprint $table) {
            $table->unsignedInteger('position')->default(0)->after('name');
        });

        // What the list has been read in until now, which is by name.
        $place = 0;

        foreach (DB::table('document_types')->orderBy('name')->pluck('id') as $id) {
            DB::table('document_types')->where('id', $id)->update(['position' => ++$place * 100]);
        }
    }

    public function down(): void
    {
        Schema::table('document_types', function (Blueprint $table) {
            $table->dropColumn('position');
        });
    }
};
