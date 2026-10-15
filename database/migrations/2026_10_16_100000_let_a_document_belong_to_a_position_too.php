<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The company has a second document, «Профиль должности» — the sheet it
     * calls СФД — and it is kept exactly as «Положение о работе» is kept: a
     * template of standard sections everybody reads, one owner's wording in the
     * place of a standard section it rewrote, that owner's own arrangement of
     * the whole, and a heading of its own. The one difference is whose it is: a
     * regulation belongs to a department and a profile to a position.
     *
     * So the tables learn about an owner instead of being copied. A section says
     * which document it belongs to and names its owner in the column for that
     * document's kind of owner; everything else about it — the template, the
     * override, the place in the order, the body and its cleaning — is the same
     * machinery doing the same thing for a second document. A copy of it would
     * drift, and the first sentence to be corrected in one and not the other
     * would be the proof.
     *
     * Two columns with real keys rather than one pair naming a table: a
     * department that is deleted takes its regulation with it and a position
     * that is deleted takes its profile, and that is the database's own doing —
     * a type-and-id pair cannot cascade and would leave the rows of a deleted
     * owner behind for somebody to notice later. What a pair would have given
     * is the one thing kept by hand: a row must not belong to two owners at
     * once, and the check below says so where nothing can talk its way past it.
     */
    /**
     * The pair each table keeps unique for a position, named here because both
     * halves of the migration ask whether it is already there: rolled back on
     * SQLite the owner column stays, and running forward again must find what
     * it left rather than write it twice.
     */
    private const SECTION_OWNER_INDEX = 'regulation_sections_position_id_template_section_id_unique';

    private const ORDER_OWNER_INDEX = 'regulation_orders_position_id_regulation_section_id_unique';

    public function up(): void
    {
        Schema::table('regulation_sections', function (Blueprint $table) {
            // Which document this section is part of. Not the owner's job to
            // say: a section of the template belongs to nobody, so without this
            // the standard text of the regulation and the standard text of the
            // profile would be the same rows of nothing in particular.
            if (! Schema::hasColumn('regulation_sections', 'document')) {
                $table->string('document', 20)->default('regulation')->after('id');
            }

            // The position whose profile this is, empty for every section of a
            // regulation and for the profile's own template. The profile is part
            // of the position, so it goes with it.
            if (! Schema::hasColumn('regulation_sections', 'position_id')) {
                $table->foreignId('position_id')->nullable()->after('department_id')->constrained()->cascadeOnDelete();
            }

            // A section whose text is drawn from what the application already
            // knows rather than typed: «01 ОБЩАЯ ИНФОРМАЦИЯ» of the profile,
            // which is a table of the position's department, name, category and
            // grade. It has no body and never gets one, and the page draws it
            // from the fields the payload carries beside the sections.
            if (! Schema::hasColumn('regulation_sections', 'computed')) {
                $table->boolean('computed')->default(false)->after('body');
            }

            // One owner rewrites one standard section once. The pair for
            // departments is already there; MySQL and SQLite both count NULLs
            // as distinct, so each leaves the template's own rows and the other
            // document's alone.
            if (! Schema::hasIndex('regulation_sections', self::SECTION_OWNER_INDEX)) {
                $table->unique(['position_id', 'template_section_id']);
            }
        });

        Schema::table('regulation_orders', function (Blueprint $table) {
            // Whose arrangement of the document it is, when the document is a
            // profile. It goes with the position.
            if (! Schema::hasColumn('regulation_orders', 'position_id')) {
                $table->foreignId('position_id')->nullable()->after('department_id')->constrained()->cascadeOnDelete();
            }

            if (! Schema::hasIndex('regulation_orders', self::ORDER_OWNER_INDEX)) {
                $table->unique(['position_id', 'regulation_section_id']);
            }
        });

        // An arrangement belongs to one owner, and from now on that owner is a
        // department or a position, so the department stops being required.
        Schema::table('regulation_orders', function (Blueprint $table) {
            $table->unsignedBigInteger('department_id')->nullable()->change();
        });

        // A section belongs to one owner or to nobody — nobody being the
        // template, which is why neither column being filled is allowed here and
        // refused below.
        $this->oneOwner(
            'regulation_sections',
            'department_id is null or position_id is null',
            'Раздел документа принадлежит отделу или должности, но не обоим сразу.',
        );

        // A place in a document is somebody's: an arrangement of nobody's
        // document is the template's own order, which is a column on the
        // section and not a row here.
        $this->oneOwner(
            'regulation_orders',
            '(department_id is null) <> (position_id is null)',
            'Порядок разделов принадлежит ровно одному владельцу — отделу или должности.',
        );
    }

    /**
     * The profile goes, and the regulation comes out of it exactly as it went
     * in.
     *
     * Its rows are deleted rather than left: without the columns below there is
     * nothing to say they are a profile's, and a section of a document that no
     * longer exists would be read as a section of «Положение о работе» that
     * belongs to nobody — which is the template, the text every department
     * reads. Dropping the columns and keeping the rows would quietly add nine
     * empty sections to the company's own document, so the rows go with the
     * columns that gave them their meaning.
     *
     * Everything the regulation has written stays where it is: its sections keep
     * their bodies, its overrides keep their owners and its arrangements keep
     * their order, because nothing here touches a row whose department is
     * filled in.
     *
     * One thing does not come off on SQLite: a column that carries a foreign
     * key. SQLite keeps the key inside the table's own definition and refuses to
     * drop the column out from under it, and taking a key off at all means
     * writing the whole table out again — which is not a thing to do to a
     * document on the way back from a change nobody asked for. So the owner
     * column stays there on SQLite, unused and holding nothing, and up() is
     * written to find it already in place; the real database is MySQL, which
     * drops the key with the column, and SQLite is the one the test suite builds
     * forwards from nothing and never rolls back. The same is already true of
     * the department on a position, in the migration that put it there.
     */
    public function down(): void
    {
        $profiles = DB::table('regulation_sections')->where('document', 'profile')->pluck('id');

        DB::table('regulation_orders')->whereNotNull('position_id')->delete();
        DB::table('regulation_orders')->whereIn('regulation_section_id', $profiles)->delete();
        // The overrides first: a template section is what they point at, and the
        // key that holds them would only set that to null and leave them behind.
        DB::table('regulation_sections')->whereIn('template_section_id', $profiles)->delete();
        DB::table('regulation_sections')->whereIn('id', $profiles)->delete();

        $this->withoutOneOwner('regulation_sections');
        $this->withoutOneOwner('regulation_orders');

        // Required again, and there is nothing left without one: every
        // arrangement a profile had is gone, and a regulation's always named
        // its department.
        Schema::table('regulation_orders', function (Blueprint $table) {
            $table->unsignedBigInteger('department_id')->nullable(false)->change();
        });

        $this->withoutOwner('regulation_orders', self::ORDER_OWNER_INDEX);
        $this->withoutOwner('regulation_sections', self::SECTION_OWNER_INDEX);

        Schema::table('regulation_sections', function (Blueprint $table) {
            $table->dropColumn(['document', 'computed']);
        });
    }

    /**
     * The rule the database keeps itself: a row of this table may not belong to
     * two owners at once.
     *
     * Written as a check where the database has them and as a pair of triggers
     * where it has not, because it is the one invariant a column cannot carry
     * and the one the two separate keys cost us. Which document an owner may own
     * — a department a regulation, a position a profile — is a rule about
     * meaning rather than about shape, and the model keeps that one; this keeps
     * the shape, so no code path, no seeder and no console session can write a
     * row that belongs to a department and a position at the same time.
     */
    private function oneOwner(string $table, string $condition, string $message): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            foreach (['insert', 'update'] as $when) {
                DB::statement(<<<SQL
                    create trigger if not exists {$table}_one_owner_on_{$when}
                    before {$when} on {$table}
                    for each row when not ({$this->of('new.', $condition)})
                    begin select raise(abort, '{$message}'); end
                SQL);
            }

            return;
        }

        if ($this->checks($table) === 0) {
            DB::statement("alter table {$table} add constraint {$table}_one_owner check ({$condition})");
        }
    }

    /** The same rule taken off again, whichever shape it was written in. */
    private function withoutOneOwner(string $table): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            DB::statement("drop trigger if exists {$table}_one_owner_on_insert");
            DB::statement("drop trigger if exists {$table}_one_owner_on_update");

            return;
        }

        if ($this->checks($table) > 0) {
            DB::statement("alter table {$table} drop constraint {$table}_one_owner");
        }
    }

    /**
     * Whether the check is already on this table, so that a migration run twice
     * — rolled back on SQLite, where the owner column stays, and run forward
     * again — does not try to write a rule the table already carries.
     */
    private function checks(string $table): int
    {
        return (int) DB::table('information_schema.check_constraints')
            ->whereRaw('constraint_schema = database()')
            ->where('constraint_name', "{$table}_one_owner")
            ->count();
    }

    /**
     * The same condition written about the row being saved, which is how a
     * trigger has to read it.
     */
    private function of(string $row, string $condition): string
    {
        return preg_replace('/\b(department_id|position_id)\b/', $row.'$1', $condition);
    }

    /**
     * The column that named the owner, with what holds it up: the key before the
     * index, because a foreign key reads through an index and this is the only
     * one it has, and the column last.
     *
     * None of it on SQLite, for the reason down() gives: the key cannot come off
     * without writing the table out again, and the column cannot come off while
     * the key is on it. What stays behind is a column full of nulls that nothing
     * reads.
     */
    private function withoutOwner(string $table, string $unique): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($table, $unique) {
            $blueprint->dropForeign(['position_id']);

            if (Schema::hasIndex($table, $unique)) {
                $blueprint->dropUnique($unique);
            }

            $blueprint->dropColumn('position_id');
        });
    }
};
