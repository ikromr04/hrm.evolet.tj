<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How one table opens for everybody who has not arranged it themselves.
 *
 * A viewer's own arrangements live in their own browser and outrank this one;
 * this is what a table looks like before anybody has touched it, and the system
 * administrator is the one who says so.
 */
class TableView extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'key',
        'view',
        'updated_by',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['view' => 'array'];
    }

    /** Whoever put it there; nobody, where that account has since gone. */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
