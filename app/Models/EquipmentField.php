<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One thing units of a category are described by: "Процессор" for laptops,
 * "Диагональ" for monitors, "IMEI" for phones.
 *
 * Which fields a category has is decided in the directory, so the card of a
 * monitor never asks about a processor and a new field costs no deployment.
 */
class EquipmentField extends Model
{
    /**
     * What a field can hold. Text unless told otherwise: it is the one type
     * that never refuses what somebody types.
     *
     * @var array<string, string>
     */
    public const TYPES = [
        'text' => 'Текст',
        'number' => 'Число',
        'date' => 'Дата',
        'boolean' => 'Да / нет',
        'select' => 'Выбор из списка',
    ];

    /**
     * The two a unit cannot be without. A category may call them by its own
     * words and put them where it likes, but one of each stays: the list, the
     * journal, the letters and the search all name a unit by these two.
     *
     * @var array<string, string>
     */
    public const ROLES = [
        'title' => 'Наименование',
        'inventory' => 'Инвентарный номер',
    ];

    protected $fillable = ['equipment_type_id', 'role', 'name', 'type', 'options', 'required', 'position'];

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'required' => 'boolean',
        ];
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(EquipmentType::class, 'equipment_type_id');
    }

    /** What the units of the category have in this field. */
    public function values(): HasMany
    {
        return $this->hasMany(EquipmentFieldValue::class);
    }

    /** Whether this is one of the two every unit carries. */
    public function isRole(): bool
    {
        return $this->role !== null;
    }

    /** The choices a "select" offers; empty for every other type. */
    public function choices(): array
    {
        return $this->type === 'select' ? array_values($this->options ?? []) : [];
    }

    /**
     * The value as the card shows it: a date the way this project writes dates
     * is the page's business, so only the plainly ambiguous ones are spelled
     * out here.
     */
    public function read(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->type === 'boolean' ? ($value === '1' ? 'Да' : 'Нет') : $value;
    }
}
