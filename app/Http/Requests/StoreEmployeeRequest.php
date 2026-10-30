<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\GuardsPrivilegedRoles;
use App\Http\Requests\Concerns\ListsCitizenships;
use App\Rules\LatinEmail;
use App\Support\EmployeeCard;
use App\Support\Placements;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Putting a colleague on the books: the whole card in one request.
 *
 * Every block the card is read in is filled in on one page and sent together —
 * who they are, how to reach them, their papers and their family, where they
 * studied, where they worked, and what hardware they are handed. Either the
 * colleague is on the books with all of it or nothing happened, which is what
 * the controller's transaction is for and what this request has to describe in
 * full before the transaction opens.
 *
 * What each block may hold is not written out here. It is described once, in
 * EmployeeCard, and the forms of the card validate against the same methods:
 * one rule, two callers, nothing to drift. Only the first block — the account
 * they sign in with, the roles they carry and where they stand — is this
 * request's own, because only this request creates an account.
 *
 * The lists are «sometimes» rather than «present», which is the one place the
 * two callers differ on purpose. On the card a list travels even when empty, so
 * that a form which forgot the field cannot read as «take them all away»; here
 * nothing is on file yet, and a block left out of the request is a block with
 * nothing in it.
 */
class StoreEmployeeRequest extends FormRequest
{
    use GuardsPrivilegedRoles;

    // The citizenships are tidied before the rules look at them, and so are the
    // telephones: one method prepares the request, so it calls them both.
    use ListsCitizenships {
        prepareForValidation as prepareCitizenships;
    }

    /**
     * The route already requires the right to add employees, and that right
     * covers the whole card: whoever puts a colleague on the books fills every
     * block of it in, whatever lines they may change on somebody already on file.
     *
     * Handing hardware over is the one exception, because it is not a line of the
     * card at all but an operation on the fleet — so it keeps the right the
     * fleet asks for, exactly as EmployeeEquipmentController does. The page
     * leaves the block out without it, so this only answers a request that
     * carried units anyway.
     */
    public function authorize(): bool
    {
        return blank($this->input('equipment')) || $this->user()->can('equipment.issue');
    }

    /**
     * The citizenships arrive as a list to be tidied; the telephones arrive as
     * typed and are stored in E.164, the same way the card's own form stores them.
     */
    protected function prepareForValidation(): void
    {
        $this->prepareCitizenships();

        $this->merge(EmployeeCard::normalizedPhones($this->all()));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rows = ['sometimes', 'array', 'max:'.EmployeeCard::MAX_ROWS];

        return [
            /* Основное */
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
            'email' => ['required', 'string', 'lowercase', 'email', new LatinEmail, 'max:255', Rule::unique('users', 'email')],

            // The company's own number for them, the kind of work and the
            // branch — described with the card's own «Основное», so the two
            // forms cannot come to disagree about what a number looks like.
            ...EmployeeCard::accountRules(),

            ...EmployeeCard::employmentRules(),

            'roles' => ['present', 'array'],
            'roles.*' => ['string', 'distinct', Rule::exists('roles', 'name'), $this->privilegedRoleGuard()],

            // Where they stand in the company: a position, the department that
            // comes with it and the duties picked out of it, as many such chains
            // as the person has.
            ...Placements::rules(),

            /* Контакты и языки */
            ...EmployeeCard::contactRules(),
            'languages' => ['sometimes', 'array'],
            ...EmployeeCard::languageRules(),

            /* Паспорт и семья */
            ...EmployeeCard::passportRules(),
            ...EmployeeCard::familyRules(),
            'children' => $rows,

            /* Образование */
            'educations' => $rows,
            ...EmployeeCard::educationRules('educations.*.'),

            /* Трудовая деятельность */
            'experiences' => $rows,
            ...EmployeeCard::experienceRules('experiences.*.'),

            /* Оборудование */
            'equipment' => $rows,
            ...EmployeeCard::equipmentRules(),
        ];
    }

    /**
     * The checks no rule can make one value at a time: whether each placement
     * hangs together, and whether a past job was left before it was joined.
     *
     * Each is said on the very field it happened on — «experiences.2.ended_year»
     * — so the page can show it under the box somebody typed it in, however far
     * down the page that box is.
     *
     * @return array<int, Closure>
     */
    public function after(): array
    {
        return [
            fn (Validator $validator) => Placements::check($validator),
            fn (Validator $validator) => collect((array) $this->input('experiences', []))
                ->each(fn (mixed $record, int|string $index) => EmployeeCard::checkExperienceDates(
                    $validator,
                    is_array($record) ? $record : [],
                    "experiences.{$index}.",
                )),
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
            'email' => 'электронная почта',
            'roles' => 'позиция',
            ...EmployeeCard::accountAttributes(),
            ...EmployeeCard::employmentAttributes(),
            ...Placements::attributes(),
            ...EmployeeCard::contactAttributes(),
            ...EmployeeCard::languageAttributes(),
            ...EmployeeCard::passportAttributes(),
            ...EmployeeCard::familyAttributes(),
            ...EmployeeCard::educationAttributes('educations.*.'),
            ...EmployeeCard::experienceAttributes('experiences.*.'),
            ...EmployeeCard::equipmentAttributes(),
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
