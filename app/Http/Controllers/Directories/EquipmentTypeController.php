<?php

namespace App\Http\Controllers\Directories;

use App\Http\Controllers\Controller;
use App\Models\EquipmentField;
use App\Models\EquipmentType;
use App\Support\Directories;
use App\Support\EquipmentIcons;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Categories of hardware ("Ноутбуки", "Мониторы"); the units themselves live
 * in the equipment section, each with its own inventory number.
 *
 * A category also decides what its units are described by: a monitor has a
 * diagonal and no processor, a phone has an IMEI. Those fields are set here,
 * which is why the dialog of this directory is the longest of them all.
 */
class EquipmentTypeController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('directories/equipment', [
            // What a category may be drawn by; the form offers exactly these.
            'icons' => EquipmentIcons::KEYS,
            // What a field can hold, for the form's own list of types.
            'fieldTypes' => collect(EquipmentField::TYPES)->map(fn (string $title, string $key) => ['key' => $key, 'title' => $title])->values(),
            // What a new category starts from: the two a unit cannot be without
            // and nothing else. What describes a monitor says nothing about a
            // cable, so the rest is written in the dialog by whoever knows.
            'defaultFields' => $this->roleFields(),
            // Units in the category, written-off ones aside: the directory
            // counts hardware, and the number links nowhere else.
            // Reading a list and keeping it are two rights, so the page says
            // which one it is looking at.
            'canEdit' => Directories::canEdit($request->user(), 'equipment'),
            'items' => EquipmentType::query()
                ->with('fields')
                ->withCount(['equipment as users_count' => fn ($q) => $q->inService()])
                ->orderBy('name')
                ->get()
                ->map(fn (EquipmentType $type) => [
                    'id' => $type->id,
                    'name' => $type->name,
                    'icon' => $type->icon,
                    'has_accessories' => $type->has_accessories,
                    'users_count' => $type->users_count,
                    'fields' => $type->fields->map(fn (EquipmentField $field) => [
                        'id' => $field->id,
                        // Null for an ordinary field, and one of the two roles
                        // for the pair every unit is named by: the editor shows
                        // those differently and sends the role back untouched.
                        'role' => $field->role,
                        'name' => $field->name,
                        'type' => $field->type,
                        'options' => $field->choices(),
                        'required' => $field->required,
                    ]),
                ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $type = EquipmentType::create([
            'name' => $data['name'],
            'icon' => $data['icon'] ?? null,
            'has_accessories' => $data['has_accessories'] ?? true,
        ]);

        // A category is born with the two fields its units are named by, even
        // when the request says nothing about fields at all: without them a
        // unit could not be put on the books under this category.
        $this->syncFields($type, $data['fields'] ?? $this->roleFields());

        return back();
    }

    public function update(Request $request, EquipmentType $equipment): RedirectResponse
    {
        $data = $this->validated($request, $equipment);

        $equipment->update([
            'name' => $data['name'],
            'icon' => $data['icon'] ?? null,
            'has_accessories' => $data['has_accessories'] ?? $equipment->has_accessories,
        ]);

        // Saying nothing about the fields leaves them alone; sending a list — even
        // an empty one — is what replaces them.
        if (array_key_exists('fields', $data)) {
            $this->syncFields($equipment, $data['fields']);
        }

        return back();
    }

    public function destroy(EquipmentType $equipment): RedirectResponse
    {
        // The units of this kind go with it; employees themselves are untouched.
        $equipment->delete();

        return back();
    }

    /**
     * The fields as the dialog left them: the ones it kept are updated in place,
     * the ones it added are created, and the ones it dropped are deleted along
     * with whatever the units had written in them — which the dialog says before
     * it lets anybody drop one.
     *
     * The two roles travel with the fields that carry them, so a role field can
     * be renamed and moved like any other and still be the one the list, the
     * journal, the letters and the search read a unit's name and number from.
     * A list that drops a role or hands it elsewhere never gets this far: the
     * rules below send it back.
     *
     * @param  list<array<string, mixed>>  $fields
     */
    private function syncFields(EquipmentType $type, array $fields): void
    {
        $kept = [];

        foreach (array_values($fields) as $position => $field) {
            $attributes = [
                'role' => $field['role'] ?? null,
                'name' => trim($field['name']),
                'type' => $field['type'],
                // Only a list has a list of choices; the rest would only keep a
                // stale one around for the day somebody changes the type back.
                'options' => $field['type'] === 'select' ? array_values(array_filter(array_map('trim', $field['options'] ?? []))) : null,
                'required' => (bool) ($field['required'] ?? false),
                'position' => $position,
            ];

            $row = isset($field['id'])
                ? $type->fields()->whereKey($field['id'])->first()
                : null;

            if ($row !== null) {
                $row->update($attributes);
            } else {
                $row = $type->fields()->create($attributes);
            }

            $kept[] = $row->id;
        }

        $type->fields()->whereKeyNot($kept)->delete();
    }

    /**
     * @return array{name: string, icon: string|null, has_accessories?: bool, fields?: list<array<string, mixed>>}
     */
    private function validated(Request $request, ?EquipmentType $type = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('equipment_types', 'name')->ignore($type)],
            // One of the drawings the interface has, or none for the plain box.
            'icon' => ['nullable', Rule::in(EquipmentIcons::KEYS)],
            // Whether a unit of this kind comes with anything at all.
            'has_accessories' => ['sometimes', 'boolean'],

            // A category with nothing of its own is a category all the same,
            // so saying nothing about the fields leaves them as they are. A list
            // that is sent, though, has to carry the two roles through.
            'fields' => ['nullable', 'array', 'max:20', function (string $attribute, mixed $value, Closure $fail) use ($type) {
                $this->keepsRoles((array) $value, $type, $fail);
            }],
            // A field already in the category keeps its id, so what the units
            // have written in it survives a rename.
            'fields.*.id' => ['nullable', 'integer', Rule::exists('equipment_fields', 'id')->where('equipment_type_id', $type?->id ?? 0)],
            // Nothing for an ordinary field; one of the two for the pair a unit
            // is named by, exactly as the page was given it.
            'fields.*.role' => ['nullable', Rule::in(array_keys(EquipmentField::ROLES))],
            'fields.*.name' => ['required', 'string', 'max:100', 'distinct:ignore_case'],
            'fields.*.type' => ['required', Rule::in(array_keys(EquipmentField::TYPES))],
            'fields.*.required' => ['boolean'],
            // A list with nothing to choose from is not a list. The question is
            // about a field's type and its choices together, so it is asked of
            // the pair rather than of one value.
            'fields.*.options' => ['array', 'max:30', function (string $attribute, mixed $value, Closure $fail) use ($request) {
                $index = explode('.', $attribute)[1];

                if ($request->input("fields.{$index}.type") === 'select' && array_filter(array_map('trim', (array) $value)) === []) {
                    $fail('Укажите хотя бы один вариант для выбора.');
                }
            }],
            'fields.*.options.*' => ['nullable', 'string', 'max:100'],
        ], attributes: [
            'name' => 'название',
            'icon' => 'иконка',
            'has_accessories' => 'комплектация',
            'fields' => 'поля',
            'fields.*.name' => 'название поля',
            'fields.*.type' => 'тип поля',
            'fields.*.options' => 'варианты',
        ], messages: [
            'fields.*.name.distinct' => 'Два поля с одним названием: карточка прочиталась бы дважды.',
        ]);
    }

    /**
     * The two a unit cannot be without, in the shape the dialog sends a field.
     * What a new category starts out with, and what it is created with when the
     * request names no fields at all.
     *
     * @return list<array<string, mixed>>
     */
    private function roleFields(): array
    {
        return collect(EquipmentField::ROLES)
            ->map(fn (string $name, string $role) => ['role' => $role, 'name' => $name, 'type' => 'text', 'options' => [], 'required' => true])
            ->values()
            ->all();
    }

    /**
     * Whether the list still has one field for each role, on the field that
     * holds it now.
     *
     * A role field is a field like any other in all the ways that matter to the
     * person editing it: it can be renamed, dragged anywhere in the list and
     * stop being obligatory. The two things it cannot do are leave and change
     * hands — a category whose units have no name and no number cannot be read
     * in a list, named in the journal, written about in a letter or found by a
     * search, and the values already written in the field would go with it.
     *
     * A list that does either is refused with a sentence saying so, rather than
     * quietly mended: that is how this dialog answers every other impossible
     * field — two fields with one name and a list with nothing to choose from
     * are both sent back — and silently restoring a field somebody believes
     * they deleted is the one outcome they could not account for.
     *
     * @param  list<array<string, mixed>>  $fields
     */
    private function keepsRoles(array $fields, ?EquipmentType $type, Closure $fail): void
    {
        $sent = [];

        foreach ($fields as $field) {
            if (($role = $field['role'] ?? null) !== null) {
                $sent[$role][] = isset($field['id']) ? (int) $field['id'] : null;
            }
        }

        // Which field holds each role now; a category being created holds none
        // yet, and one that somehow lost a role may be given it back here.
        $held = $type === null
            ? collect()
            : $type->fields()->whereNotNull('role')->get(['id', 'role', 'name'])->keyBy('role');

        foreach (array_keys(EquipmentField::ROLES) as $role) {
            // Named as the category itself names it, so a renamed field is not
            // sent back under words nobody in this dialog has seen.
            $title = $held->get($role)?->name ?? EquipmentField::ROLES[$role];
            $ids = $sent[$role] ?? [];

            if ($ids === []) {
                $fail("Поле «{$title}» нельзя удалить: по нему единицу находят в списке, в журнале и в поиске.");

                continue;
            }

            if (count($ids) > 1) {
                $fail("Поле «{$title}» в категории одно: его роль нельзя отдать сразу двум полям.");

                continue;
            }

            if ($held->has($role) && $ids[0] !== $held->get($role)->id) {
                $fail("Роль поля «{$title}» нельзя передать другому полю.");
            }
        }
    }
}
