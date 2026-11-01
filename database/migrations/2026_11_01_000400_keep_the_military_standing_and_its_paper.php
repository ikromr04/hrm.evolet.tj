<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «Отношение к воинской обязанности» — the last of the card's three lines that
 * are a sentence with a paper behind it.
 *
 * Kept exactly as the degree and the awards are kept, and for the same reasons:
 * «военнообязанный, старший лейтенант запаса» is one answer about one person,
 * the wording is copied off a document rather than chosen from a directory the
 * company keeps, and «Нет» has to be written down to be told from a line nobody
 * has asked about yet.
 *
 * The paper is the военный билет, or the page of it that says this.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_details', function (Blueprint $table) {
            $table->string('military', 200)->nullable()->after('award_document_name');
            $table->string('military_document_path')->nullable()->after('military');
            $table->string('military_document_name')->nullable()->after('military_document_path');
        });
    }

    public function down(): void
    {
        // The file outlives the column, as it does everywhere else.
        Schema::table('user_details', function (Blueprint $table) {
            $table->dropColumn(['military', 'military_document_path', 'military_document_name']);
        });
    }
};
