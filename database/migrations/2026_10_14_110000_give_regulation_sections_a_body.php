<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A section of «Положение о работе» was three different things — a numbered
     * list, the two-column table of tasks, the sheet signed at the printer —
     * and each was kept as rows, one per line, so that the table could hold two
     * halves of a line at once.
     *
     * It is one thing now: a heading and a body, written in an editor that gives
     * the person the list, the emphasis and the table themselves. So the lines
     * stop being rows and become the body's own markup, and the shape a section
     * used to be told apart by stops being asked for.
     *
     * The number goes with it, for a different reason: it was never a property
     * of the row. A department reads one document assembled out of two scopes —
     * the company's standard sections and its own — and a row of the template is
     * read in twenty-six of those documents at once, in a different place in
     * each. Numbered in its own scope it came out right only until a department
     * added a section, and then two sections of one document were both «01». So
     * the number is worked out where the document is put together, from the
     * place each section is read in, and nothing about it is stored.
     *
     * Nothing else is carried across either: the sections the template seeded
     * are dropped in the same change, and the standard text is written again as
     * bodies.
     */
    public function up(): void
    {
        Schema::dropIfExists('regulation_items');

        Schema::table('regulation_sections', function (Blueprint $table) {
            // The content as sanitized HTML — paragraphs, lists and tables as
            // the editor wrote them. Long text because a section of a document
            // is written to be read, not to fit a field.
            $table->longText('body')->nullable()->after('title');
        });

        $gone = array_values(array_filter(
            ['kind', 'number'],
            fn (string $column) => Schema::hasColumn('regulation_sections', $column),
        ));

        if ($gone !== []) {
            Schema::table('regulation_sections', fn (Blueprint $table) => $table->dropColumn($gone));
        }
    }

    /**
     * The two columns and the rows come back, and come back empty: a body
     * written as markup is not three kinds of section again, HTML does not split
     * back into the halves of a line the table used to keep, and a number nobody
     * stored is not a number anybody can restore. What a section says is in
     * `body`, which is dropped here, so a rollback is a rollback of the schema
     * and not of the document: every section comes back a heading with nothing
     * under it. Rolling this back and forward again on a database somebody has
     * written in throws that writing away, and RegulationTemplateSeeder will
     * not put even the standard text back, because the headings are still there
     * and it writes only while the template is empty.
     */
    public function down(): void
    {
        if (! Schema::hasColumn('regulation_sections', 'number')) {
            Schema::table('regulation_sections', function (Blueprint $table) {
                $table->string('number', 10)->default('')->after('template_section_id');
            });
        }

        if (! Schema::hasColumn('regulation_sections', 'kind')) {
            Schema::table('regulation_sections', function (Blueprint $table) {
                $table->string('kind', 20)->default('list')->after('title');
            });
        }

        Schema::table('regulation_sections', function (Blueprint $table) {
            $table->dropColumn('body');
        });

        if (! Schema::hasTable('regulation_items')) {
            Schema::create('regulation_items', function (Blueprint $table) {
                $table->engine('InnoDB');
                $table->id();
                $table->foreignId('regulation_section_id')->constrained()->cascadeOnDelete();
                $table->unsignedSmallInteger('order')->default(0);
                $table->text('text');
                $table->text('product')->nullable();
                $table->timestamps();
            });
        }
    }
};
