<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Equipment;
use App\Models\EquipmentField;
use App\Models\EquipmentFieldValue;
use App\Models\EquipmentType;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

/**
 * The fields a category gives its units, read off a form and written down.
 *
 * A form sends them as "fields[<field id>]", so the rules are built for the
 * category the form was filled in for: a monitor is never asked for a processor,
 * and a required field is required only where it exists.
 *
 * The fields a unit is named by are the same story, told through the role they
 * carry: a form asks for a name and a number by themselves, so the rules for
 * those are built here too — and a category that keeps neither is asked for
 * neither.
 */
trait SavesEquipmentFields
{
    /**
     * The fields the category names its units by, by the role each carries. A
     * category may hold one, both or none of them.
     *
     * @return Collection<string, EquipmentField>
     */
    protected function identityFields(?EquipmentType $type): Collection
    {
        if ($type === null) {
            return collect();
        }

        return EquipmentField::query()
            ->where('equipment_type_id', $type->id)
            ->whereNotNull('role')
            ->get()
            ->keyBy('role');
    }

    /**
     * What a form may send for the field of that role.
     *
     * A category that has the field asks for its value as it always has, and
     * insists on one where the field says it is obligatory. A category that has
     * dropped it asks for nothing: the form draws no input, and a value from a
     * page open since before the field went is taken no further than this —
     * `writeIdentity` has nowhere to put it.
     *
     * Until a category is chosen there is no telling, and both are asked for:
     * the request is going back over the category anyway, and a form that lost
     * its name on the way should say so in the same breath.
     *
     * @param  Collection<string, EquipmentField>  $named
     * @return list<mixed>
     */
    protected function identityRules(?EquipmentType $type, Collection $named, string $role, int $max): array
    {
        $field = $named->get($role);

        if ($type !== null && $field === null) {
            return ['nullable', 'string', "max:{$max}"];
        }

        return [$field === null || $field->required ? 'required' : 'nullable', 'string', "max:{$max}"];
    }

    /**
     * One unit, one number. The column that used to see to that is gone with
     * its unique index, so the number is checked against what the inventory
     * fields themselves hold — all of them, whatever category the units holding
     * them are filed under. Asked as a subquery rather than as a list of ids,
     * so no caller can hand the rule an empty list and have it pass on nothing.
     *
     * A category with no inventory field asks for no number, and then this rule
     * is not among the ones asked at all: there is nothing to be unique.
     */
    protected function freeNumber(?Equipment $unit = null): Unique
    {
        $rule = Rule::unique('equipment_field_values', 'value')
            ->where(fn (QueryBuilder $query) => $query->whereIn(
                'equipment_field_id',
                EquipmentField::query()->where('role', 'inventory')->select('id'),
            ));

        return $unit === null ? $rule : $rule->ignore($unit->id, 'equipment_id');
    }

    /**
     * Rules for the fields of one category, keyed the way the form sends them.
     *
     * @return array<string, mixed>
     */
    protected function fieldRules(?EquipmentType $type): array
    {
        if ($type === null) {
            return ['fields' => ['nullable', 'array']];
        }

        $rules = ['fields' => ['nullable', 'array']];

        foreach ($type->fields as $field) {
            $rules["fields.{$field->id}"] = [
                $field->required ? 'required' : 'nullable',
                ...match ($field->type) {
                    'number' => ['numeric'],
                    'date' => ['date'],
                    'boolean' => ['boolean'],
                    'select' => ['string', Rule::in($field->choices())],
                    default => ['string', 'max:200'],
                },
            ];
        }

        return $rules;
    }

    /**
     * What each field is called, so a message names the field rather than its id.
     *
     * @return array<string, string>
     */
    protected function fieldAttributes(?EquipmentType $type): array
    {
        if ($type === null) {
            return [];
        }

        return $type->fields
            ->mapWithKeys(fn (EquipmentField $field) => ["fields.{$field->id}" => mb_strtolower($field->name)])
            ->all();
    }

    /**
     * Write the values down, one row per field that has one.
     *
     * A field left empty keeps no row: "nothing written here" and "no row" are
     * the same thing, and it saves the card from reading blanks. Values of
     * fields that belong to another category are left where they are — a unit
     * filed under the wrong category and put back should not lose its data.
     *
     * @param  array<int|string, mixed>  $values  As the form sent them: field id => value.
     * @return array<string, array{string|null, string|null}> What changed, by field name.
     */
    protected function saveFields(Equipment $equipment, array $values): array
    {
        $fields = $equipment->type?->fields ?? collect();
        $existing = $equipment->fieldValues()->get()->keyBy('equipment_field_id');
        $changes = [];

        foreach ($fields as $field) {
            // A field the form did not mention is left alone; one it mentioned
            // as empty is cleared.
            if (! array_key_exists($field->id, $values) && ! Arr::has($values, (string) $field->id)) {
                continue;
            }

            $was = $existing->get($field->id)?->value;
            $now = $this->plain($field, $values[$field->id] ?? null);

            if ($was === $now) {
                continue;
            }

            if ($now === null) {
                $existing->get($field->id)?->delete();
            } else {
                EquipmentFieldValue::updateOrCreate(
                    ['equipment_id' => $equipment->id, 'equipment_field_id' => $field->id],
                    ['value' => $now],
                );
            }

            // Before and after, in the shape the journal keeps every change in,
            // and under the field's own name — there is no column to name it by.
            $changes[$field->name] = [$field->read($was), $field->read($now)];
        }

        return $changes;
    }

    /** One value as it is kept: text, or nothing at all when it is empty. */
    private function plain(EquipmentField $field, mixed $value): ?string
    {
        if ($field->type === 'boolean') {
            // An unticked box arrives as false, which is a value like any other.
            return $value === null || $value === '' ? null : ($value ? '1' : '0');
        }

        $value = is_string($value) ? trim($value) : $value;

        return $value === null || $value === '' ? null : (string) $value;
    }
}
