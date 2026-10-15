<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One thing that happened to a unit. What kind of thing it was is named by
 * `kind`; which fields moved, and from what to what, is in `changes`.
 */
class EquipmentEvent extends Model
{
    /** Every operation the section records, in the order a life runs. */
    public const KINDS = ['created', 'stocked', 'issued', 'written_off', 'updated', 'condition', 'accessories', 'repair_added', 'repair_ended', 'repair_updated', 'repair_removed'];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'equipment_id',
        'user_id',
        'kind',
        'diff',
        'note',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'diff' => 'array',
        ];
    }

    /**
     * The fields a journal entry keeps as an id, and where the name behind it
     * is looked up. An entry records what the row said at the time, so the
     * names are resolved when it is read rather than copied into it.
     */
    private const NAMED = [
        'holder_user_id' => User::class,
        'holder_department_id' => Department::class,
        'equipment_type_id' => EquipmentType::class,
    ];

    /**
     * Names for every id mentioned by these entries, so "#33" can be read as
     * the colleague it stands for. An id with nobody behind it any more is
     * left out, and the journal falls back to the number.
     *
     * @param  iterable<EquipmentEvent>  $events
     * @return array<string, array<int, string>> field => [id => name]
     */
    public static function namesFor(iterable $events): array
    {
        $ids = [];

        foreach ($events as $event) {
            foreach ($event->diff ?? [] as $field => $pair) {
                if (! isset(self::NAMED[$field])) {
                    continue;
                }

                foreach ($pair as $value) {
                    if (is_numeric($value)) {
                        $ids[$field][] = (int) $value;
                    }
                }
            }
        }

        $names = [];

        foreach ($ids as $field => $values) {
            $names[$field] = match (self::NAMED[$field]) {
                User::class => User::query()->whereKey(array_unique($values))->get(['id', 'name', 'surname'])
                    ->mapWithKeys(fn (User $u) => [$u->id => "{$u->surname} {$u->name}"])->all(),
                // A department by the name it is known by everywhere but the
                // directory: its abbreviation, or its full name where it has none.
                Department::class => Department::query()->whereKey(array_unique($values))->get(['id', 'name', 'abbreviation'])
                    ->mapWithKeys(fn (Department $d) => [$d->id => $d->display_name])->all(),
                default => self::NAMED[$field]::query()->whereKey(array_unique($values))->pluck('name', 'id')->all(),
            };
        }

        return $names;
    }

    /**
     * Dates and decimals come off a row in whatever shape the driver gives
     * them; an entry keeps plain values, so a line reads the same however it
     * was written. A list stays a list: the accessories are compared item by
     * item when the entry is read.
     */
    public static function plain(mixed $value): string|int|float|bool|array|null
    {
        return match (true) {
            $value === null || is_scalar($value) => $value,
            $value instanceof \DateTimeInterface => $value->format('Y-m-d'),
            is_array($value) => array_values(array_map(fn ($item) => (string) $item, $value)),
            default => (string) $value,
        };
    }

    /**
     * What a save changed, as an entry keeps it: field => [before, after].
     *
     * Called while a model is being saved — from an observer — the row still
     * remembers what it said, and that is where the "before" comes from. Called
     * after the save it does not, so the caller hands over a copy taken
     * beforehand: .
     *
     * @param  list<string>  $ignored
     * @return array<string, array{mixed, mixed}>
     */
    public static function diffOf(Model $row, array $ignored = ['created_at', 'updated_at'], ?Model $was = null): array
    {
        return collect($row->getChanges())
            ->except($ignored)
            ->map(fn ($skip, string $field) => [
                self::plain($was ? $was->getAttribute($field) : $row->getOriginal($field)),
                self::plain($row->getAttribute($field)),
            ])
            ->all();
    }

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }

    /**
     * What the unit looked like when this entry was written.
     */
    public function photos(): HasMany
    {
        return $this->hasMany(EquipmentPhoto::class)->orderBy('id');
    }

    /** Whoever did it; empty for what the seeder and the system do. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
