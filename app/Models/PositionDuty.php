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
        'name',
        'order',
    ];

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }
}
