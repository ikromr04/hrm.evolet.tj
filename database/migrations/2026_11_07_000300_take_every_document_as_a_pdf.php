<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One kind of file and no counting: a document is one or more PDFs.
     *
     * The list used to say how each paper might arrive — chosen from a disk,
     * photographed, between two and five files — and every one of those
     * answers has turned out to be the same answer. The office prints, signs
     * and scans; what it files is a PDF, and how many pages it runs to is the
     * document's business rather than the list's.
     *
     * What a type still says is what the paper is called, what is wanted of it
     * in a word, whether the original is handed over instead of sent, and
     * whether it is asked of everybody.
     */
    public function up(): void
    {
        Schema::table('document_types', function (Blueprint $table) {
            $table->dropColumn(['min_files', 'max_files', 'accepts_files', 'accepts_camera']);
        });
    }

    /**
     * The four columns back, saying what every type said before they went: a
     * file or a photograph, one of them at least and no ceiling.
     */
    public function down(): void
    {
        Schema::table('document_types', function (Blueprint $table) {
            $table->unsignedTinyInteger('min_files')->default(1)->after('hint');
            $table->unsignedTinyInteger('max_files')->nullable()->after('min_files');
            $table->boolean('accepts_files')->default(true)->after('max_files');
            $table->boolean('accepts_camera')->default(true)->after('accepts_files');
        });
    }
};
