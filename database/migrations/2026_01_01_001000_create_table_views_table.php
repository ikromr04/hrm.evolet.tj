<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How a table opens for somebody who has never touched it.
     *
     * Every viewer keeps their own arrangements in their own browser. This is
     * the one underneath: the columns a table shows the first time anybody
     * opens it, which would otherwise be written into the page and could only
     * be changed by changing the page.
     *
     * The system administrator sets it from the «Вид» menu, having arranged the
     * table the way the company wants it read. One row per table, named by the
     * key the page keeps its own views under.
     */
    public function up(): void
    {
        Schema::create('table_views', function (Blueprint $table) {
            $table->id();
            // The key the page keeps its views under, which is what the browser
            // writes its own arrangements beside.
            $table->string('key', 64)->unique();
            // Which columns are hidden, which are held to an edge, in what
            // order and how wide — the same thing a kept view holds.
            $table->json('view');
            // Who put it there, so the one row can be asked about. The row
            // stays if that account goes.
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('table_views');
    }
};
