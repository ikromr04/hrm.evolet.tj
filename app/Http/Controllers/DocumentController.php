<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\Position;
use App\Models\RegulationOrder;
use App\Models\RegulationSection;
use App\Support\Document;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Writing one of the company's documents: the shared standard text, and what
 * one owner keeps of its own.
 *
 * It serves no address itself. «Положение о работе» belongs to a department and
 * «Профиль должности» to a position, and apart from that the two are written
 * the same way down to the sentence: the template is the document, an owner
 * rewrites a standard section and puts the standard text back, nobody adds a
 * section or takes one out, the body is cleaned to an allowlist once where it is
 * written, and where a section is read is the owner's own and kept apart from
 * what it says. So the two addresses are two subclasses of this, each saying
 * which document it writes, whose it is, and in what words it refuses — and a
 * correction to any of the rules below reaches both documents, which is the
 * whole point of their being one class.
 *
 * Each subclass serves two addresses, as the regulation always did: the
 * document of no owner — the template, the sentences everybody reads, and the
 * only address the standard sections themselves are added to or dropped from —
 * and the document of one owner, its wording of the standard sections it
 * rewrote. Which address a section is written at decides who the change
 * reaches, and that is the whole difference between correcting a sentence for
 * the company and correcting it for one department or one position.
 */
abstract class DocumentController extends Controller
{
    /**
     * A section whose text is not written but read from the directory —
     * «01 ОБЩАЯ ИНФОРМАЦИЯ» of «Профиль должности», which is a table of the
     * position's department, name, category and grade.
     *
     * There is nothing to type in it and nothing to rewrite: a body under that
     * heading would be a second answer to the same question, disagreeing with
     * the first the week somebody is moved.
     */
    private const FROM_DATA = 'Раздел «%s» заполняется из системы — его текст не пишут и не переопределяют.';

    /** Which of the documents these addresses write. */
    abstract protected function document(): string;

    /** The column its owner keeps the heading of the printed sheet in. */
    abstract protected function titleColumn(): string;

    /**
     * What the heading of the sheet is called where a form names the field it
     * refused — «заголовок положения», «заголовок профиля должности».
     */
    abstract protected function heading(): string;

    /**
     * What an owner is told when it asks for a section the document does not
     * have: one sentence for a section it would add, one for a section it would
     * take out, one for a rearrangement that does not name the whole document,
     * one for a template trying to override itself, and one for a heading
     * longer than the sheet has room for.
     *
     * The page offers none of the first four any more, so they answer a request
     * that reached us some other way — and they say what the document is rather
     * than that something went wrong, because that is the actual reason.
     *
     * @return array{added: string, taken_out: string, arranged: string, overridden: string, too_long: string}
     */
    abstract protected function refusals(): array;

    /**
     * An owner's own wording of a standard section, or — at the template's
     * address — a standard section itself.
     *
     * Rewriting a standard section is this with template_section_id named: the
     * standard text is copied as it stands, and from then on this owner reads
     * its copy in that section's place. It keeps the order of the section it
     * stands for, because it is read where that section was read.
     *
     * So an owner always names one, and a save that names none is refused
     * rather than quietly filed as a section of this owner's own: the document
     * is the standard sections, and nothing is added to it here.
     */
    protected function writeSection(Request $request, Department|Position|null $owner): RedirectResponse
    {
        $data = $this->validated($request, $owner);

        $standard = isset($data['template_section_id'])
            ? RegulationSection::findOrFail($data['template_section_id'])
            : null;

        if ($standard?->computed) {
            throw ValidationException::withMessages(['template_section_id' => sprintf(self::FROM_DATA, $standard->title)]);
        }

        RegulationSection::create([
            'document' => $this->document(),
            $this->owns() => $owner?->id,
            'template_section_id' => $standard?->id,
            'title' => $data['title'] ?? $standard?->title,
            // Nothing was typed, so a new section starts empty — and a
            // rewritten one starts from the standard text, which is what
            // somebody about to rewrite it wants in front of them.
            'body' => array_key_exists('body', $data) ? $data['body'] : $standard?->body,
            'order' => $standard?->order ?? $data['order'] ?? $this->atTheEnd($owner),
        ]);

        return back();
    }

