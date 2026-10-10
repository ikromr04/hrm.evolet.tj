<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class Department extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'abbreviation',
        'parent_id',
    ];

    /**
     * What this department is called wherever it is shown: its abbreviation
     * when it has one, its full name when it has not, so a department never
     * reads as blank. Every page names a department by this; the two with room
     * to say what the short name stands for — the «Отделы» directory, where it
     * is written, and the department's own page — spell both out instead.
     */
    protected function displayName(): Attribute
    {
        return Attribute::get(fn (): string => $this->abbreviation ?: $this->name);
    }

    protected static function booted(): void
    {
        // Keep the tree a tree: no department may sit under itself or its own sub-department.
        static::saving(function (Department $department) {
            if ($department->parent_id !== null && $department->exists && $department->descendantIds(true)->contains($department->parent_id)) {
                throw new InvalidArgumentException("Отдел «{$department->name}» не может быть подразделением самого себя или своего подразделения.");
            }
        });
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Department::class, 'parent_id')->orderBy('name');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('is_head')->withTimestamps();
    }

    /**
     * Members who lead this department; there can be several.
     */
    public function heads(): BelongsToMany
    {
        return $this->users()->wherePivot('is_head', true);
    }

    /**
     * IDs of every sub-department at any depth, optionally including this one.
     *
     * @return Collection<int, int>
     */
    public function descendantIds(bool $includeSelf = false): Collection
    {
        $ids = collect($includeSelf ? [$this->id] : []);
        $level = [$this->id];

        while ($level !== []) {
            $level = static::whereIn('parent_id', $level)->pluck('id')->diff($ids)->values()->all();
            $ids = $ids->merge($level);
        }

        return $ids->values();
    }

    /**
     * How many working people each department has together with all its
     * sub-departments, heads included and each person counted once, like the
     * employee list filtered by that department.
     *
     * @param  iterable<Department>  $departments  the whole tree, with `users` loaded (working staff only)
     * @return array<int, int> department id => number of people
     */
    public static function staffTotals(iterable $departments): array
    {
        $departments = collect($departments);
        $children = $departments->groupBy('parent_id');

        $staff = function (Department $d, array $seen = []) use (&$staff, $children): Collection {
            return $children->get($d->id, collect())
                ->reject(fn (Department $child) => in_array($child->id, $seen, true))
                ->reduce(fn (Collection $ids, Department $child) => $ids->merge($staff($child, [...$seen, $d->id])), $d->users->pluck('id'));
        };

        return $departments->mapWithKeys(fn (Department $d) => [$d->id => $staff($d)->unique()->count()])->all();
    }

    /**
     * "ДМ › ОД", each step named the way it is named on screen.
     */
    public function path(): string
    {
        $names = [$this->display_name];
        $seen = [$this->id];

        for ($parent = $this->parent; $parent && ! in_array($parent->id, $seen, true); $parent = $parent->parent) {
            array_unshift($names, $parent->display_name);
            $seen[] = $parent->id;
        }

        return implode(' › ', $names);
    }
}
