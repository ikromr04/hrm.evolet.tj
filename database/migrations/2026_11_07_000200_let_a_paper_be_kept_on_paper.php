<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Papers that are handed over rather than uploaded.
     *
     * A labour book is given to the personnel officer across a desk and put in
     * a safe. Nobody scans it, and a checklist that only knows how to take
     * files would show it as missing for ever — so the card would say the
     * person had not handed in the one document the office is holding.
     *
     * Such a type is ticked rather than attached to: somebody who passes papers
     * says «оригинал у меня», and the tick is that sentence, with their name
     * and the day on it. A scan may still be clipped to it afterwards — the
     * first page of the book, a photograph of a page — but nothing is waiting
     * for one.
     */
    public function up(): void
    {
        Schema::table('document_types', function (Blueprint $table) {
            $table->boolean('on_paper')->default(false)->after('accepts_camera');
        });
    }

    public function down(): void
    {
        Schema::table('document_types', function (Blueprint $table) {
            $table->dropColumn('on_paper');
        });
    }
};
