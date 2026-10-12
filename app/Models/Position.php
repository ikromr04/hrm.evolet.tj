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
     *
     * They come in groups — the duties the position carries everywhere first,
     * then the ones each department adds — and `order` is a duty's place inside
     * its own group, so every group reads from the top. Which group is which is
     * spelled out rather than left to the engine: MySQL and SQLite both sort a
     * null first, but the common duties lead the list because they are the ones
     * everybody holding the position carries, not because of that.
     */
    public function duties(): HasMany
    {
        return $this->hasMany(PositionDuty::class)
            ->orderByRaw('department_id is null desc')
            ->orderBy('department_id')
            ->orderBy('order')
            ->orderBy('id');
    }
}
