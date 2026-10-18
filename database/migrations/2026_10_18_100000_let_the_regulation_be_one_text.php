<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What the document is called: the words a sheet of it begins with, before
     * the name of the department it is written for, and the name given to the
     * one section it is left as, which nothing draws any more.
     */
    private const TITLE = 'ПОЛОЖЕНИЕ О РАБОТЕ';

    /**
     * «Положение о работе» was a heading in a field of its own and four
     * numbered sections — ОБЩЕЕ ПОЛОЖЕНИЕ, ПРАВА, ОБЯЗАННОСТИ,
     * ОТВЕТСТВЕННОСТЬ — and the company asked for one document instead: the
     * whole sheet written in one go, heading included. So the document becomes
     * a document of one section, and everything that was drawn around it
     * becomes a line of the text inside it.
     *
     * Nothing about the machinery changes for that. A section is still a
     * heading and a body, a department still reads the template until it writes
     * its own wording and still puts the standard text back the same way, and
     * «Профиль должности» beside it still has its nine sections and its own
     * heading in a field. What changes is how many rows the regulation's
     * template holds, which is one, and that the page draws nothing of its own
     * around it — no heading, no numbers, no titles. They are in the text now,
     * where whoever writes the document can move and renumber them.
     *
     * The words are what matters here, and every one of them is carried across
     * in the order it was read in:
     *
     * - the heading of the sheet becomes the first line of the body, an <h2>
     *   ahead of everything, the way a printed sheet opens with its own name;
     * - each old section becomes «01 ОБЩЕЕ ПОЛОЖЕНИЕ» as an <h2> after it,
     *   followed by the clauses that stood under it.
     *
     * Both are allowed by the body's allowlist already. The numbers are typed
     * into the headings once, from the place each section was read in, and from
     * then on nothing counts or renumbers anything.
     *
     * The template's heading is written with the placeholder — «ПОЛОЖЕНИЕ О
     * РАБОТЕ {подразделение}» — which is exactly what the placeholders are for:
     * one text is read by twenty-six departments and each of them reads its own
     * name there. A department that typed a heading of its own reads its own
     * words instead, and for that it needs a text of its own, so it is given
     * one: the standard text with its heading at the top of it. That is the one
     * thing this migration writes that nobody wrote before, and the alternative
     * was throwing away a line somebody typed by hand.
     *
     * It costs that department something, and the cost is said out loud rather
     * than hidden: a department reading a text of its own is no longer reading
     * the company's, so a correction made to the standard clauses stops
     * reaching it — which its page says, with «Вернуть стандартный текст»
     * beside it, and which is exactly what a department that had typed its own
     * heading was asking for anyway.
     *
     * A department that had rewritten some of the sections is merged the same
     * way, and merged out of the document it actually read: its own wording
     * where it wrote one and the standard wording everywhere else. There is one
     * section left to read, so a department reads either its own text or the
     * template's, and a merge of its overrides alone would have dropped the
     * standard parts out of its sheet.
     *
     * What does not survive is an arrangement: a document of one section has no
     * order to keep, so the rows saying where a department read which section
     * go. No words go with them — the sections those rows named are in the body
     * now, in the order that department read them.
     */
    public function up(): void
    {
        $this->fold();

        // And with every heading inside a text, the column that held them has
        // no reader left. The profile keeps its own: it is still nine sections
        // under a heading of its own, and only this document was asked to
        // become one file.
        if (Schema::hasColumn('departments', 'regulation_title')) {
            Schema::table('departments', fn (Blueprint $table) => $table->dropColumn('regulation_title'));
        }
    }

    /**
     * The heading back in a field and the sections back out of the body: every
     * <h2> in it is a section, what follows it is that section's text, and the
     * first one — the one with nothing under it — is the heading of the sheet.
     *
     * Three things do not come back. An arrangement is gone for good: up() had
     * no order left to keep and nothing here can invent one, so the sections
     * read in the order they stand in the text, which is the order that
     * department read them in. A department that had rewritten two of the four
     * comes back having rewritten all four, because its merged body held the
     * standard parts as well and nothing in it says which of the words were its
     * own. And a department given a text of its own for the sake of a heading
     * it had typed keeps that text: its heading goes back into the column, and
     * the standard clauses under it are now its own copy of them.
     *
     * Its wording is all there either way, which is the thing that would have
     * been worth refusing a rollback over.
     *
     * A body somebody has edited since is split by the headings it has now, and
     * a text with no heading at all comes back as one section under the title
     * its row already carried.
     */
    public function down(): void
    {
        if (! Schema::hasColumn('departments', 'regulation_title')) {
            Schema::table('departments', function (Blueprint $table) {
                $table->string('regulation_title', 200)->nullable()->after('abbreviation');
            });
        }

        $this->unfold();
    }

    /** The whole document, department by department, written as one text. */
    private function fold(): void
    {
        $rows = DB::table('regulation_sections')->where('document', 'regulation')->get();
        $template = $this->reading($rows->whereNull('department_id')->whereNull('position_id'), []);
        $own = $rows->whereNotNull('department_id')->groupBy('department_id');

        // A template already folded is left exactly as it is. Its one row opens
        // with the name of the sheet — a heading with nothing of its own under
        // it, which is what split() reads back as that name — and folding it
        // again would write the name a second time and number the first one as
        // a section of the document. This is the whole of what keeps a second
        // run harmless: a run over a half-finished one, where the bodies are
        // folded and the column has been put back, stops here.
        //
        // A standard section whose text is nothing but a heading would be read
        // as that name too, and such a document is left unmerged rather than
        // merged wrongly — a visible nothing, not a lost clause.
        if ($template->isEmpty() || $this->named($this->split($template->first())) !== null) {
            return;
        }

        // The departments this has anything to write for: the ones that had
        // rewritten part of the document, and the ones that had typed a heading
        // of their own, which is a line of the text now and so needs a text to
        // be a line of.
        $titles = Schema::hasColumn('departments', 'regulation_title')
            ? DB::table('departments')->whereNotNull('regulation_title')->pluck('regulation_title', 'id')
            : collect();
        $names = DB::table('departments')
            ->whereIn('id', $own->keys()->merge($titles->keys()))
            ->pluck('name', 'id');

        $places = DB::table('regulation_orders')
            ->whereNotNull('department_id')
            ->whereIn('regulation_section_id', $rows->pluck('id'))
            ->get()
            ->groupBy('department_id')
            ->map(fn (Collection $rows) => $rows->pluck('order', 'regulation_section_id')->all());

        $standard = $template->first();

        DB::transaction(function () use ($rows, $template, $own, $places, $titles, $names, $standard) {
            foreach ($own->keys()->merge($titles->keys())->map(fn (int|string $id) => (int) $id)->unique() as $department) {
                $instead = ($own[$department] ?? collect())
                    ->whereNotNull('template_section_id')
                    ->keyBy(fn (object $section) => (int) $section->template_section_id);

                // The document as that department read it: the template with
                // its wording in the place of every section it rewrote, plus
                // anything a dropped standard section left it, in its own
                // order — under the heading it read at the top of the sheet.
                $read = $this->reading(
                    $template
                        ->map(fn (object $section) => $instead->get((int) $section->id) ?? $section)
                        ->merge(($own[$department] ?? collect())->whereNull('template_section_id')),
                    $places[$department] ?? [],
                );

                $body = $this->titled($this->heading($titles[$department] ?? null, $names[$department] ?? ''), $this->merged($read));

                // The text goes into one of the department's own rows, and the
                // rest of them go before it is written: a department may name
                // each standard section once, and the row kept is about to name
                // the only standard section left. A department that had none
                // gets one written for it.
                $keep = $instead->get((int) $standard->id) ?? ($own[$department] ?? collect())->first();

                if ($keep === null) {
                    DB::table('regulation_sections')->insert([
                        'document' => 'regulation',
                        'department_id' => $department,
                        'position_id' => null,
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

                $this->drop($own[$department], $keep->id);

                DB::table('regulation_sections')->where('id', $keep->id)->update([
                    'template_section_id' => $standard->id,
                    'title' => self::TITLE,
                    'body' => $body,
                    'order' => $standard->order,
                ]);
            }

            // The template's surplus rows go after the departments', so that
            // nothing is left pointing at a section being deleted: dropping a
            // standard section sets the link on every wording of it to null,
            // and by now there is no such wording left to be cut loose.
            $this->drop($template, $standard->id);

            DB::table('regulation_sections')->where('id', $standard->id)->update([
                'title' => self::TITLE,
                // The one heading nobody typed: the name of the document and a
                // placeholder, so that every department reads its own name
                // there and one text goes on serving all of them.
                'body' => $this->titled(self::TITLE.' {подразделение}', $this->merged($template)),
            ]);

            DB::table('regulation_orders')->whereIn('regulation_section_id', $rows->pluck('id'))->delete();
        });
    }

    /** The one text read back as a heading and the sections under it. */
    private function unfold(): void
    {
        $rows = DB::table('regulation_sections')->where('document', 'regulation')->get();
        $standard = $rows->whereNull('department_id')->whereNull('position_id')->sortBy('order')->first();

        if ($standard === null) {
            return;
        }

        DB::transaction(function () use ($rows, $standard) {
            $parts = $this->split($standard);

            // The template's own first line was the name of the document with a
            // placeholder in it, which nothing stored before and nothing has to
            // store again: every department worked its heading out from its own
            // name until it typed one.
            $template = $this->sections($parts);

            // The template first, so that every wording of a standard section
            // has a standard section to name.
            $ids = $this->rewrite($standard, $template, fn (int $place) => [
                'template_section_id' => null,
                // A hundred apart, which leaves room between any two standard
                // sections for one written later.
                'order' => ($place + 1) * 100,
            ]);

            // Which standard section each heading is. A department's headings
            // read the same as the template's, because up() wrote both out of
            // the same sections; one that reads as nothing in the template is a
            // wording of a section the company no longer has, which is a shape
            // the document already allows for.
            $standards = $template->pluck('title')->combine($ids)->all();

            foreach ($rows->whereNotNull('department_id') as $own) {
                $parts = $this->split($own);
                $named = $this->named($parts);

                // The first line is the heading of the sheet, which goes back
                // into the column it was typed in.
                if ($named !== null) {
                    DB::table('departments')->where('id', $own->department_id)->update(['regulation_title' => $named]);
                }

                $parts = $this->sections($parts);

                // A text that was nothing but its own name leaves nothing to be
                // a section of: the words are in the column now.
                if ($parts->isEmpty()) {
                    DB::table('regulation_sections')->where('id', $own->id)->delete();

                    continue;
                }

                $this->rewrite($own, $parts, fn (int $place) => [
                    'template_section_id' => $standards[$parts[$place]->title] ?? null,
                    'order' => ($place + 1) * 100,
                ]);
            }
        });
    }

    /**
     * The name of the sheet, as the first line of a body says it — and nothing
     * where that line is not a name at all.
     *
     * up() writes the name as a heading with no text of its own under it, so a
     * first heading that does carry text is a section like any other and its
     * words stay in the document. Which matters: a department that rewrote the
     * whole thing and kept no heading would otherwise have its text read as a
     * title and thrown away.
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
     * The heading one department read at the top of its sheet: what it typed,
     * or the name of the document and its own name upper-cased, which is what
     * it was shown while it had typed nothing.
     *
     * Taken exactly as it stands. The column is read once, by this, and is
     * dropped in the same run, so there is no second pass for a heading to be
     * folded into a body that already carries it.
     */
    private function heading(?string $typed, string $name): string
    {
        $own = trim((string) $typed);

        return $own === '' ? self::TITLE.' '.mb_strtoupper($name) : $own;
    }

    /** The document under its own name, which is the first line of it now. */
    private function titled(string $heading, string $body): string
    {
        return '<h2>'.e($heading).'</h2>'.$body;
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
     * <h2>, numbered by the place it was read in, with the text that stood
     * under it after it.
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
     * One body read back as headings and what stands under each: the first of
     * them is the name of the sheet, with nothing under it, and the rest are
     * its sections. Anything standing before the first heading is kept under
     * it, because it is text somebody wrote and the alternative is throwing it
     * away.
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
     * @param  callable(int): array<string, mixed>  $extra  what the row at this place says of itself
     * @return list<int> the id each section ended up as, in the order they are read
     */
    private function rewrite(object $row, Collection $parts, callable $extra): array
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
                'document' => 'regulation',
                'department_id' => $row->department_id,
                'position_id' => null,
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
