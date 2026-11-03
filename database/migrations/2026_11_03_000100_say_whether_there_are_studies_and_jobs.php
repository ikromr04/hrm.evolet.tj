<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «Образование» and «Трудовая деятельность» get an answer of their own.
 *
 * An empty list says two different things and the card could not tell them
 * apart: a person who has studied nowhere and never worked, and a person nobody
 * has asked yet. The same question was answered for the children a while ago,
 * with the same flag and for the same reason — «Нет» is an answer, and a blank
 * is the absence of one.
 *
 * Nullable, and three-valued on purpose: null is the card nobody has asked,
 * false is «нет», and true is «есть», which the rows on file imply anyway. Every
 * card already on the books starts at null, which is the truth about it — nobody
 * was asked, because there was no question.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_details', function (Blueprint $table) {
            $table->boolean('has_educations')->nullable()->after('military_document_name');
            $table->boolean('has_experience')->nullable()->after('has_educations');
        });
    }

    public function down(): void
    {
        Schema::table('user_details', function (Blueprint $table) {
            $table->dropColumn(['has_educations', 'has_experience']);
        });
    }
};
