<?php

namespace App\Models;

use App\Models\Concerns\KeptByName;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The kind of work somebody was taken on for — full time, part time, an hourly
 * arrangement. A person is taken on under one of them or under none.
 */
class EmploymentType extends Model
{
    use KeptByName;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
    ];

    /**
     * Whoever is taken on under it. An entry that leaves the directory leaves
     * their cards standing, with that one line empty.
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
