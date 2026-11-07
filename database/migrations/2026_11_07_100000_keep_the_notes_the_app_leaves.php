<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where a note the application leaves somebody is kept.
     *
     * Until now the only thing this system ever said to anybody it said by
     * letter: an account was opened, an address wanted confirming, a password
     * wanted resetting. A letter suits those three — each of them is read once,
     * outside the application, often before the person can even sign in.
     *
     * The personal sheet is the opposite case. A colleague asks for their card
     * to be changed, personnel look at the request and either write it in or
     * send it back, and all three of those happen to people who are already
     * signed in and looking at this application. A letter for that would be a
     * letter about a page one click away, and nothing would tell the reader
     * whether they had dealt with it already. So the note is kept here, where
     * the bell can count what is still unread and the list can be read in the
     * order things happened.
     *
     * This is Laravel's own table, column for column, and deliberately so: the
     * `Notifiable` trait on `User` is already there, and its relations look for
     * exactly these names. Nothing here is specific to the personal sheet —
     * `type` is the notification class, `data` is whatever that class wrote, and
     * a second kind of note needs a new class and no new column.
     *
     * The key is a uuid rather than a counter because the id travels to the
     * browser and comes back from it when a row is marked read, and a counter
     * would let anybody who has one guess the rest. `notifiable` is a morph
     * because the trait is not promised to `User` alone. `read_at` is a moment
     * and nullable, not a flag: null is «not read yet», and a date answers when
     * without a second column.
     */
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Rolled back, the notes go with the table.
     *
     * Nothing is lost that was not said somewhere else as well: a note is a
     * pointer at a request, and the requests are a table of their own.
     */
    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
