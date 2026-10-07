<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateContactsRequest;
use App\Http\Requests\UpdateFamilyRequest;
use App\Http\Requests\UpdatePersonalDataRequest;
use App\Models\Citizenship;
use App\Models\Language;
use App\Models\User;
use App\Notifications\PlacementChanged;
use App\Support\EmployeeFields;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Edits one block of the profile at a time, the way the page shows it: each
 * method takes just the fields of its own card.
 */
class EmployeeDetailsController extends Controller
{
    /** The name and sex live on the user, the rest of the plain fields on the details. */
    private const ON_USER = ['surname', 'name', 'patronymic', 'sex'];

    private const RELATIONS = ['roles', 'positions', 'departments'];

    /**
     * What a line of these two blocks is called in the request, where that is not
     * the name of the field itself. The two are the blocks a position is given
     * line by line, so a form may carry more than the viewer is allowed to
     * change — from a stale page, or from somebody trying it on — and what they
     * may not change is simply not saved.
     *
     * @var array<string, list<string>>
     */
    private const LINES = [
        // Always readable, not always theirs to retype.
        'surname' => ['surname'],
        'name' => ['name'],
        'patronymic' => ['patronymic'],
        'sex' => ['sex'],
        'birth_date' => ['birth_date'],
        'birth_place' => ['birth_place'],
        'citizenship' => ['citizenship'],
        'nationality' => ['nationality'],
        'home_address' => ['home_address'],
        'roles' => ['roles'],
        'positions' => ['positions'],
        'departments' => ['departments'],
        'email' => ['email'],
        'phone' => ['phone'],
        'sos_phone' => ['sos_phone', 'sos_contact'],
    ];

    /**
     * The "Основные данные" card.
     */
    public function personal(UpdatePersonalDataRequest $request, User $employee): RedirectResponse
    {
        $data = $this->allowed($request, $employee, $request->validated());

        // Where the person stood before the save, so they can be told what
        // moved. Not while they are still being put on the books: the wizard
        // saves this card again as one of its steps, and somebody who has not
        // signed in once has no "before" to hear a change from.
        $placement = EmployeeFields::isBeingCreatedBy($request->user(), $employee)
            ? null
            : PlacementChanged::snapshot($employee);

        DB::transaction(function () use ($employee, $data) {
            $employee->update(Arr::only($data, self::ON_USER));

            $details = Arr::except($data, [...self::ON_USER, ...self::RELATIONS, 'citizenship']);

            // Countries by name: one missing from the directory is added to it,
            // so it can be typed in right here rather than in «Справочники».
            if (array_key_exists('citizenship', $data)) {
                $employee->citizenships()->sync(Citizenship::idsFor($data['citizenship'] ?? []));
            }

            if ($details !== []) {
                $employee->details()->updateOrCreate([], $details);
            }

            // A relation left out of the form is left as it is, rather than being
            // emptied by a save that was never allowed to touch it.
            foreach (['roles' => 'syncRoles', 'positions' => 'positions', 'departments' => 'departments'] as $key => $how) {
                if (! array_key_exists($key, $data)) {
                    continue;
                }

                // Departments the employee stays in keep their head flag.
                $how === 'syncRoles' ? $employee->syncRoles($data[$key]) : $employee->{$how}()->sync($data[$key]);
            }
        });

        if ($placement !== null) {
            PlacementChanged::announce($employee, $placement, $request->user());
        }

        return back();
    }

    /**
     * The "Паспорт" card. Every field is optional: a new hire may be on file
     * before their document is.
     */
    public function passport(Request $request, User $employee): RedirectResponse
    {
        $data = $request->validate([
            'passport_series' => ['nullable', 'string', 'max:10'],
            'passport_number' => ['nullable', 'string', 'max:20'],
            'passport_issued_at' => ['nullable', 'date', 'before_or_equal:today'],
            'passport_issued_by' => ['nullable', 'string', 'max:150'],
        ], attributes: [
            'passport_series' => 'серия паспорта',
            'passport_number' => 'номер паспорта',
            'passport_issued_at' => 'дата выдачи',
            'passport_issued_by' => 'кем выдан',
        ]);

        $employee->details()->updateOrCreate([], $data);

        return back();
    }

