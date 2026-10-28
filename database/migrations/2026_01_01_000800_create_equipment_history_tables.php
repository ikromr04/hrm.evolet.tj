<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Everything that has ever happened to a unit, in the order it happened:
     * put on the books, handed over, reassigned, taken back, serviced, written
     * off, or simply corrected. This is the whole record of a unit's past — who
     * did what to it and when — which is what a question like "what changed
     * last month" is really asking.
     *
     * Beside the journal stand the pieces of work done on a unit and the
     * photographs taken while somebody had it in hand.
     */
    public function up(): void
    {
        Schema::create('equipment_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('equipment_id')->constrained()->cascadeOnDelete();
            // Whoever did it; empty for what the seeder and the system do.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('kind', 30)->index();
            // Field => [before, after], for the fields that actually changed.
            // Not called "changes": Eloquent keeps a property of that name, and
            // a column that shadows it is unreadable from inside the model.
            $table->json('diff')->nullable();
            // A line to read when the fields alone do not tell the story.
            $table->string('note', 200)->nullable();

            $table->timestamps();

            $table->index(['equipment_id', 'created_at']);
            $table->index('created_at');
        });

        // Every piece of work done on a unit: maintenance as well as a repair.
        Schema::create('equipment_repairs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('equipment_id')->constrained()->cascadeOnDelete();

            $table->string('kind', 150);
            $table->date('started_at');
            // Still away while this is empty.
            $table->date('ended_at')->nullable();
            $table->string('note', 200)->nullable();

            $table->timestamps();
        });

        // Photographs of a unit, taken when somebody looked it over or worked
        // on it. They belong to the journal entry that records the occasion
        // rather than to the unit, so the next check adds its own and the
        // earlier ones stay where they were — a record of what the thing looked
        // like that day.
        Schema::create('equipment_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('equipment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('equipment_event_id')->nullable()->constrained()->cascadeOnDelete();
            // Set as well when the pictures came with a piece of service work,
            // so they are shown on that record and not only in the journal.
            $table->foreignId('equipment_repair_id')->nullable()->constrained()->cascadeOnDelete();

            // The upload itself, and the scaled copy the interface shows.
            $table->string('path');
            $table->string('preview');

            $table->timestamps();

            $table->index(['equipment_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('equipment_photos');
        Schema::dropIfExists('equipment_repairs');
        Schema::dropIfExists('equipment_events');
    }
};
