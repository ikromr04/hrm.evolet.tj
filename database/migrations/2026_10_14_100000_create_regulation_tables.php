<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * «Положение о работе» — the document every department has, until now a Word
     * file passed around and printed.
     *
     * Most of it is the same sentence for everybody: a department is a unit of
     * the Representative Office, it is created by an order of its Head, its
     * people are hired by an order of its Head. That text is written once, as
     * the sections that belong to no department — the template — and every
     * department reads it.
     *
     * A department that needs its own wording keeps a section of its own instead
     * of the standard one: a row with its department filled in and the template
     * section it stands for named, which is read in that section's place and
     * nowhere else. Where no such row exists the template is what the department
     * reads, so correcting a sentence in the template corrects it for the whole
     * company.
     *
     * And a department may add sections no template has — a row with its
     * department and no template section behind it. The numbering of the
     * document leaves room for exactly that: it runs 01 to 05 and then jumps to
     * 09 for the signatures.
     */
    public function up(): void
    {
        Schema::create('regulation_sections', function (Blueprint $table) {
            // Spelled out: a host whose default is MyISAM drops the foreign keys
            // below without a word, and this table's whole shape is in them.
            $table->engine('InnoDB');
            $table->id();
            // Empty for the shared template; a department for its own sections.
            // The regulation is part of the department, so it goes with it.
            $table->foreignId('department_id')->nullable()->constrained()->cascadeOnDelete();
            // The standard section this one is read in place of. A department
            // that drops its override reads the template again, which is why the
            // override is a row beside the template rather than a change to it.
            //
            // nullOnDelete: dropping a standard section from the template leaves
            // every department that had rewritten it holding its own text, now as
            // a section of its own. Cascading would throw that text away because
            // somebody tidied the template, and refusing the deletion would hold
            // the whole company's document hostage to one department's wording.
            $table->foreignId('template_section_id')->nullable()->constrained('regulation_sections')->nullOnDelete();
            // "01", "02", "09" — the numbering the document carries, written
            // rather than counted: it skips from 05 to 09 and the company means
            // it to.
            $table->string('number', 10)->default('');
            $table->string('title', 200);
            // A numbered list, the two-column table of tasks and their valuable
            // final product, or the block signed when the document is printed.
            $table->string('kind', 20)->default('list');
            // Where the section is read, which a department may change for
            // itself. The number above is what is printed beside the heading;
            // this is the order, and the two are kept apart because renumbering a
            // document and moving a section through it are different acts.
            $table->unsignedSmallInteger('order')->default(0);
            $table->timestamps();

            // One department rewrites one standard section once. MySQL and SQLite
            // both count NULLs as distinct here, so this leaves the template's own
            // rows and a department's own additions alone.
            $table->unique(['department_id', 'template_section_id']);
        });

        Schema::create('regulation_items', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->id();
            $table->foreignId('regulation_section_id')->constrained()->cascadeOnDelete();
            // The order the lines are read in, as the position duties keep theirs.
            $table->unsignedSmallInteger('order')->default(0);
            // One numbered line of a list, or the left half of the table: what the
            // department does.
            $table->text('text');
            // The right half: the valuable final product of it. Only the table
            // ever fills this in.
            $table->text('product')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations. The items first: they point at the sections.
     */
    public function down(): void
    {
        Schema::dropIfExists('regulation_items');
        Schema::dropIfExists('regulation_sections');
    }
};