    /**
     * The "Контакты" card. The phones are normalised to E.164 by the request;
     * the email is the sign-in address and so lives on the user.
     */
    public function contacts(UpdateContactsRequest $request, User $employee): RedirectResponse
    {
        $data = $this->allowed($request, $employee, $request->validated());

        DB::transaction(function () use ($employee, $data) {
            if (array_key_exists('email', $data)) {
                $employee->update(['email' => $data['email']]);
            }

            $details = Arr::except($data, 'email');

            if ($details !== []) {
                $employee->details()->updateOrCreate([], $details);
            }
        });

        return back();
    }

    /**
     * The part of a save the viewer is allowed to make. Every line of the card is
     * on the list, the name and the surname included: those two are always
     * readable, which is not the same as always theirs to retype.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function allowed(Request $request, User $employee, array $data): array
    {
        $editable = EmployeeFields::editableBy($request->user(), $employee);

        foreach (self::LINES as $field => $keys) {
            if (! in_array($field, $editable, true)) {
                $data = Arr::except($data, $keys);
            }
        }

        return $data;
    }

    /**
     * The hire date, shown bare at the top of the sidebar. Tenure is counted
     * from it, so a date in the future would read as negative service.
     */
    public function employment(Request $request, User $employee): RedirectResponse
    {
        $data = $request->validate([
            'hired_at' => ['nullable', 'date', 'before_or_equal:today'],
        ], attributes: ['hired_at' => 'начало работы']);

        $employee->details()->updateOrCreate([], $data);

        return back();
    }

    /**
     * The "Знание языков" card. Unlike the other cards here, languages are
     * public: every colleague sees them.
     */
    public function languages(Request $request, User $employee): RedirectResponse
    {
        $data = $request->validate([
            'languages' => ['present', 'array'],
            // Each language once, so the list cannot hold two levels for one.
            'languages.*.id' => ['nullable', 'required_without:languages.*.name', 'integer', 'distinct', Rule::exists('languages', 'id')],
            // A language missing from the list is named instead, and added to it
            // here: whoever may fill this card in may also add the language.
            'languages.*.name' => ['nullable', 'required_without:languages.*.id', 'string', 'max:100'],
            'languages.*.level' => ['required', Rule::in(Language::LEVELS)],
        ], attributes: [
            'languages.*.id' => 'язык',
            'languages.*.name' => 'язык',
            'languages.*.level' => 'уровень',
        ]);

        DB::transaction(function () use ($employee, $data) {
            // Matched without regard to case, so "китайский" finds "Китайский"
            // rather than putting a second one on the list.
            $known = Language::query()->get(['id', 'name'])->keyBy(fn (Language $l) => mb_strtolower($l->name));

            $levels = collect($data['languages'])->mapWithKeys(function (array $l) use ($known) {
                $id = $l['id'] ?? null;

                if ($id === null) {
                    $name = trim($l['name']);
                    $id = ($known[mb_strtolower($name)] ?? Language::create(['name' => $name]))->id;
                }

                return [$id => ['level' => $l['level']]];
            });

            $employee->languages()->sync($levels);
        });

        return back();
    }

    /**
     * The "Семья" card: marital status, the spouse and the children. The
     * children are replaced wholesale, the way the dialog edits them.
     *
     * Each of the three lines has a right of its own, and the request validates
     * only those this viewer may change; whatever it left out stays as it is on
     * file rather than being overwritten by the blanks of a form that never
     * showed it.
     */
    public function family(UpdateFamilyRequest $request, User $employee): RedirectResponse
    {
        $data = $request->validated();
        $details = Arr::only($data, ['marital_status', 'spouse_name', 'spouse_birth_date']);
        $children = $data['children'] ?? null;

        if ($children !== null) {
            // "Не указано" and "детей нет" look the same in an empty list, so the
            // flag keeps them apart; rows on file always mean there are children.
            $details['has_children'] = match (true) {
                $children !== [] => true,
                ($data['has_children'] ?? null) === false => false,
                default => null,
            };
        }

        DB::transaction(function () use ($employee, $details, $children) {
            if ($details !== []) {
                $employee->details()->updateOrCreate([], $details);
            }

            // Only whoever may change the children gets to replace them.
            if ($children !== null) {
                $employee->children()->delete();
                $employee->children()->createMany(array_map(
                    fn (array $child) => ['full_name' => $child['full_name'], 'birth_date' => $child['birth_date'] ?? null],
                    $children,
                ));
            }
        });

        return back();
    }
}
