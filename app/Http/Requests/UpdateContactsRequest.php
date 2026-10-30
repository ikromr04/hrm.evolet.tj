<?php

namespace App\Http\Requests;

use App\Rules\LatinEmail;
use App\Support\EmployeeCard;
use App\Support\EmployeeFields;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The "Контакты" card of the profile: the employee's phone and whom to call
 * in an emergency.
 *
 * The telephones themselves are described in EmployeeCard, which is where the
 * create page reads them from as well.
 */
class UpdateContactsRequest extends FormRequest
{
    /**
     * The route already requires the right to change employees.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The phones are tidied into E.164 the same way for every form that asks
     * for them. The email is lowercased rather than refused, since its case
     * carries no meaning.
     */
    protected function prepareForValidation(): void
    {
        $this->merge(EmployeeCard::normalizedPhones($this->all()));

        if (filled($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim((string) $this->input('email')))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // An address this viewer may not change is not one the form shows, so it
        // cannot be demanded here: the controller drops it from the save anyway,
        // and requiring it would fail on a field nobody can see.
        $editsEmail = in_array('email', EmployeeFields::editableBy($this->user(), $this->route('employee')), true);

        return [
            // The email is how the employee signs in, so it is required and
            // must stay unique; their own address is not a clash. It is the
            // account rather than a line of this block, which is why it is not
            // among the shared rules: the create form must clash with everybody,
            // this one with everybody but its owner.
            'email' => $editsEmail
                ? ['required', 'string', 'email', new LatinEmail, 'max:255', Rule::unique('users', 'email')->ignore($this->route('employee'))]
                : ['nullable'],
            ...EmployeeCard::contactRules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'email' => 'электронная почта',
            ...EmployeeCard::contactAttributes(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return EmployeeCard::contactMessages();
    }
}
