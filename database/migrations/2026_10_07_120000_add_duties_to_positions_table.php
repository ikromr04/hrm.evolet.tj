<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What a person in the position is responsible for, in plain words. Optional:
     * the positions that already exist have none until somebody writes them.
     */
    public function up(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->text('duties')->nullable()->after('name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->dropColumn('duties');
        });
    }
};
