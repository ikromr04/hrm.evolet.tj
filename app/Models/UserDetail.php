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
        'degree',
        'degree_document_path',
        'degree_document_name',
        'award',
        'award_document_path',
        'award_document_name',
        'military',
        'military_document_path',
        'military_document_name',
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
            'passport_issued_at' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
