<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The diploma itself in place of its number.
 *
 * A number typed off a document proves nothing — it is as easy to type a number
 * nobody holds as one they do — and it was the only thing the record kept about
 * the document. A scan of the thing is what somebody checking a card actually
 * wants, so the record keeps the file instead.
 *
 * Two columns rather than one: where the file lies, and what it was called when
 * it arrived. Without the second, a diploma downloads under the name the disk
 * gave it — a line of random letters — and whoever saved it has no idea which
 * of four such files on their desktop is which.
 *
 * The numbers already on file go with the column. There are none to keep: the
 * field was never filled in on this database, and a number cannot be turned
 * into a document anyway. Reversing this brings the column back empty, which is
 * the most an honest `down()` can promise.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_educations', function (Blueprint $table) {
            $table->dropColumn('diploma_number');

            // Where the file lies on the private disk, and the name it came
            // with. Empty together: a record has a document or it has none.
            $table->string('document_path')->nullable()->after('graduated_year');
            $table->string('document_name')->nullable()->after('document_path');
        });
    }

    public function down(): void
    {
        Schema::table('user_educations', function (Blueprint $table) {
            $table->dropColumn(['document_path', 'document_name']);
            $table->string('diploma_number', 50)->nullable()->after('graduated_year');
        });
    }
};
