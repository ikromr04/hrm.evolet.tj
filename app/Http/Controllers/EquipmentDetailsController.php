<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\KeepsEquipmentPhotos;
use App\Http\Controllers\Concerns\SavesEquipmentFields;
use App\Models\Equipment;
use App\Models\EquipmentField;
use App\Models\EquipmentType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

/**
 * The card of a unit, edited one block at a time, the way a profile is. What a
 * unit is and what comes with it are written here; where it is and who holds
 * it belong to the moves, so they stay out of these forms.
 */
class EquipmentDetailsController extends Controller
{
    use KeepsEquipmentPhotos, SavesEquipmentFields;

    /**
     * "Характеристики": what the unit is.
     */
    public function specs(Request $request, Equipment $equipment): RedirectResponse
    {
        // The fields asked about are those of the category the form was filled
        // in for, which is not necessarily the one the unit is filed under now.
        // The two a unit cannot be without are left out of them: the form asks
        // for those by themselves, as it always has.
        $type = EquipmentType::with(['fields' => fn ($fields) => $fields->whereNull('role')])
            ->find($request->integer('equipment_type_id'));

        $data = $request->validate([
            'equipment_type_id' => ['required', 'integer', Rule::exists('equipment_types', 'id')],
            'name' => ['required', 'string', 'max:150'],
            // Still one unit, one number — this one's own does not clash with
            // it. The column that used to see to that is gone with its unique
            // index, so the inventory field's own values are asked instead.
            'inventory_number' => ['required', 'string', 'max:50', Rule::unique('equipment_field_values', 'value')
                ->whereIn('equipment_field_id', EquipmentField::query()->where('role', 'inventory')->pluck('id')->all())
                ->ignore($equipment->id, 'equipment_id')],
            ...$this->fieldRules($type),
        ], attributes: [
            'equipment_type_id' => 'категория',
            'name' => 'наименование',
            'inventory_number' => 'инвентарный номер',
            ...$this->fieldAttributes($type),
        ]);

        // Which entries were there before, so a save that moved nothing but the
        // category's own fields can still be told it left a mark.
        $before = (int) $equipment->events()->max('id');

        // What the unit was called and the number it carried, before either is
        // written over. Both are fields of its category now, so the journal is
        // told about them here rather than by the row's own diff.
        $identity = self::identityChanges($equipment, $data['name'], $data['inventory_number']);

        // The category is set first: the values belong to the fields of the
        // category the unit ends up in.
        $moved = (int) $data['equipment_type_id'] !== $equipment->equipment_type_id;
        $equipment->equipment_type_id = $data['equipment_type_id'];
        $equipment->setRelation('type', $type);

        // A unit filed under another category is named by the fields of the one
        // it is in now; what it was called in the old one would otherwise go on
        // answering for it. Its ordinary values stay where they are, as ever —
        // a unit put back finds them again.
        if ($moved) {
            $equipment->fieldValues()->whereIn(
                'equipment_field_id',
                EquipmentField::query()->whereNotNull('role')->where('equipment_type_id', '!=', $equipment->equipment_type_id)->select('id'),
            )->delete();
        }

        $equipment->writeIdentity($data['name'], $data['inventory_number']);

        $equipment->journalExtra = [...$identity, ...$this->saveFields($equipment, $data['fields'] ?? [])];
        $equipment->update(Arr::except($data, ['fields', 'name', 'inventory_number']));

        // Nothing but the fields moved, so the row's own save wrote no entry and
        // the journal would otherwise have nothing to say about the change.
        if ($equipment->journalExtra !== [] && (int) $equipment->events()->max('id') === $before) {
            $equipment->events()->create([
                'user_id' => $request->user()->id,
                'kind' => 'updated',
                'diff' => $equipment->journalExtra,
            ]);
        }

        return back();
    }

    /**
     * What a save did to the two a unit cannot be without, named as the journal
     * has always named them. They were columns of the row once and read as a
     * plain change of "Наименование" or "Инвентарный номер"; they are fields of
     * the category now, and the line has to come out the same — whatever words
     * the category happens to call them by.
     *
     * @return array<string, array{?string, ?string}>
     */
    private static function identityChanges(Equipment $equipment, ?string $title, ?string $number): array
    {
        $changes = [];

        foreach (['name' => ['title', $title], 'inventory_number' => ['inventory', $number]] as $column => [$role, $now]) {
            $was = $equipment->roleValue($role);

            if ($was !== $now) {
                $changes[$column] = [$was, $now];
            }
        }

        return $changes;
    }

    /**
     * "Состояние": what state the unit was last seen in and when it is due to
     * be looked at again. A return fills this in by itself; this is for the
     * times somebody checks a unit without moving it.
     */
    public function state(Request $request, Equipment $equipment): RedirectResponse
    {
        $data = $request->validate([
            'condition' => ['nullable', 'string', 'max:200'],
            'checked_at' => ['nullable', 'date', 'before_or_equal:today'],
            'next_inventory_at' => ['nullable', 'date'],
            ...$this->photoRules(),
        ], messages: $this->photoMessages(), attributes: [
            'condition' => 'текущее состояние',
            'checked_at' => 'последняя проверка',
            'next_inventory_at' => 'следующая инвентаризация',
            'photos' => 'фотографии',
        ]);

        // Which entries were there before, so the one this check writes can be
        // found afterwards and the photographs hung on it.
        $before = (int) $equipment->events()->max('id');

        $equipment->update(Arr::except($data, 'photos'));

        // A check with nothing to correct still happened, so it gets an entry
        // of its own rather than leaving the photographs with nowhere to hang.
        $this->keepPhotos($request, $equipment, $before, 'condition');

        return back();
    }

    /**
     * "Комплектация": the whole list is replaced, so removing a line is simply
     * leaving it out.
     */
    public function accessories(Request $request, Equipment $equipment): RedirectResponse
    {
        abort_unless($equipment->type?->has_accessories ?? false, 403, 'У этой категории нет комплектации.');

        $data = $request->validate([
            'accessories' => ['present', 'array', 'max:30'],
            // A line left blank in the form arrives as null; it simply drops out.
            'accessories.*' => ['nullable', 'string', 'max:100'],
        ], attributes: ['accessories' => 'комплектация']);

        $items = array_filter(array_map(fn (?string $item) => trim($item ?? ''), $data['accessories']));

        $equipment->update(['accessories' => array_values($items)]);

        return back();
    }
}
