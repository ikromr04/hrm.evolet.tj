<?php

namespace App\Models;

use Database\Factories\UserDetailFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserDetail extends Model
{
    /** @use HasFactory<UserDetailFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'hired_at',
        'birth_date',
        'birth_place',
        'nationality',
        'passport_series',
        'passport_number',
        'passport_issued_at',
        'passport_issued_by',
        'tax_number',
        'marital_status',
        'spouse_name',
        'spouse_birth_date',
        'spouse_occupation',
        'has_children',
        'has_educations',
        'has_experience',
        // Whether the three blocks that used to be one line apiece have
        // anything in them: «Учёная степень, звание», «Государственные
        // награды, поощрения», «Отношение к воинской обязанности». The word
        // «Нет» was written into those lines to tell them from an unanswered
        // one, and a list has no line to write it in — so each is answered
        // here, the way the children and the places of study are.
        'has_degrees',
        'has_awards',
        'has_military_records',
        'home_address',
        'phone',
        'sos_phone',
        'sos_contact',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'hired_at' => 'date',
            'birth_date' => 'date',
            'spouse_birth_date' => 'date',
            'has_children' => 'boolean',
            'has_educations' => 'boolean',
            'has_experience' => 'boolean',
            'has_degrees' => 'boolean',
            'has_awards' => 'boolean',
            'has_military_records' => 'boolean',
            'passport_issued_at' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
