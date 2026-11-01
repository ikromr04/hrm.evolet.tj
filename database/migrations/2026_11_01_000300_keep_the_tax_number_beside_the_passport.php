<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «ИНН» — the number the tax office files a person under.
 *
 * Beside the passport and not on a block of its own: it is read by the people
 * who read the passport, for the same errands — a contract, a payroll, a form
 * for the ministry — and a card that scattered somebody's identifiers over
 * three parts of a page would have whoever needs them hunting.
 *
 * A string and not a number, for the reason every such code is a string: it is
 * written down and compared, never counted, and a leading nought is part of it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_details', function (Blueprint $table) {
            $table->string('tax_number', 20)->nullable()->after('passport_issued_by');
        });
    }

    public function down(): void
    {
        Schema::table('user_details', function (Blueprint $table) {
            $table->dropColumn('tax_number');
        });
    }
};
