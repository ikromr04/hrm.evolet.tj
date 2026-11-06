<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A language one person speaks, and how well.
 *
 * Written on the card rather than pointed at in a list the company keeps: what
 * the next person is offered is what has been written on the cards already, and
 * a language nobody has heard of is typed in and kept as it was typed.
 */
class UserLanguage extends Model
{
    /** How well someone speaks it, from least to most. */
    public const LEVELS = ['beginner', 'intermediate', 'advanced'];

    protected $table = 'user_languages';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'level',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
