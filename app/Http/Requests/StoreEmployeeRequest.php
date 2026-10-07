<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\GuardsPrivilegedRoles;
use App\Http\Requests\Concerns\ListsCitizenships;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Putting a colleague on the books: the account they sign in with and the
 * little that identifies them. Everything else — the passport, the contacts,
 * the family, what they studied — is filled in on the profile afterwards,
 * card by card, so the form that starts a person off stays short.
 */
class StoreEmployeeRequest extends FormRequest
{
    use GuardsPrivilegedRoles;
    use ListsCitizenships;

    /**
     * The route already requires the right to add employees.
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
        return [
            'surname' => ['required', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:100'],
            'patronymic' => ['nullable', 'string', 'max:100'],
            'sex' => ['required', Rule::in(['male', 'female'])],
            'birth_date' => ['nullable', 'date', 'before_or_equal:today'],
            'birth_place' => ['nullable', 'string', 'max:255'],
            ...$this->citizenshipRules(),
            'nationality' => ['nullable', 'string', 'max:255'],
            'home_address' => ['nullable', 'string', 'max:255'],

            // The address they sign in with, so it belongs to one person only.
            // The password is not asked for: one is generated and mailed there.
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique('users', 'email')],

            'hired_at' => ['nullable', 'date', 'before_or_equal:today'],

            'roles' => ['present', 'array'],
            'roles.*' => ['string', 'distinct', Rule::exists('roles', 'name'), $this->privilegedRoleGuard()],
            'positions' => ['present', 'array'],
            'positions.*' => ['integer', 'distinct', Rule::exists('positions', 'id')],
            'departments' => ['present', 'array'],
            'departments.*' => ['integer', 'distinct', Rule::exists('departments', 'id')],
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
            'email' => 'e-mail',
            'hired_at' => 'начало работы',
            'roles' => 'позиция',
            'positions' => 'должность',
            'departments' => 'отдел',
        ];
    }
}
