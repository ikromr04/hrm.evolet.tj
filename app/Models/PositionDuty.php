<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * One thing a position answers for: "Ведение договоров" for a lawyer, "Закрытие
 * месяца" for an accountant.
 *
 * A position has several of them and they are read one at a time — the page of a
 * position picks out, among everything a holder is responsible for, the duties
 * that come from this position — so each is a row of its own rather than a line
 * of one block of text.
 *
 * The list is what the position answers for; a duty on it is what one of the
 * holders answers for. Which of them, the duty knows: holding the position does
 * not hand somebody the whole list, the duties they carry are picked from it.
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

    /**
     * The employees who carry this duty — none of them until somebody picks it
     * for them, and several of them where a department shares the work out.
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }
}
