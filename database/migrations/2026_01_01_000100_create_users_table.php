<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An account is the employee's card and the way in at once: everything the
     * whole company may read about a person, plus what signing in needs. The
     * rest of the card — passport, family, schooling, work before this one —
     * hangs off it in tables of its own.
     *
     * Everyone who ever worked here keeps their account; it is the status that
     * says whether they are still on the staff. The two tables after it are
     * Laravel's own: the tokens a forgotten password is reset with, and the
     * sessions, kept in the database so an account can be signed out everywhere.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();

            // Public profile: visible to every colleague.
            $table->string('name');
            $table->string('surname');
            $table->string('patronymic')->nullable();
            // The number the company files a person under. Not a number as the
            // database counts: it is written with its leading noughts, nothing
            // is ever added to it, and a card starts with its id in five digits
            // — 00001, 00002 — until somebody types the real one over it.
            $table->string('employee_number', 20)->nullable()->unique();
            // The square thumbnail the interface shows, and the file as it was
            // uploaded, kept so the photo can be opened at full size.
            $table->string('avatar')->nullable();
            $table->string('avatar_original')->nullable();
            $table->enum('sex', ['male', 'female']);

            // The kind of work the person was taken on for and the branch they
            // work at. An entry that goes out of either directory takes nothing
            // with it: the cards that named it simply stop naming anything.
            $table->foreignId('employment_type_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();

            // Employment status: people who left stay in the database, apart
            // from the working staff. Deleting an employee removes the row.
            $table->enum('status', ['active', 'transferred', 'fired'])->default('active')->index();
            $table->date('status_changed_at')->nullable();
            // Where the person was transferred to, or why they were let go.
            $table->string('status_note')->nullable();

            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            // An address asked for but not yet confirmed. It becomes the real
            // one only when the letter sent to it is answered, so a typo in
            // settings cannot lock anybody out.
            $table->string('pending_email')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            // Indexed, not constrained: a session outlives the account it was
            // opened by, and the garbage collector reads it by last activity.
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
