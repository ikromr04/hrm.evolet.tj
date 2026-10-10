<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Position extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
    ];

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    /**
     * What a person in this position answers for, in the order somebody entered
     * them: duties are written down as a list and read back as the same list, so
     * the one nobody thought of first stays last.
     */
    public function duties(): HasMany
    {
        return $this->hasMany(PositionDuty::class)->orderBy('order')->orderBy('id');
    }
}
