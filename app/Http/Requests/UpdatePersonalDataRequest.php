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
    use ListsCitizenships;

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

        return [
            'surname' => $line('surname', ['required', 'string', 'max:100']),
            'name' => $line('name', ['required', 'string', 'max:100']),
            'patronymic' => ['nullable', 'string', 'max:100'],
            'sex' => $line('sex', ['required', Rule::in(['male', 'female'])]),
            'birth_date' => ['nullable', 'date', 'before_or_equal:today'],
            'birth_place' => ['nullable', 'string', 'max:255'],
            ...$this->citizenshipRules(),
            'nationality' => ['nullable', 'string', 'max:255'],
            'home_address' => ['nullable', 'string', 'max:255'],

            'roles' => ['present', 'array'],
            'roles.*' => ['string', 'distinct', Rule::exists('roles', 'name'), $this->privilegedRoleGuard()],

            ...($this->places() ? Placements::rules() : ['placements' => ['nullable']]),
        ];
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
            'roles' => 'позиция',
            ...Placements::attributes(),
        ];
    }
}
