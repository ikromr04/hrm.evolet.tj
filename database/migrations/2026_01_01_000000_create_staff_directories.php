<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Four lists the card picks from instead of having them typed into it: the
     * countries a person holds, the languages they speak, the kinds of work
     * people are taken on for and the branches they work at. What each list
     * holds is the company's to write down and rename.
     *
     * Citizenships and languages a person has any number of, so they are joined
     * to the card through tables of their own; a kind of work and a branch there
     * is one of or none, so those two sit as columns on the account.
     */
    public function up(): void
    {
        // A hundred characters is what these directories and the card accept; a
        // language was left at the field's own length and has stayed there.
        Schema::create('citizenships', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->timestamps();
        });

        Schema::create('languages', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });

        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->timestamps();
        });

        Schema::create('employment_types', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employment_types');
        Schema::dropIfExists('branches');
        Schema::dropIfExists('languages');
        Schema::dropIfExists('citizenships');
    }
};
