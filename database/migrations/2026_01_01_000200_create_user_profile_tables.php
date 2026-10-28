<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The papers behind a staff card: one row of details per person, and a row
     * per child, per school and per job held before this one.
     *
     * Private employee data, kept out of the users table so it is never
     * serialized along with the public profile by accident. Everything here
     * belongs to one person and goes when they do.
     */
    public function up(): void
    {
        Schema::create('user_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();

            $table->date('hired_at')->nullable();

            $table->date('birth_date')->nullable();
            $table->string('birth_place')->nullable();
            // Citizenship is a directory of its own, because a person can hold
            // several of them; the nationality on the card is one line of text.
            $table->string('nationality')->nullable();

            $table->string('passport_series', 16)->nullable();
            $table->string('passport_number', 32)->nullable();
            $table->date('passport_issued_at')->nullable();
            $table->string('passport_issued_by')->nullable();

            $table->enum('marital_status', ['single', 'married'])->nullable();
            // There is at most one spouse, so they sit here rather than in a
            // table of their own like the children.
            $table->string('spouse_name', 150)->nullable();
            $table->date('spouse_birth_date')->nullable();
            // Three states: null while nobody has filled the card in, false once
            // HR states there are none, true alongside rows in user_children.
            // An empty list alone cannot tell "none" from "not asked yet".
            $table->boolean('has_children')->nullable();

            $table->string('home_address')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('sos_phone', 32)->nullable();
            // Whose number that is: "Мама — Дилором".
            $table->string('sos_contact', 100)->nullable();

            $table->timestamps();
        });

        Schema::create('user_children', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('full_name');
            $table->date('birth_date')->nullable();
            $table->timestamps();
        });

        Schema::create('user_educations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('institution', 200);
            $table->string('faculty', 150);
            $table->string('specialty', 150);
            // Years, not dates: a diploma names a year, and nobody remembers
            // the day they enrolled.
            $table->unsignedSmallInteger('started_year');
            // Empty while still studying.
            $table->unsignedSmallInteger('graduated_year')->nullable();
            $table->string('diploma_number', 50)->nullable();
            $table->timestamps();
        });

        Schema::create('user_work_experiences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('organization', 200);
            // The job as it was called there, not an entry in the positions
            // directory: this work was done at another company.
            $table->string('position', 150);
            $table->string('country', 100);
            // Known to the month: a labour book names a month, not a day.
            $table->unsignedTinyInteger('started_month');
            $table->unsignedSmallInteger('started_year');
            // Both empty while the person still works there.
            $table->unsignedTinyInteger('ended_month')->nullable();
            $table->unsignedSmallInteger('ended_year')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_work_experiences');
        Schema::dropIfExists('user_educations');
        Schema::dropIfExists('user_children');
        Schema::dropIfExists('user_details');
    }
};
