<?php

namespace App\Http\Requests;

use App\Rules\LatinEmail;
use App\Support\EmployeeFields;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The "Контакты" card of the profile: the employee's phone and whom to call
 * in an emergency.
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
     * Phones arrive as typed ("90 123 45 67", "+992 90 123-45-67") and are
     * stored in E.164, like the rest of the data. The email is lowercased
     * rather than refused, since its case carries no meaning.
     */
    protected function prepareForValidation(): void
    {
        $this->merge(collect(['phone', 'sos_phone'])
            ->filter(fn (string $key) => filled($this->input($key)))
            ->mapWithKeys(fn (string $key) => [$key => self::normalizePhone((string) $this->input($key))])
            ->all());

        if (filled($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim((string) $this->input('email')))]);
        }
    }

    public static function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone);

        // A local 9-digit mobile number gets the Tajik country code.
        return '+'.(strlen($digits) === 9 ? '992'.$digits : $digits);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $phone = ['nullable', 'string', 'regex:/^\+\d{11,15}$/'];

        // An address this viewer may not change is not one the form shows, so it
        // cannot be demanded here: the controller drops it from the save anyway,
        // and requiring it would fail on a field nobody can see.
        $editsEmail = in_array('email', EmployeeFields::editableBy($this->user(), $this->route('employee')), true);

        return [
            // The email is how the employee signs in, so it is required and
            // must stay unique; their own address is not a clash.
            'email' => $editsEmail
                ? ['required', 'string', 'email', new LatinEmail, 'max:255', Rule::unique('users', 'email')->ignore($this->route('employee'))]
                : ['nullable'],
            'phone' => $phone,
            'sos_phone' => $phone,
            'sos_contact' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'email' => 'электронная почта',
            'phone' => 'телефон',
            'sos_phone' => 'телефон SOS',
            'sos_contact' => 'чей это номер',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone.regex' => 'Телефон указан неверно.',
            'sos_phone.regex' => 'Телефон SOS указан неверно.',
        ];
    }
}
