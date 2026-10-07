<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A country one may be a citizen of. A person may hold several.
 */
class Citizenship extends Model
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
     * The entries for these names, added to the directory where missing. A name
     * is matched without regard to case, so "россия" finds "Россия" rather than
     * putting a second one on the list.
     *
     * @param  list<string>  $names
     * @return list<int>
     */
    public static function idsFor(array $names): array
    {
        $known = self::query()->get(['id', 'name'])->keyBy(fn (self $c) => mb_strtolower($c->name));

        return collect($names)
            ->map(fn (string $name) => ($known[mb_strtolower($name)] ??= self::create(['name' => $name]))->id)
            ->unique()
            ->values()
            ->all();
    }
}
