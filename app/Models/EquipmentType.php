<?php

namespace App\Models;

use Database\Factories\EquipmentTypeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A category of hardware: "Ноутбуки", "Мониторы", "Телефоны".
 */
class EquipmentType extends Model
{
    /** @use HasFactory<EquipmentTypeFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'icon',
        'has_accessories',
    ];

    protected function casts(): array
    {
        return ['has_accessories' => 'boolean'];
    }

    /**
     * What units of this category are described by, in the order the card and
     * the form put them in.
     */
    public function fields(): HasMany
    {
        return $this->hasMany(EquipmentField::class)->orderBy('position')->orderBy('id');
    }

    /**
     * Every unit in this category, whoever holds it.
     */
    public function equipment(): HasMany
    {
        return $this->hasMany(Equipment::class);
    }

    /**
     * The category as a form offers it: its name, whether its units come with
     * anything, and what they are described by.
     *
     * @return array<string, mixed>
     */
    public function formOption(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'has_accessories' => $this->has_accessories,
            'fields' => $this->formFields(),
        ];
    }

    /**
     * What units of this category are asked about, as a form draws the inputs:
     * each field with the role it carries, if any, and its choices. Given a
     * unit, each field comes with what that unit answered, for a form to put
     * back into its input.
     *
     * @return list<array<string, mixed>>
     */
    public function formFields(?Equipment $unit = null): array
    {
        $held = $unit?->fieldValues->keyBy('equipment_field_id');

        return $this->fields->map(fn (EquipmentField $field) => [
            'id' => $field->id,
            'name' => $field->name,
            // Which of the two it is, or nothing for a category's own field.
            'role' => $field->role,
            'type' => $field->type,
            'options' => $field->choices(),
            'required' => $field->required,
            ...($unit === null ? [] : [
                // As it is kept; the card spells a yes or a no out for itself.
                'value' => $held?->get($field->id)?->value,
            ]),
        ])->all();
    }

    /**
     * Every category, as the forms that offer a choice of one need them.
     *
     * @return list<array<string, mixed>>
     */
    public static function formOptions(): array
    {
        return self::query()
            ->with('fields')
            ->orderBy('name')
            ->get(['id', 'name', 'has_accessories'])
            ->map(fn (self $type) => $type->formOption())
            ->all();
    }
}
