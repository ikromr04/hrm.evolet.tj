<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Notifications are put aside until they are designed, so the table they
     * were kept in goes: nothing writes to it any more — the classes that did
     * were deleted with this change — and a table nothing fills is a table
     * nobody can trust.
     *
     * What it held is lost, and that is the intent: it was a log of what had
     * already happened elsewhere — a unit handed over, a colleague added, a
     * card changed — and every one of those is still written in its own place,
     * in the journal of the fleet and in the cards themselves.
     *
     * Mail is untouched: «Пароль», «Новый аккаунт» and the confirmation of a
     * changed address never went through this table.
     */
    public function up(): void
    {
        Schema::dropIfExists('notifications');
    }

    /**
     * The table back as it was, empty: the rows are not kept anywhere to be
     * put back, so this undoes the shape and not the contents.
     */
    public function down(): void
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
};
