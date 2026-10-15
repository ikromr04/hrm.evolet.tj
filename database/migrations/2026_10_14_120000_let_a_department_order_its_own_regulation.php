<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where one department reads each section of «Положение о работе».
     *
     * A department could arrange its own sections and nothing else: the order
     * of the standard ones was the template's, the same for everybody, because
     * that order is a column on the row and one row of the template is read in
     * every department's document at once. Asked to move a standard section the
     * page refused and explained why, which is not an answer anybody wanted.
     *
     * So where a section is read becomes a thing of its own: a row per
     * department per section, written only when that department arranges its
     * document. The section is not touched — the wording of a standard section
     * stays the company's and stays shared — and a department that has never
     * arranged anything has no rows here and reads the template's order,
     * exactly as before.
     *
     * The section named is the place in the document rather than the row read
     * in it: for a standard section it is the template's row, and a
     * department's own wording of that section is read in the same place, so
     * rewriting the text does not move it and moving it does not rewrite
     * anything.
     */
    public function up(): void
    {
        Schema::create('regulation_orders', function (Blueprint $table) {
            // Spelled out: a host whose default is MyISAM drops the foreign
            // keys below without a word, and they are what keeps this table
            // from outliving what it talks about.
            $table->engine('InnoDB');
            $table->id();
            // Whose arrangement it is. It goes with the department.
            $table->foreignId('department_id')->constrained()->cascadeOnDelete();
            // The place in the document. A standard section dropped from the
            // template is dropped from every arrangement with it, which leaves
            // no row pointing at nothing and no gap in what is read.
            $table->foreignId('regulation_section_id')->constrained()->cascadeOnDelete();
            // Counted from one, down the document as the department reads it.
            $table->unsignedSmallInteger('order')->default(0);

            // One place per section per department.
            $table->unique(['department_id', 'regulation_section_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('regulation_orders');
    }
};
