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
        'marital_status',
        'spouse_name',
        'spouse_birth_date',
        'has_children',
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
            'passport_issued_at' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
