<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What joins a card to the two directories it can name more than one entry
     * of: the countries a person is a citizen of, and the languages they speak,
     * each with how well. Both are public, like positions.
     *
     * An entry struck off a directory takes its links with it, and so does a
     * deleted account: neither leaves a citizenship or a language behind.
     */
    public function up(): void
    {
        Schema::create('citizenship_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('citizenship_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['citizenship_id', 'user_id']);
        });

        Schema::create('language_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('language_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('level', ['beginner', 'intermediate', 'advanced']);
            $table->timestamps();

            $table->unique(['language_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('language_user');
        Schema::dropIfExists('citizenship_user');
    }
};
