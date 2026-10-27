<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * «Инвентаризация» is read in three: what people work on, the furniture,
     * and what the office itself runs on. The company owns one stock all the
     * same — a desk is an inventory item exactly as a laptop is — so the three
     * are not three tables but one question asked of a category.
     *
     * Every category there is says «Рабочее оборудование», which is where they
     * have all been read until now; whoever keeps the directory moves the desks
     * and the printers across as they see fit.
     */
    public function up(): void
    {
        Schema::table('equipment_types', function (Blueprint $table) {
            $table->string('section', 32)->default('equipment')->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('equipment_types', function (Blueprint $table) {
            $table->dropColumn('section');
        });
    }
};
