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
 * template section it stands for. Which owners a document may have is the one
 * thing that still tells the two apart: a regulation is a department's and
 * nobody else's, while a profile is written for a position and for a
 * department alike. That is why the document is written on the row — the two
 * templates, both owned by nobody, would otherwise be one.
 *
 * One owner to a row, whichever document it is. A profile naming a department
 * and a position at once would be a sheet about two things, and the database
 * refuses it outright; the kind of owner it may name at all is a rule about
 * meaning, which is kept here.
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
        // an owner's section overrides, an owner only ever owns a document its
        // kind may own, and no row belongs to two owners at once.
        static::saving(function (self $section) {
            $owners = Document::owners($section->document);
            $named = array_filter(
                array_values(Document::COLUMNS),
                fn (string $column) => $section->{$column} !== null,
            );

            if (array_diff($named, $owners) !== []) {
                throw new InvalidArgumentException('«Положение о работе» принадлежит подразделению, а «Профиль должности» — подразделению или должности.');
            }

            if (count($named) > 1) {
                throw new InvalidArgumentException('Раздел документа принадлежит одному владельцу: подразделению или должности, но не обоим сразу.');
            }

            if ($section->template_section_id === null) {
                return;
            }

            if ($named === []) {
                throw new InvalidArgumentException('Раздел шаблона не может быть переопределением: переопределение принадлежит владельцу документа.');
            }

            $standard = static::find($section->template_section_id);

            if ($standard !== null && ($standard->ownerId() !== null || $standard->document !== $section->document)) {
                throw new InvalidArgumentException('Переопределить можно только раздел шаблона того же документа.');
            }
        });
    }

    /** Empty for the template, and for a profile written for a position. */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /** Empty for the template, for every section of a regulation, and for a department's profile. */
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
     * Which column this row names its owner in, and nothing at all for a
     * section of the template, which names none.
     *
     * Only the columns this document may be owned through are looked at: a
     * profile is read for a department as readily as for a position, and a
     * regulation that somehow carried a position would be answered as the
     * template rather than as that position's, which is the one reading that
     * would be worse than saying nothing.
     */
    public function ownerColumn(): ?string
    {
        foreach (Document::owners($this->document) as $column) {
            if ($this->{$column} !== null) {
                return $column;
            }
        }

        return null;
    }

    /**
     * Whose section this is: the department of a regulation, the department or
     * the position a profile is written for, nobody at all for a section of the
     * template.
     *
     * An id and not an owner, because what asks is comparing it with the owner
     * of the address it was read at — and an id alone cannot tell a department
     * from a position, so whatever compares them uses ownedBy() rather than
     * this.
     */
    public function ownerId(): ?int
    {
        $column = $this->ownerColumn();

        return $column === null ? null : $this->{$column};
    }

    /**
     * Whether this section is this owner's — or, asked about nobody, whether it
     * is the template's.
     *
     * The kind is asked along with the id, because the two owners of a profile
     * are numbered apart: department 7 and position 7 both exist, and «is this
     * section seven's?» has two answers.
     */
    public function ownedBy(Department|Position|null $owner): bool
    {
        return $owner === null
            ? $this->ownerColumn() === null
            : $this->{Document::column($owner)} === $owner->id && Document::owns($this->document, $owner);
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
     * The template is the rows of this document that name no owner of any kind,
     * so one template serves every kind that may own the document: a department
     * and a position read the same nine sections and rewrite them each for
     * itself, and there is nothing in the standard text that is one kind's.
     *
     * @return Collection<int, self>
     */
    public static function forOwner(string $document, Department|Position|null $owner): Collection
    {
        $standard = static::where('document', $document);

        foreach (Document::owners($document) as $column) {
            $standard->whereNull($column);
        }

        $template = $standard->get();

        if ($owner === null) {
            return self::inOrder($template);
        }

        $owns = Document::column($owner);
        $own = static::where('document', $document)->where($owns, $owner->id)->get();
        $instead = $own->whereNotNull('template_section_id')->keyBy('template_section_id');

        return self::inOrder(
            $template
                ->map(fn (self $section) => $instead->get($section->id) ?? $section)
                ->merge($own->whereNull('template_section_id')),
            // This document's places and not the owner's every place: a
            // department arranges two documents and names itself in the same
            // column in both, so the rows are narrowed to the sections this
            // document is made of.
            RegulationOrder::where($owns, $owner->id)
                ->whereIn('regulation_section_id', $template->pluck('id')->merge($own->pluck('id')))
                ->pluck('order', 'regulation_section_id')
                ->all(),
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
     * The same nine sections as one department reads them, which is the sheet
     * its «СФД» tab holds. Its regulation is a different document and is read
     * by forDepartment(); both are the department's, and the arrangement of
     * each is kept apart by the sections it names.
     *
     * @return Collection<int, self>
     */
    public static function profileForDepartment(?Department $department): Collection
    {
        return self::forOwner(Document::PROFILE, $department);
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
