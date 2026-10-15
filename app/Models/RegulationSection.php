<?php

namespace App\Models;

use App\Support\Document;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * One block of one of the company's documents: «01 ОБЩЕЕ ПОЛОЖЕНИЕ», «02
 * ПРАВА», «03 ОБЯЗАННОСТИ», «04 ОТВЕТСТВЕННОСТЬ» of «Положение о работе»; «01
 * ОБЩАЯ ИНФОРМАЦИЯ» and the eight sections after it of «Профиль должности».
 *
 * A heading and a body, the same shape for every one of them: the body is the
 * text as the editor wrote it, so a numbered list, a table of tasks and a line
 * to sign under are all a section saying what it has to say rather than three
 * kinds of section the application has to tell apart.
 *
 * A section names the document it is part of and belongs either to nobody — the
 * shared template, the standard text every owner reads — or to one owner, and
 * then it is that owner's own wording of a standard section, which names the
 * template section it stands for. Who an owner is depends on the document: a
 * regulation belongs to a department and a profile to a position, which is the
 * one difference between them and the reason the document has to be written on
 * the row. Without it the two templates, both owned by nobody, would be one.
 *
 * A document is those standard sections and nothing else: an owner rewrites one
 * of them, and no page adds a section to the document or takes one out of it. A
 * row of an owner's with no template section is the one thing left over — the
 * company dropped a standard section while this owner had rewritten it, and the
 * owner keeps the sentences it wrote by hand — so a section still reads as one
 * of three things, and the third is nothing anybody writes on purpose.
 *
 * So one owner's document is read in one order: the template's sections, each
 * replaced by this owner's wording where it wrote one, together with whatever
 * was left to it that way. forOwner() does that; it is what both the page and
 * the print read.
 */
