<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The personal exceptions go with the table they were ticked in.
 *
 * A right given to, or taken from, one colleague in particular made sense while
 * there was a table of roles against rights to make an exception to. There is
 * none now: there are four roles, two of which pass every check and two of which
 * the site is not open to, and an exception would have nothing to except from.
 *
 * Nothing reads the table any more, so leaving it would leave a list that looks
 * like it decides something and does not. It goes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('permission_overrides');
    }

    /**
     * The table back as it was, empty — which is as much as a reversal can
     * honestly promise, the rows being gone.
     */
    public function down(): void
    {
        Schema::create('permission_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('permission');
            $table->boolean('allowed');
            $table->timestamps();

            $table->unique(['user_id', 'permission']);
        });
    }
};
