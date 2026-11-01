<?php

namespace App\Models;

use Database\Factories\UserRelativeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One of the family a card names: who they are to this person, what they are
 * called, the year they were born and what they do.
 *
 * Kept apart from the children for the reason the migration gives, and read
 * under the same right: «Семья» is one block of the card.
 */
class UserRelative extends Model
{
    /** @use HasFactory<UserRelativeFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'relation',
        'full_name',
        'birth_year',
        'occupation',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
