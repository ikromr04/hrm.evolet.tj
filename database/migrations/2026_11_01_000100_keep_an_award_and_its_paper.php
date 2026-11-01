<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «Государственные награды, поощрения» — part 07 of the sheet, kept on the same
 * terms as the degree line above it.
 *
 * One line of text and the one paper behind it, for the same reasons: what a
 * person was given is a sentence they would write themselves — «Медаль
 * „Хизмати шоиста“», «Благодарность Правительства» — and a list of the kinds of
 * award would be a directory the company does not keep and the state does not
 * publish. A card that holds both a medal and a commendation says both on the
 * one line, the way it names a degree and a title together.
 *
 * And «Нет» is an answer here too: an empty line means nobody has asked yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_details', function (Blueprint $table) {
            $table->string('award', 200)->nullable()->after('degree_document_name');

            // Where the file went on the private disk, and the name it arrived
            // under. Empty together — there is a document or there is none.
            $table->string('award_document_path')->nullable()->after('award');
            $table->string('award_document_name')->nullable()->after('award_document_path');
        });
    }

    public function down(): void
    {
        // The file outlives the column, as it does everywhere else: a dropped
        // column cannot reach into storage, and a migration that deleted
        // documents on the way down would be a migration nobody dared run.
        Schema::table('user_details', function (Blueprint $table) {
            $table->dropColumn(['award', 'award_document_path', 'award_document_name']);
        });
    }
};
