<?php

namespace App\Models;

use Database\Factories\UserRelativeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One of the family a card names: who they are to this person, what they are
 * called, the month they were born in and what they do.
 *
 * Kept apart from the children for the reason the migration gives, and read
 * under the same right: «Семья» is one block of the card.
 *
 * The date is a month and a year — the day is not asked for of anybody in the
 * block — so the column holds the first of the month and the card reads it
 * back as «1971-03». See App\Support\EmployeeCard::familyRules().
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
        'birth_date',
        'occupation',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
