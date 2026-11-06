<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A country one person is a citizen of; there may be several on one card.
 *
 * Named on the card, like the languages beside it: the countries offered are
 * the ones already written down somewhere, and anything else is taken as typed.
 */
class UserCitizenship extends Model
{
    protected $table = 'user_citizenships';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
