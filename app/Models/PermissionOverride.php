<?php

namespace App\Models;

use App\Support\AccessMemo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One right given to, or taken from, one person, whatever their position says.
 *
 * @see User::hasPermissionTo()
 */
class PermissionOverride extends Model
{
    protected $fillable = ['user_id', 'permission', 'allowed'];

    /**
     * A right written here is a right the rest of the request must not still be
     * reading the old answer to.
     *
     * What a card shows is worked out from the whole catalogue of rights and
     * filed for the length of the request, because the pages ask it once per
     * row; this throws that filing away the moment an exception is made or
     * called off. No route today writes an override and then renders — they all
     * redirect — so this changes nothing that happens. It is here because the
     * day one does, the failure would be a right shown to somebody who no longer
     * has it, and that is not a thing to leave to the ordering of a controller.
     */
    protected static function booted(): void
    {
        static::saved(fn () => AccessMemo::forget());
        static::deleted(fn () => AccessMemo::forget());
    }

    protected function casts(): array
    {
        return ['allowed' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
