<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** What the opening line of the sheet used to be written as, and what it is now. */
    private const WAS = '<h2>ПОЛОЖЕНИЕ О РАБОТЕ {подразделение}</h2>';

    private const NOW = '<h2>{заголовок}</h2>';

    /**
     * A position keeps a «Положение о работе» of its own now, and the standard
     * text has to be readable by one.
     *
     * Nothing about the rows changes: the document is owned by a position the
     * way it is owned by a department, which is a rule in the application
     * rather than a column, and the table has had a position_id since the
     * profile needed one. What changes is one line of the company's text.
     *
     * Its first line said «ПОЛОЖЕНИЕ О РАБОТЕ {подразделение}», which answers
     * the name of a unit and nothing else — a job reading it would be headed
     * with the name of the department it is filed in, or with a dash. So it is
     * written as {заголовок} instead: the one placeholder that stands for the
     * whole opening line, answered «ПОЛОЖЕНИЕ О РАБОТЕ Отдел Дизайна» for a
     * unit and «ПОЛОЖЕНИЕ О РАБОТЕ Аналитик (ОАП)» for a job. The same answer
     * «Профиль должности» already uses, for the same collision.
     *
     * Only the template's line is touched, and only while it still says what
     * the company was given. A department that wrote its own heading wrote
     * words, not a token, and those words are its own — they stay exactly as
     * they are, and so does any text somebody has edited since.
     */
    public function up(): void
    {
        $this->rewrite(self::WAS, self::NOW);
    }

    /**
     * The placeholder back to the one it replaced.
     *
     * A position's own wording goes on saying whatever it says: this only ever
     * touches the shared text, and a position that rewrote it owns what it
     * wrote. Rolled back, such a row is a wording of a document that no longer
     * admits its kind of owner — the rows stay, and the application stops
     * reading them.
     */
    public function down(): void
    {
        $this->rewrite(self::NOW, self::WAS);
    }

    /**
     * The template's opening line, written one way for the other — and left
     * alone wherever it is not the line this migration knows.
     */
    private function rewrite(string $from, string $to): void
    {
        $template = DB::table('regulation_sections')
            ->where('document', 'regulation')
            ->whereNull('department_id')
            ->whereNull('position_id')
            ->get(['id', 'body']);

        foreach ($template as $row) {
            if (! str_starts_with((string) $row->body, $from)) {
                continue;
            }

            DB::table('regulation_sections')
                ->where('id', $row->id)
                ->update(['body' => $to.substr((string) $row->body, strlen($from))]);
        }
    }
};
