<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which of a position's duties an employee actually carries. Holding the
     * position used to mean carrying all of them, and in a department of six
     * analysts that was never true: the list is what the position answers for
     * between them, and each of them answers for a few of it.
     *
     * Nothing is written here by this migration. Whoever carries what is not in
     * the data — "everybody carries everything" was the assumption, not a
     * record of anything — so the table starts empty and is filled a person at
     * a time, one duty or several, from the list of their position.
     */
    public function up(): void
    {
        Schema::create('position_duty_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('position_duty_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            // Carrying a duty is something a person either does or does not.
            $table->unique(['position_duty_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('position_duty_user');
    }
};
