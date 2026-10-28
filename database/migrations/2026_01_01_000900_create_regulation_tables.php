<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The company's two written documents — «Положение о работе», which a
     * department has, and «Профиль должности», which a position and a
     * department alike have — kept by one piece of machinery, because they are
     * kept the same way.
     *
     * Most of a document is the same text for everybody: it is written once as
     * the sections that belong to no owner — the template — and every owner
     * reads it. An owner that needs its own wording keeps a section of its own
     * instead of the standard one: a row with its owner filled in and the
     * template section it stands for named, which is read in that section's
     * place and nowhere else. Where no such row exists the template is what the
     * owner reads, so correcting a sentence in the template corrects it for the
     * whole company.
     *
     * A section is a heading and a body, and the body is the text as the editor
     * wrote it — the numbered list, the table of tasks, the line to sign under
     * are all markup inside it rather than three kinds of section the
     * application has to tell apart. Both documents are one such text now, with
     * their own parts as headings inside the body, which is how the company
     * writes and prints them.
     *
     * Two owner columns with real keys rather than one pair naming a table: a
     * department that is deleted takes its regulation with it and a position
     * that is deleted takes its profile, and that is the database's own doing.
     * What a pair would have given is the one thing kept in the model instead —
     * a row must not belong to two owners at once.
     */
    public function up(): void
    {
        Schema::create('regulation_sections', function (Blueprint $table) {
            // Spelled out: a host whose default is MyISAM drops the foreign keys
            // below without a word, and this table's whole shape is in them.
            $table->id();
            // Which document the section is part of. Not the owner's job to
            // say: a section of the template belongs to nobody, so without this
            // the standard text of the regulation and the standard text of the
            // profile would be the same rows of nothing in particular.
            $table->string('document', 20)->default('regulation');
            // Empty for the shared template; an owner for its own sections. The
            // document is part of its owner, so it goes with it.
            $table->foreignId('department_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('position_id')->nullable()->constrained()->cascadeOnDelete();
            // The standard section this one is read in place of. An owner that
            // drops its override reads the template again, which is why the
            // override is a row beside the template rather than a change to it.
            //
            // nullOnDelete: dropping a standard section from the template leaves
            // every owner that had rewritten it holding its own text, now as a
            // section of its own. Cascading would throw that text away because
            // somebody tidied the template, and refusing the deletion would hold
            // the whole company's document hostage to one owner's wording.
            $table->foreignId('template_section_id')->nullable()->constrained('regulation_sections')->nullOnDelete();
            $table->string('title', 200);
            // The content as sanitized HTML — paragraphs, lists and tables as
            // the editor wrote them. Long text because a section of a document
            // is written to be read, not to fit a field.
            $table->longText('body')->nullable();
            // A section whose text is drawn from what the application already
            // knows rather than typed: «01 ОБЩАЯ ИНФОРМАЦИЯ» of the profile,
            // which is a table of the position's department, name, category and
            // grade. It has no body and never gets one, and the page draws it
            // from the fields the payload carries beside the sections.
            $table->boolean('computed')->default(false);
            // Where the section is read, which an owner may change for itself
            // through the arrangements below. The document carries no stored
            // numbering: a row of the template is read in every owner's document
            // at once, in a different place in each, so what is printed beside a
            // heading is worked out where the document is put together.
            $table->unsignedSmallInteger('order')->default(0);
            $table->timestamps();

            // One owner rewrites one standard section once. MySQL and SQLite both
            // count NULLs as distinct here, so each pair leaves the template's own
            // rows, the other document's and an owner's leftovers alone.
            $table->unique(['department_id', 'template_section_id']);
            $table->unique(['position_id', 'template_section_id']);
        });

        Schema::create('regulation_orders', function (Blueprint $table) {
            // Spelled out, for the reason above: these foreign keys are what
            // keeps this table from outliving what it talks about.
            $table->id();
            // Whose arrangement it is — a department's regulation or a
            // position's profile, one of the two and never both. An owner that
            // has never arranged anything has no rows here and reads the order
            // the template gives.
            $table->foreignId('department_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('position_id')->nullable()->constrained()->cascadeOnDelete();
            // The place in the document rather than the row read in it: for a
            // standard section it is the template's row, and an owner's own
            // wording of that section is read in the same place, so rewriting
            // the text does not move it and moving it does not rewrite
            // anything. A standard section dropped from the template is dropped
            // from every arrangement with it.
            $table->foreignId('regulation_section_id')->constrained()->cascadeOnDelete();
            // Counted from one, down the document as the owner reads it.
            $table->unsignedSmallInteger('order')->default(0);

            // One place per section per owner.
            $table->unique(['department_id', 'regulation_section_id']);
            $table->unique(['position_id', 'regulation_section_id']);
        });

        $this->oneOwner(
            'regulation_sections',
            'department_id is null or position_id is null',
            'Раздел документа принадлежит отделу или должности, но не обоим сразу.',
        );

        $this->oneOwner(
            'regulation_orders',
            '(department_id is null) <> (position_id is null)',
            'Порядок разделов принадлежит ровно одному владельцу — отделу или должности.',
        );
    }

    /**
     * Reverse the migrations. The arrangements first: they point at the sections.
     */
    public function down(): void
    {
        Schema::dropIfExists('regulation_orders');
        Schema::dropIfExists('regulation_sections');
    }

    /**
     * The rule the database keeps itself: a row of this table may not belong to
     * two owners at once.
     *
     * Two owner columns are two separate keys, and what a single key would have
     * given for free — a row with one owner — has to be said out loud. Written
     * as a check where the database has them and as a pair of triggers where it
     * has not, so that no code path, no seeder and no console session can write
     * a row belonging to a department and a position at the same time. Which
     * document an owner may own — a department a regulation, a position a
     * profile — is a rule about meaning, and the model keeps that one.
     */
    private function oneOwner(string $table, string $condition, string $message): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            foreach (['insert', 'update'] as $when) {
                $row = preg_replace('/\b(department_id|position_id)\b/', 'new.$1', $condition);

                DB::statement(<<<SQL
                    create trigger {$table}_one_owner_on_{$when}
                    before {$when} on {$table}
                    for each row when not ({$row})
                    begin select raise(abort, '{$message}'); end
                SQL);
            }

            return;
        }

        DB::statement("alter table {$table} add constraint {$table}_one_owner check ({$condition})");
    }
};
