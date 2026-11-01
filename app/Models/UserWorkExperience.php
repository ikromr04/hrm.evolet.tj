<?php

namespace App\Models;

use Database\Factories\UserWorkExperienceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserWorkExperience extends Model
{
    /** @use HasFactory<UserWorkExperienceFactory> */
    use HasFactory;

    protected $table = 'user_work_experiences';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'organization',
        'position',
        'started_month',
        'started_year',
        'ended_month',
        'ended_year',
        'reason',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
