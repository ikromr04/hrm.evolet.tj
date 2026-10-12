<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing a position answers for: "Ведение договоров" for a lawyer, "Закрытие
 * месяца" for an accountant.
 *
 * A position has several of them and they are read one at a time — the page of a
 * position picks out, among everything a holder is responsible for, the duties
 * that come from this position — so each is a row of its own rather than a line
 * of one block of text.
 *
 * A duty may name a department as well, because an analyst in one department
 * does not answer for what an analyst in another answers for. Named, it belongs
 * only to the people holding the position in that department; unnamed, it
 * belongs to everybody holding the position, wherever they sit.
 */
class PositionDuty extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'position_id',
        'department_id',
        'name',
        'order',
    ];

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    /**
     * The department the duty is done in, and nothing at all when it is one of
     * the duties the position carries everywhere.
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }
}
