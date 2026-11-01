<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «Учёная степень, звание» — part 04 of the sheet, which had nowhere to be kept.
 *
 * One line of text, and the document behind it. A line, because that is the
 * question the sheet asks and the answer a person gives: «кандидат
 * экономических наук, доцент» is one thing to know about one colleague, and
 * taking it apart into a degree and a title would ask whoever fills the card in
 * to file a form where the sheet asked a question. Written out rather than
 * chosen from a list, too — the names run long, every field has its own, and a
 * company hears one it has not heard before every few years.
 *
 * So three columns beside the rest of the card's private lines, where the
 * nationality and the address are: the line, and the one paper that is shown
 * for it, kept the way the diploma of a place of study is kept.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_details', function (Blueprint $table) {
            $table->string('degree', 200)->nullable()->after('nationality');

            // Where the file went on the private disk, and what it is called:
            // the name it arrived under, which is the name it is handed back
            // under. Empty together — there is a document or there is none.
            $table->string('degree_document_path')->nullable()->after('degree');
            $table->string('degree_document_name')->nullable()->after('degree_document_path');
        });
    }

    public function down(): void
    {
        // The file outlives the column, as it does everywhere else: a dropped
        // column cannot reach into storage, and a migration that deleted
        // documents on the way down would be a migration nobody dared run.
        Schema::table('user_details', function (Blueprint $table) {
            $table->dropColumn(['degree', 'degree_document_path', 'degree_document_name']);
        });
    }
};
