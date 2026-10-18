<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** What the one section left is called, which nothing draws any more. */
    private const TITLE = 'ПРОФИЛЬ ДОЛЖНОСТИ';

    /** The section that was never text, and is not part of the document now. */
    private const FROM_DATA = 'ОБЩАЯ ИНФОРМАЦИЯ';

    /**
     * Where each kind of owner is named, and the words its sheet opens with.
     *
     * @var array<string, array{table: string, opening: string}>
     */
    private const OWNERS = [
        'department_id' => ['table' => 'departments', 'opening' => 'ПРОФИЛЬ ПОДРАЗДЕЛЕНИЯ'],
        'position_id' => ['table' => 'positions', 'opening' => 'ПРОФИЛЬ ДОЛЖНОСТИ'],
    ];

    /**
     * «Профиль должности», the sheet the company calls СФД, was a heading in a
     * field and nine sections. The company asked for it to be what «Положение о
     * работе» became: one text, written in one go, heading included — and for
     * «01 ОБЩАЯ ИНФОРМАЦИЯ» to leave the document altogether.
     *
     * So three things happen here, and only one of them touches anybody's
     * words.
     *
     * The eight written parts — ДОЛЖНОСТНЫЕ ОБЯЗАННОСТИ, ОБРАЗОВАНИЕ И ОПЫТ
     * РАБОТЫ, ЗНАНИЕ ЯЗЫКОВ, ЖЕСТКИЕ НАВЫКИ, МЯГКИЕ НАВЫКИ, КРИТЕРИИ ОЦЕНКИ
     * ЭФФЕКТИВНОСТИ РАБОТЫ, ОТВЕТСТВЕННОСТЬ ЗА ДОКУМЕНТЫ ФАЙЛЫ, ОЗНАКОМЛЕНИЕ —
     * become headings inside one body, in the order they were read, each
     * numbered once from the place it stood in. Whatever stood under them comes
     * with them.
     *
     * The heading of the sheet becomes the first line of that body. The
     * template writes it as {заголовок}, the one placeholder this document has:
     * a job's sheet and a unit's are the same template and the opening differs,
     * so the line has to read «ПРОФИЛЬ ДОЛЖНОСТИ Аналитик» to one reader and
     * «ПРОФИЛЬ ПОДРАЗДЕЛЕНИЯ Отдел дизайна» to another. An owner that typed a
     * heading of its own reads its own words instead, and for that it needs a
     * text of its own, so it is given one — the standard headings under its
     * heading. That owner stops reading the company's text from then on, which
     * is the price of not throwing away a line somebody typed by hand.
     *
     * «01 ОБЩАЯ ИНФОРМАЦИЯ» is dropped rather than merged. It was never
     * written: it is where the thing sits, who leads it, how many work there —
     * all of it read from the directory — and it is drawn in the column beside
     * the document now, where the page already says most of it. Nothing is lost
     * with the row, because nothing was ever stored in it; the fields are read
     * out of the structure exactly as they were.
     *
     * And with every heading inside a text, the columns that held them have no
     * reader left, so both go.
     *
     * None of this is shared with the regulation's migration, which did the
     * same work a day earlier. A migration is a snapshot of what the database
     * was and what it became; two of them reading one helper would both change
     * the day somebody edited it.
     */
    public function up(): void
    {
        $this->fold();

        foreach (self::OWNERS as ['table' => $table]) {
            if (Schema::hasColumn($table, 'profile_title')) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropColumn('profile_title'));
            }
        }
    }

    /**
     * The heading back into a field, the sections back out of the body, and the
     * section drawn from data back at the top of the document.
     *
     * Three things do not come back. An arrangement is gone for good: up() had
     * no order left to keep. An owner that had rewritten part of the document
     * comes back having rewritten all of it, because its merged body held the
     * standard headings too and nothing in it says which were its own. And an
     * owner given a text of its own for the sake of a heading it had typed
     * keeps that text: the heading goes back into the column, and the empty
     * headings under it are now its own copy of them.
     *
     * Its wording is all there either way, which is the thing that would have
     * been worth refusing a rollback over.
     */
    public function down(): void
    {
        foreach (self::OWNERS as ['table' => $table]) {
            if (! Schema::hasColumn($table, 'profile_title')) {
                Schema::table($table, function (Blueprint $blueprint) use ($table) {
                    $after = $table === 'departments' ? 'abbreviation' : 'name';

                    $blueprint->string('profile_title', 200)->nullable()->after($after);
                });
            }
        }

        $this->unfold();
    }

    /** The whole sheet, owner by owner, written as one text. */
    private function fold(): void
    {
        $rows = DB::table('regulation_sections')->where('document', 'profile')->get();
        $template = $this->reading($rows->whereNull('department_id')->whereNull('position_id'), []);

        // A template already folded is left exactly as it is: folding it again
        // would write the name of the sheet a second time and number the first
        // one as a part of the document. This is what keeps a second run
        // harmless.
        if ($template->isEmpty() || $this->folded($template)) {
            return;
        }

        // The part that was never text leaves the document; what is written is
        // what is merged.
        $written = $template->reject(fn (object $section) => (bool) $section->computed)->values();

        if ($written->isEmpty()) {
            return;
        }

        $standard = $written->first();
        $places = DB::table('regulation_orders')
            ->whereIn('regulation_section_id', $rows->pluck('id'))
            ->get();

        DB::transaction(function () use ($rows, $template, $written, $places, $standard) {
            foreach (self::OWNERS as $column => ['table' => $table, 'opening' => $opening]) {
                $own = $rows->whereNotNull($column)->groupBy($column);
                $titles = Schema::hasColumn($table, 'profile_title')
                    ? DB::table($table)->whereNotNull('profile_title')->pluck('profile_title', 'id')
                    : collect();
                $names = DB::table($table)
                    ->whereIn('id', $own->keys()->merge($titles->keys()))
                    ->pluck('name', 'id');
                $arranged = $places->whereNotNull($column)
                    ->groupBy($column)
                    ->map(fn (Collection $rows) => $rows->pluck('order', 'regulation_section_id')->all());

                foreach ($own->keys()->merge($titles->keys())->map(fn (int|string $id) => (int) $id)->unique() as $owner) {
                    $instead = ($own[$owner] ?? collect())
                        ->whereNotNull('template_section_id')
                        ->keyBy(fn (object $section) => (int) $section->template_section_id);

                    // The document as that owner read it: the written parts of
                    // the template with its wording in the place of each one it
                    // rewrote, plus anything a dropped standard part left it,
                    // in its own order — under the heading it read at the top.
                    $read = $this->reading(
                        $written
                            ->map(fn (object $section) => $instead->get((int) $section->id) ?? $section)
                            ->merge(($own[$owner] ?? collect())->whereNull('template_section_id')),
                        $arranged[$owner] ?? [],
                    );

                    $body = $this->titled(
                        $this->heading($titles[$owner] ?? null, $opening, (string) ($names[$owner] ?? '')),
                        $this->merged($read),
                    );

                    // The text goes into one of the owner's own rows, and the
                    // rest of them go before it is written: an owner may name
                    // each standard section once, and the row kept is about to
                    // name the only one left. An owner that had none gets one
                    // written for it.
                    $keep = $instead->get((int) $standard->id) ?? ($own[$owner] ?? collect())->first();

                    if ($keep === null) {
                        DB::table('regulation_sections')->insert([
                            'document' => 'profile',
                            'department_id' => $column === 'department_id' ? $owner : null,
                            'position_id' => $column === 'position_id' ? $owner : null,
                            'template_section_id' => $standard->id,
                            'title' => self::TITLE,
                            'body' => $body,
                            'computed' => false,
                            'order' => $standard->order,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);

                        continue;
                    }

                    $this->drop($own[$owner], $keep->id);

                    DB::table('regulation_sections')->where('id', $keep->id)->update([
                        'template_section_id' => $standard->id,
                        'title' => self::TITLE,
                        'body' => $body,
                        'order' => $standard->order,
                    ]);
                }
            }

            // The template's surplus rows go after the owners', so that nothing
            // is left pointing at a section being deleted: dropping a standard
            // section sets the link on every wording of it to null, and by now
            // there is no such wording left to be cut loose. The section drawn
            // from data goes with them.
            $this->drop($template, $standard->id);

            DB::table('regulation_sections')->where('id', $standard->id)->update([
                'title' => self::TITLE,
                // The one heading nobody typed: the name of the sheet as a
                // placeholder, because this one text is read by a job and by a
                // unit and each has to read its own opening there.
                'body' => $this->titled('{заголовок}', $this->merged($written)),
            ]);

            DB::table('regulation_orders')->whereIn('regulation_section_id', $rows->pluck('id'))->delete();
        });
    }

    /** The one text read back as a heading, a drawn section and the parts under them. */
    private function unfold(): void
    {
        $rows = DB::table('regulation_sections')->where('document', 'profile')->get();
        $template = $this->reading($rows->whereNull('department_id')->whereNull('position_id'), []);

        // Nothing folded is nothing to unfold. A rollback of a run that did not
        // fold anything — because it found the document already one text, or
        // found nothing at all — leaves the rows exactly where they are rather
        // than writing a second section drawn from data in front of them.
        if (! $this->folded($template)) {
            return;
        }

        $standard = $template->first();

        DB::transaction(function () use ($rows, $standard) {
            $parts = $this->split($standard);

            // The template's own first line was the name of the sheet written
            // as a placeholder, which nothing stored before and nothing has to
            // store again: every owner worked its heading out from its own name
            // until it typed one.
            $template = $this->sections($parts);

            $ids = $this->rewrite($standard, $template, null, fn (int $place) => [
                'template_section_id' => null,
                // A hundred apart, and starting at two hundred: the section
                // drawn from data is written back in front of them.
                'order' => ($place + 2) * 100,
            ]);

            DB::table('regulation_sections')->insert([
                'document' => 'profile',
                'department_id' => null,
                'position_id' => null,
                'template_section_id' => null,
                'title' => self::FROM_DATA,
                'body' => null,
                'computed' => true,
                'order' => 100,
                'created_at' => $standard->created_at,
                'updated_at' => $standard->updated_at,
            ]);

            // Which standard section each heading is. An owner's headings read
            // the same as the template's, because up() wrote both out of the
            // same sections; one that reads as nothing in the template is a
            // wording of a section the company no longer has, which is a shape
            // the document already allows for.
            $standards = $template->pluck('title')->combine($ids)->all();

            foreach (self::OWNERS as $column => ['table' => $table]) {
                foreach ($rows->whereNotNull($column) as $own) {
                    $parts = $this->split($own);
                    $named = $this->named($parts);

                    // The first line is the heading of the sheet, which goes
                    // back into the column it was typed in.
                    if ($named !== null) {
                        DB::table($table)->where('id', $own->{$column})->update(['profile_title' => $named]);
                    }

                    $parts = $this->sections($parts);

                    // A text that was nothing but its own name leaves nothing
                    // to be a section of: the words are in the column now.
                    if ($parts->isEmpty()) {
                        DB::table('regulation_sections')->where('id', $own->id)->delete();

                        continue;
                    }

                    $this->rewrite($own, $parts, $column, fn (int $place) => [
                        'template_section_id' => $standards[$parts[$place]->title] ?? null,
                        'order' => ($place + 2) * 100,
                    ]);
                }
            }
        });
    }

    /**
     * The heading one owner read at the top of its sheet: what it typed, or the
     * opening its kind of sheet has and its own name upper-cased, which is what
     * it was shown while it had typed nothing.
     *
     * Taken exactly as it stands. The columns are read once, by this, and are
     * dropped in the same run, so there is no second pass for a heading to be
     * folded into a body that already carries it.
     */
    private function heading(?string $typed, string $opening, string $name): string
    {
        $own = trim((string) $typed);

        return $own === '' ? $opening.' '.mb_strtoupper($name) : $own;
    }

    /** The document under its own name, which is the first line of it now. */
    private function titled(string $heading, string $body): string
    {
        return '<h2>'.e($heading).'</h2>'.$body;
    }

    /**
     * Whether this template is the one text it becomes here: one row, whose
     * body carries headings, the first of them with nothing of its own under it.
     *
     * All three are asked, and the middle one is the reason. The parts of this
     * document are seeded empty — there is not a word under any of them — so a
     * row with no body at all is the ordinary state of an unfolded one, and
     * split() reads such a row back as its own title with nothing under it,
     * which reads exactly like the name of a sheet. Asking that the body
     * actually hold a heading is what tells the two apart.
     *
     * @param  Collection<int, object>  $template
     */
    private function folded(Collection $template): bool
    {
        $first = $template->first();

        return $template->count() === 1
            && $first !== null
            && str_contains((string) $first->body, '<h2')
            && $this->named($this->split($first)) !== null;
    }

    /**
     * The name of the sheet, as the first line of a body says it — and nothing
     * where that line is not a name at all.
     *
     * up() writes the name as a heading with no text of its own under it, so a
     * first heading that does carry text is a part of the document like any
     * other and its words stay in it.
     *
     * @param  Collection<int, object>  $parts
     */
    private function named(Collection $parts): ?string
    {
        $first = $parts->first();

        return $first !== null && trim($first->body) === '' && trim($first->title) !== '' ? $first->title : null;
    }

    /**
     * The same parts without the one that is the name of the sheet.
     *
     * @param  Collection<int, object>  $parts
     * @return Collection<int, object>
     */
    private function sections(Collection $parts): Collection
    {
        return $this->named($parts) === null ? $parts : $parts->skip(1)->values();
    }

    /**
     * Where each section is read: where its owner put it, and failing that by
     * the section's own order and then by age — the same reading
     * RegulationSection assembles a document by, said again here because a
     * migration that went through the model would be a migration of whatever
     * the model means next year.
     *
     * @param  Collection<int, object>  $sections
     * @param  array<int, int>  $places  the section stood in for => where it was read
     * @return Collection<int, object>
     */
    private function reading(Collection $sections, array $places): Collection
    {
        return $sections
            ->sortBy(fn (object $section) => [
                $places[$section->template_section_id ?? $section->id] ?? PHP_INT_MAX,
                $section->order,
                $section->id,
            ])
            ->values();
    }

    /**
     * The sections as one body: each heading written out as the document's own
     * <h2>, numbered by the place it was read in, with whatever stood under it
     * after it.
     *
     * @param  Collection<int, object>  $read
     */
    private function merged(Collection $read): string
    {
        return $read
            ->map(fn (object $section, int $place) => '<h2>'
                .str_pad((string) ($place + 1), 2, '0', STR_PAD_LEFT).' '
                .e(trim((string) $section->title))
                .'</h2>'.(string) $section->body)
            ->implode('');
    }

    /**
     * One body read back as headings and what stands under each. Anything
     * standing before the first heading is kept under it, because it is text
     * somebody wrote and the alternative is throwing it away.
     *
     * @return Collection<int, object>
     */
    private function split(object $section): Collection
    {
        $body = (string) $section->body;

        if (! preg_match_all('/<h2\b[^>]*>(.*?)<\/h2>(.*?)(?=<h2\b|$)/su', $body, $found, PREG_SET_ORDER)) {
            return collect([(object) ['title' => (string) $section->title, 'body' => $body]]);
        }

        $preamble = substr($body, 0, (int) strpos($body, '<h2'));

        return collect($found)->map(fn (array $part, int $place) => (object) [
            // The heading as words, without the number up() typed in front of
            // it: that number was the place the section was read in.
            'title' => preg_replace(
                '/^\d+\s+/u',
                '',
                trim(html_entity_decode(strip_tags($part[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8')),
            ),
            'body' => ($place === 0 ? $preamble : '').$part[2],
        ])->values();
    }

    /**
     * One row written as several: the first section goes back into the row that
     * held them all, and the rest are written beside it, each the same kind of
     * row — the same document, the same owner, as old as the row it came out of.
     *
     * @param  Collection<int, object>  $parts
     * @param  string|null  $column  which column names this row's owner, if any
     * @param  callable(int): array<string, mixed>  $extra  what the row at this place says of itself
     * @return list<int> the id each section ended up as, in the order they are read
     */
    private function rewrite(object $row, Collection $parts, ?string $column, callable $extra): array
    {
        $ids = [];

        foreach ($parts as $place => $part) {
            $said = ['title' => $part->title, 'body' => $part->body === '' ? null : $part->body, ...$extra($place)];

            if ($place === 0) {
                DB::table('regulation_sections')->where('id', $row->id)->update($said);

                $ids[] = (int) $row->id;

                continue;
            }

            $ids[] = DB::table('regulation_sections')->insertGetId([
                'document' => 'profile',
                'department_id' => $column === 'department_id' ? $row->department_id : null,
                'position_id' => $column === 'position_id' ? $row->position_id : null,
                'computed' => false,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
                ...$said,
            ]);
        }

        return $ids;
    }

    /**
     * Every one of these rows but the one being kept.
     *
     * @param  Collection<int, object>  $rows
     */
    private function drop(Collection $rows, int|string $keep): void
    {
        $gone = $rows->pluck('id')->reject(fn (int|string $id) => (int) $id === (int) $keep)->all();

        if ($gone !== []) {
            DB::table('regulation_sections')->whereIn('id', $gone)->delete();
        }
    }
};
