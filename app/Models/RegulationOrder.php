<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Where one owner reads one section of its document.
 *
 * An owner arranges the whole of it — the standard sections as freely as its
 * own — and that arrangement is its own: these rows say where this department
 * reads each section of «Положение о работе», or this position each section of
 * «Профиль должности», and nobody else's document moves. An owner that has
 * never arranged anything has no rows here and reads the order the template
 * gives, which is what twenty-five of twenty-six departments do.
 *
 * Which of the two documents a row is about is not written on it: the section
 * it names says that already, and the owner column it fills in says the same
 * thing from the other side. One of the two owners, never both — the database
 * refuses a row that names a department and a position at once, and one that
 * names neither, because a place in nobody's document is the template's own
 * order, which is a column on the section.
 *
 * The section named is the place in the document rather than the row read in
 * it: for a standard section it is the template's row, and an owner's own
 * wording of that section is read in the same place. So rewriting a section's
 * text does not move it, and moving it does not rewrite anything —
 * RegulationSection::slot() is the one that says which id that is.
 */
class RegulationOrder extends Model
{
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'department_id',
        'position_id',
        'regulation_section_id',
        'order',
    ];

    /** Empty when the document arranged is a profile. */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /** Empty when it is a regulation. */
    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    /** The section whose place in the document this is. */
    public function section(): BelongsTo
    {
        return $this->belongsTo(RegulationSection::class, 'regulation_section_id');
    }
}