    /**
     * The heading this owner prints the document under, typed by hand because
     * it wants the name of the department or the position in a case nothing here
     * invents.
     *
     * Emptied, the column is emptied rather than filled with an empty string: a
     * sheet with no heading is not a thing anybody wants, and what comes back
     * instead is the heading worked out from the name — the state the owner was
     * in before anybody typed. So clearing the field means «back to the default»
     * and never «no heading».
     *
     * One owner only: the heading belongs to the owner, and the template has no
     * sheet of its own to head.
     */
    protected function nameDocument(Request $request, Department|Position $owner): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['present', 'nullable', 'string', 'max:'.Document::TITLE_LENGTH],
        ], messages: [
            'title.max' => $this->refusals()['too_long'],
        ], attributes: [
            'title' => $this->heading(),
        ]);

        $typed = trim((string) ($data['title'] ?? ''));

        $owner->update([$this->titleColumn() => $typed === '' ? null : $typed]);

        return back();
    }

    protected function rewriteSection(Request $request, Department|Position|null $owner, ?RegulationSection $section): RedirectResponse
    {
        $section = $this->belongsHere($section, $owner);

        $data = $this->validated($request, $owner, $section);

        if ($section->computed && ($data['body'] ?? null) !== null) {
            throw ValidationException::withMessages(['body' => sprintf(self::FROM_DATA, $section->title)]);
        }

        DB::transaction(function () use ($section, $data) {
            $section->update(array_intersect_key($data, array_flip(['title', 'body', 'order'])));

            // An owner's wording is read where the standard section is read, so
            // moving that section in the template moves every owner's wording
            // of it with it.
            if ($section->ownerId() === null && $section->wasChanged('order')) {
                $section->overrides()->update(['order' => $section->order]);
            }
        });

        return back();
    }

    /**
     * An owner dropping its own wording of a standard section reads the
     * standard text again — «Вернуть стандартный текст», the way back to the
     * shared wording, and the only deletion an owner's page has.
     *
     * Anything else at that address is refused. A standard section is not one
     * owner's to take out of the document, and a section that is nobody's
     * wording of a standard one is a section the document is not supposed to
     * have; the one way such a row can still appear is the company dropping a
     * standard section while an owner had rewritten it, and that wording is the
     * owner's to keep rather than ours to let somebody delete by the same button
     * that means «вернуть стандартный текст».
     *
     * Dropping a section of the template is the one deletion that reaches
     * further than the page it is done on: every owner that had rewritten that
     * section keeps its text, which becomes a section of its own — the foreign
     * key sets the link to null. Their wording is theirs, written by hand, and
     * tidying the standard document is no reason to throw it away; refusing the
     * deletion instead would hold the company's document hostage to one owner's
     * version of one section.
     *
     * It keeps its place as well as its text. The place in the document was the
     * standard section's, and the text that stood in for that section is now the
     * thing itself, so the arrangement is told to name it — otherwise a section
     * nobody touched would walk to the end of the document because somebody
     * tidied the template.
     */
    protected function dropSection(Department|Position|null $owner, ?RegulationSection $section): RedirectResponse
    {
        $section = $this->belongsHere($section, $owner);

        if ($owner !== null && $section->template_section_id === null) {
            throw ValidationException::withMessages(['section' => $this->refusals()['taken_out']]);
        }

        DB::transaction(function () use ($section) {
            $section->overrides->each(fn (RegulationSection $instead) => RegulationOrder::where($this->owns(), $instead->ownerId())
                ->where('regulation_section_id', $section->id)
                ->update(['regulation_section_id' => $instead->id]));

            $section->delete();
        });

        return back();
    }

    /**
     * The document in a new order: every section it reads, once, in the order it
     * is to be read in.
     *
     * An owner arranges the whole of its document — the standard sections are
     * the whole of it, and it may read them in whatever order suits it — and the
     * arrangement is its own: rows of regulation_orders saying where this owner
     * reads what. The sections themselves are not touched, so the wording of a
     * standard section stays the company's and stays shared with every other
     * owner, and nobody else's document moves because one of them dragged
     * something.
     *
     * Position and wording are separate things, and this writes only the first.
     * Moving a standard section does not turn it into this owner's own copy and
     * does not cut it off from the company's later corrections.
     *
     * In the template there is nothing to arrange for anybody in particular, so
     * the sections are simply spaced out again: that is the order every owner
     * starts from, and the one an owner that has arranged its own document has
     * already answered for itself.
     */
    protected function arrange(Request $request, Department|Position|null $owner): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'distinct'],
        ], attributes: ['ids' => 'порядок разделов', 'ids.*' => 'раздел']);

        $ids = array_map('intval', $data['ids']);
        $sections = RegulationSection::forOwner($this->document(), $owner)->keyBy('id');

        if ($sections->keys()->sort()->values()->all() !== collect($ids)->sort()->values()->all()) {
            throw ValidationException::withMessages(['ids' => $this->refusals()['arranged']]);
        }

        DB::transaction(function () use ($ids, $sections, $owner) {
            // The template itself is simply spaced out again, a hundred
            // apart, which leaves room between any two sections for one
            // written later.
            if ($owner === null) {
                foreach ($ids as $place => $id) {
                    $sections[$id]->update(['order' => ($place + 1) * 100]);
                    $sections[$id]->overrides()->update(['order' => $sections[$id]->order]);
                }

                return;
            }

            // The whole arrangement is written at once, so what was there
            // before is not partly kept: this is the document as it is to be
            // read now, from the first section to the last.
            RegulationOrder::where($this->owns(), $owner->id)->delete();

            RegulationOrder::insert(array_map(fn (int $place, int $id) => [
                $this->owns() => $owner->id,
                // The place in the document, not the row read in it: an owner's
                // wording of a standard section is read where that standard
                // section is read, so a section rewritten later finds its place
                // already set.
                'regulation_section_id' => $sections[$id]->slot(),
                'order' => $place + 1,
            ], array_keys($ids), $ids));
        });

        return back();
    }

    /** Which column names the owner of this document. */
    protected function owns(): string
    {
        return Document::owner($this->document());
    }

    /**
     * A section is written at the address of the document it belongs to: an
     * owner's wording at the owner's, the standard text at the template's.
     * Anywhere else it is simply not there — which is also what keeps one
     * owner's id from reaching another's section, and the sections of one
     * document from being written at the other's address.
     *
     * The section comes from the address, so there is always one; the parameter
     * is written as if there might not be because the two addresses name a
     * different number of things and the methods serve both.
     */
    private function belongsHere(?RegulationSection $section, Department|Position|null $owner): RegulationSection
    {
        abort_if(
            $section === null || $section->document !== $this->document() || $section->ownerId() !== $owner?->id,
            404,
        );

        return $section;
    }

    /**
     * Where a new standard section goes while nobody has said: after
     * everything, until it is moved.
     *
     * Only the template's address gets this far — an owner names the standard
     * section it is rewriting, and that hands over its place with its text. This
     * is the order on the section itself, which is what a document nobody has
     * arranged is read by, and an owner that has arranged its own reads a
     * section it has not placed last in any case, so a new standard section
     * lands at the end of that document too.
     */
    private function atTheEnd(Department|Position|null $owner): int
    {
        $last = (int) RegulationSection::forOwner($this->document(), $owner)->max('order');

        // The column holds a small integer, and a document nobody will ever
        // write this far is not worth a failed save.
        return min($last + 100, 65535);
    }

    /**
     * What a section may be given, and what of it this request is actually
     * writing: a save that does not carry a field leaves that field alone, the
     * way the forms of a card do.
     *
     * The number is not among them, and is not stored either. It is the place
     * the section is read in the document, which is counted where the document
     * is put together.
     *
     * @return array<string, mixed>
     */
    private function validated(Request $request, Department|Position|null $owner, ?RegulationSection $section = null): array
    {
        // A heading is asked for while there is nothing to take one from: a new
        // standard section, which only the template's address writes. Rewriting
        // a standard section copies its heading, and a save of something else
        // leaves the heading alone.
        $heading = $section === null && $owner === null
            ? ['required']
            : ['sometimes', 'required'];

        $refusals = $this->refusals();

        $data = $request->validate([
            'template_section_id' => [
                // Only ever said when a section is created, and then always by
                // an owner and never by the template: an owner writes nothing
                // but its wording of a standard section, and the template
                // rewrites nothing, it is what is rewritten.
                match (true) {
                    $section !== null => 'prohibited',
                    $owner === null => 'sometimes',
                    default => 'required',
                },
                'nullable',
                'integer',
                Rule::exists('regulation_sections', 'id')
                    ->where('document', $this->document())
                    ->whereNull($this->owns()),
                Rule::unique('regulation_sections', 'template_section_id')
                    ->where('document', $this->document())
                    ->where($this->owns(), $owner?->id),
                function (string $attribute, mixed $value, Closure $fail) use ($owner, $refusals) {
                    if ($value !== null && $owner === null) {
                        $fail($refusals['overridden']);
                    }
                },
            ],
            'title' => [...$heading, 'string', 'max:150'],
            'body' => ['sometimes', 'nullable', 'string', 'max:'.Document::BODY_LENGTH],
            'order' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:65535'],
        ], messages: [
            'template_section_id.required' => $refusals['added'],
            'body.max' => 'Текст раздела не должен быть длиннее '.Document::BODY_LENGTH.' символов.',
        ], attributes: [
            'template_section_id' => 'стандартный раздел',
            'title' => 'заголовок',
            'body' => 'текст раздела',
            'order' => 'порядок',
        ]);

        if (array_key_exists('title', $data)) {
            $data['title'] = trim($data['title']);
        }

        // Cleaned here, so the row is written already safe and the pages that
        // read it afterwards have nothing to remember.
        if (array_key_exists('body', $data)) {
            $data['body'] = Document::sanitize($data['body']);
        }

        // An owner's wording of a standard section is read exactly where that
        // section is read, so its place is not its own to set.
        if ($section !== null && $section->template_section_id !== null) {
            unset($data['order']);
        }

        return $data;
    }
}
