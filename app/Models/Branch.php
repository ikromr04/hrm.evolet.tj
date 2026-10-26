<?php

namespace App\Models;

use App\Models\Concerns\KeptByName;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A place the company works from. Not the tree of departments — that says who
 * reports to whom — but the address a person goes to: one of them, or none.
 */
class Branch extends Model
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
     * Whoever works at it. An entry that leaves the directory leaves their
     * cards standing, with that one line empty.
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
