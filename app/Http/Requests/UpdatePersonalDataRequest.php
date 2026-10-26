<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\GuardsPrivilegedRoles;
use App\Http\Requests\Concerns\ListsCitizenships;
use App\Models\User;
use App\Support\EmployeeFields;
use App\Support\Placements;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The "Основные данные" card of the profile: the name and personal facts, plus
 * the roles the employee carries and where they stand in the company.
 */
class UpdatePersonalDataRequest extends FormRequest
{
    use GuardsPrivilegedRoles;

    // The citizenships are tidied before the rules look at them, and so is the
    // number below: one method prepares the request, so it calls them both.
    use ListsCitizenships {
        prepareForValidation as prepareCitizenships;
    }

    /**
     * The route already requires the right to change employees.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var User $employee */
        $employee = $this->route('employee');
        $editable = EmployeeFields::editableBy($this->user(), $employee);

        /**
         * A line this viewer may not change is not one the form shows, so it is not
         * one the form can be asked to send. The controller drops it from the save
         * in any case; demanding it here would only fail on a field nobody can see,
         * and the window would sit there saying nothing.
         *
         * @param  list<mixed>  $rules
         * @return list<mixed>
         */
        $line = fn (string $field, array $rules) => in_array($field, $editable, true) ? $rules : ['nullable'];

        /**
         * And a line the form left out is one it does not touch: «Основное» is
         * one window of this card and the lines are shared out between them, so
         * asking every window for every line would have each of them saving
         * what it never showed.
         *
         * @param  list<mixed>  $rules
         * @return list<mixed>
         */
        $sent = fn (array $rules) => ['sometimes', ...$rules];

        return [
            'surname' => $sent($line('surname', ['required', 'string', 'max:100'])),
            'name' => $sent($line('name', ['required', 'string', 'max:100'])),
            'patronymic' => $sent(['nullable', 'string', 'max:100']),
            'sex' => $sent($line('sex', ['required', Rule::in(['male', 'female'])])),
            'birth_date' => $sent(['nullable', 'date', 'before_or_equal:today']),
            'birth_place' => $sent(['nullable', 'string', 'max:255']),
            ...collect($this->citizenshipRules())->map(fn (array $rules) => $sent($rules))->all(),
            'nationality' => $sent(['nullable', 'string', 'max:255']),
            'home_address' => $sent(['nullable', 'string', 'max:255']),

            // The company's own number. It may be left empty, and it is written
            // in at least five digits — the first of them is a nought on nearly
            // every card, so this is a string of digits and not a number.
            'employee_number' => $sent($line('employee_number', [
                'nullable', 'string', 'regex:/^\d{5,}$/', 'max:20',
                Rule::unique('users', 'employee_number')->ignore($employee),
            ])),
            // One entry of a list each, or none, and named rather than
            // numbered: one missing from the list is written into it on the
            // save, the way a country typed into the citizenships is.
            'employment_type' => $sent($line('employment_type', ['nullable', 'string', 'max:100'])),
            'branch' => $sent($line('branch', ['nullable', 'string', 'max:100'])),

            'roles' => $sent(['present', 'array']),
            'roles.*' => ['string', 'distinct', Rule::exists('roles', 'name'), $this->privilegedRoleGuard()],

            ...collect($this->places() ? Placements::rules() : ['placements' => ['nullable']])
                ->map(fn (array $rules, string $key) => $key === 'placements' ? $sent($rules) : $rules)
                ->all(),
        ];
    }

    /**
     * A number nobody typed is no number: the box is left empty rather than
     * failing against the five digits it would have to be written in.
     */
    protected function prepareForValidation(): void
    {
        $this->prepareCitizenships();

        if ($this->has('employee_number') && trim((string) $this->input('employee_number')) === '') {
            $this->merge(['employee_number' => null]);
        }
    }

    /**
     * Whether where this colleague stands is this viewer's to change.
     *
     * It is one answer and two lines of the card: a department with a position
     * of that department in it, and the duties picked out of that position.
     * Half a placement is nothing to save, so it is carried only when both
     * lines are theirs; otherwise the form may send whatever it likes and the
     * controller drops it, as it drops any line that is not.
     */
    private function places(): bool
    {
        /** @var User $employee */
        $employee = $this->route('employee');
        $editable = EmployeeFields::editableBy($this->user(), $employee);

        return in_array('positions', $editable, true) && in_array('departments', $editable, true);
    }

    /**
     * @return array<int, Closure>
     */
    public function after(): array
    {
        /** @var User $employee */
        $employee = $this->route('employee');

        return [
            fn (Validator $validator) => $this->guardPrivilegedChanges($validator, $employee),
            // Nothing to say about a placement this viewer cannot change: it is
            // dropped from the save rather than argued with.
            fn (Validator $validator) => $this->places() ? Placements::check($validator) : null,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'surname' => 'фамилия',
            'name' => 'имя',
            'patronymic' => 'отчество',
            'sex' => 'пол',
            'birth_date' => 'дата рождения',
            'birth_place' => 'место рождения',
            'citizenship' => 'гражданство',
            'citizenship.*' => 'гражданство',
            'nationality' => 'национальность',
            'home_address' => 'домашний адрес',
            'employee_number' => 'номер сотрудника',
            'employment_type' => 'тип занятости',
            'branch' => 'филиал',
            'roles' => 'позиция',
            ...Placements::attributes(),
        ];
    }
}