class RegulationSection extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'document',
        'department_id',
        'position_id',
        'template_section_id',
        'title',
        'body',
        'computed',
        'order',
    ];

    /**
     * What a row says before anybody says otherwise, which is what the columns
     * themselves say: a section nobody filed under a document is a section of
     * «Положение о работе», the document that was here first, and a section is
     * written in an editor unless it is marked as drawn from data. The two
     * defaults are the schema's own, so a row written without them is filed the
     * same way whether the model or the database fills it in.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'document' => Document::REGULATION,
        'computed' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['computed' => 'boolean'];
    }

    protected static function booted(): void
    {
        // Keep the three shapes above the only ones there are, and keep each
        // document's rows its own: the template overrides nothing, nothing but
        // an owner's section overrides, and an owner only ever owns the document
        // that belongs to its kind.
        static::saving(function (self $section) {
            $owns = Document::owner($section->document);
            $foreign = $section->document === Document::REGULATION ? 'position_id' : 'department_id';

            if ($section->{$foreign} !== null) {
                throw new InvalidArgumentException('«Положение о работе» принадлежит отделу, а «Профиль должности» — должности.');
            }

            if ($section->template_section_id === null) {
                return;
            }

            if ($section->{$owns} === null) {
                throw new InvalidArgumentException('Раздел шаблона не может быть переопределением: переопределение принадлежит владельцу документа.');
            }

            $standard = static::find($section->template_section_id);

            if ($standard !== null && ($standard->ownerId() !== null || $standard->document !== $section->document)) {
                throw new InvalidArgumentException('Переопределить можно только раздел шаблона того же документа.');
            }
        });
    }

    /** Empty for the template, and for every section of a profile. */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /** Empty for the template, and for every section of a regulation. */
    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    /** The standard section this one is read in place of. */
    public function template(): BelongsTo
    {
        return $this->belongsTo(self::class, 'template_section_id');
    }

    /** The owners that have rewritten this standard section. */
    public function overrides(): HasMany
    {
        return $this->hasMany(self::class, 'template_section_id');
    }

    /**
     * Whose section this is, read through the document it belongs to: the
     * department of a regulation, the position of a profile, nobody at all for
     * a section of the template.
     *
     * The two columns are asked one at a time rather than together, because a
     * row that filled in both would be a row of two documents at once — which
     * the database refuses outright.
     */
    public function ownerId(): ?int
    {
        return $this->{Document::owner($this->document)};
    }

    /**
     * Which of the three a section is, for the page that shows it: the
     * company's own text, this owner's wording of it, or — only ever because a
     * standard section was dropped from under it — an owner's wording of
     * nothing in particular.
     */
    public function origin(): string
    {
        return match (true) {
            $this->ownerId() === null => 'template',
            $this->template_section_id !== null => 'override',
            default => 'own',
        };
    }

    /**
     * Which place in a document this section occupies: the standard section it
     * stands for, or itself.
     *
     * An owner's wording of a standard section is read exactly where that
     * standard section is read, so the two are one place and not two. Which is
     * what keeps position and wording apart: an owner that arranges its
     * document and then rewrites a section finds the place already set, and one
     * that rewrites a section first and arranges afterwards is arranging the
     * same place. It is what regulation_orders names.
     */
    public function slot(): int
    {
        return $this->template_section_id ?? $this->id;
    }

    /**
     * What one owner reads of one document: the template, with this owner's
     * wording in the place of every standard section it rewrote, plus any
     * wording left to it by a standard section the company has since dropped —
     * all of it in one order. Called with no owner it is the template itself,
     * which is how the same page edits the standard text.
     *
     * @return Collection<int, self>
     */
    public static function forOwner(string $document, Department|Position|null $owner): Collection
    {
        $owns = Document::owner($document);
        $template = static::where('document', $document)->whereNull($owns)->get();

        if ($owner === null) {
            return self::inOrder($template);
        }

        $own = static::where('document', $document)->where($owns, $owner->id)->get();
        $instead = $own->whereNotNull('template_section_id')->keyBy('template_section_id');

        return self::inOrder(
            $template
                ->map(fn (self $section) => $instead->get($section->id) ?? $section)
                ->merge($own->whereNull('template_section_id')),
            RegulationOrder::where($owns, $owner->id)->pluck('order', 'regulation_section_id')->all(),
        );
    }

    /**
     * «Положение о работе» as one department reads it, or the standard text
     * itself.
     *
     * @return Collection<int, self>
     */
    public static function forDepartment(?Department $department): Collection
    {
        return self::forOwner(Document::REGULATION, $department);
    }

    /**
     * «Профиль должности» as it is read for one position, or the standard
     * nine sections themselves.
     *
     * @return Collection<int, self>
     */
    public static function forPosition(?Position $position): Collection
    {
        return self::forOwner(Document::PROFILE, $position);
    }

    /**
     * What the section read in this place of a document is numbered: «01»,
     * «02», «03» down the page, counting from one.
     *
     * Nobody types it and nothing stores it, because it is not a property of the
     * section. An owner reads one document assembled out of two scopes, and one
     * row of the template is read in every owner's document at a different
     * place; the only thing a number can be is the place it is read in. So it is
     * worked out from the order forOwner() returns, and a section slotted in
     * between two others simply pushes the rest down.
     *
     * Two digits because that is how the company writes it, and a document of
     * more than ninety-nine sections is not one of these.
     */
    public static function number(int $place): string
    {
        return str_pad((string) ($place + 1), 2, '0', STR_PAD_LEFT);
    }

    /**
     * Where each section is read: where this owner put it, and failing that by
     * the section's own order and then by age, so two sections that were never
     * moved apart still come out the same way twice.
     *
     * An owner that has arranged nothing has no places, and then this is the
     * template's order, the same for everybody. A section nobody placed is read
     * after every section somebody did — which is the rule for a standard
     * section added to the template after an owner arranged its document: it
     * lands at the end, where it can be found and moved, rather than somewhere
     * that depends on a row id.
     *
     * @param  Collection<int, self>  $sections
     * @param  array<int, int>  $places  slot id => where this owner reads it
     * @return Collection<int, self>
     */
    private static function inOrder(Collection $sections, array $places = []): Collection
    {
        return $sections
            ->sortBy(fn (self $section) => [
                $places[$section->slot()] ?? PHP_INT_MAX,
                $section->order,
                $section->id,
            ])
            ->values();
    }
}
