<?php

namespace App\Models;

use Database\Factories\UserChildFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One child a card names, on the same three lines as everybody else in
 * «Состав семьи»: what they are called, the month they were born in and where
 * they spend their days — «3 класс, СОШ № 12» as readily as a place of work.
 *
 * The day of the birth is not asked for anywhere in the block, so the column
 * holds the first of the month. See App\Support\EmployeeCard::familyRules().
 */
class UserChild extends Model
{
    /** @use HasFactory<UserChildFactory> */
    use HasFactory;

    protected $table = 'user_children';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
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
