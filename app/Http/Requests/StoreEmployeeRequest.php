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
 * they sign in with, the one role they carry and where they stand — is this
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
     *
     * And the six lists that are answered before they are filled arrive as
     * empty where they did not arrive at all. On a card being written there is
     * nothing for a missing list to mean but "none of them": nothing is on file
     * to be left alone. Said here because «sometimes» drops every rule for a key
     * that is not there, «required_if» among them — so a card that answered
     * «есть» and sent no list at all would have had the answer quietly taken
     * back instead of being told to name one.
     */
    protected function prepareForValidation(): void
    {
        $this->prepareCitizenships();

        $this->merge(EmployeeCard::normalizedPhones($this->all()));

        foreach (['educations', 'experiences', 'children', 'degrees', 'awards', 'military_records'] as $list) {
            $this->merge([$list => $this->input($list, [])]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rows = ['sometimes', 'array', 'max:'.EmployeeCard::MAX_ROWS];

        return [
            /* Основное */
            // The eight plain lines of the block, described in EmployeeCard
            // with everything else the card's own windows and this page ask
            // for in common.
            ...EmployeeCard::mainRules(),
            ...$this->citizenshipRules(),

            // The address they sign in with, so it belongs to one person only.
            // The password is not asked for: one is generated and mailed there.
            'email' => ['required', 'string', 'lowercase', 'email', new LatinEmail, 'max:255', Rule::unique('users', 'email')],

            // The company's own number for them, the kind of work and the
            // branch — described with the card's own «Основное», so the two
            // forms cannot come to disagree about what a number looks like.
            ...EmployeeCard::accountRules(),

            ...EmployeeCard::employmentRules(),

            // The role, in the singular, because there are four of them and they
            // are an order rather than a set: a person is personnel, or a head,
            // or neither. Asked for and not worked out: what somebody may do
            // here is not a thing to be left to a default, and the page always
            // has an answer to send — the system administrator picks it, and for
            // everybody else the job decides it.
            'role' => ['required', 'string', Rule::exists('roles', 'name'), $this->privilegedRoleGuard()],

            // Where they stand in the company: a position, the department that
            // comes with it and the duties picked out of it, as many such chains
            // as the person has.
            ...Placements::rules(),

            /* Контакты и языки */
            ...EmployeeCard::contactRules(),
            // At least one, because everybody speaks something: an empty list
            // here is a part somebody skipped rather than an answer.
            'languages' => ['present', 'array', 'min:1'],
            ...EmployeeCard::languageRules(),

            /* Семейное положение и состав семьи, и паспорт */
            ...EmployeeCard::passportRules(),
            ...EmployeeCard::familyRules(),
            'children' => [...$rows, EmployeeCard::CHILDREN_REQUIRED],
            'relatives' => $rows,

            /* Образование */
            // Answered before it is filled, as the children are: an empty list
            // means «нет» only where somebody said so.
            'has_educations' => ['nullable', 'boolean'],
            'educations' => [...$rows, EmployeeCard::EDUCATIONS_REQUIRED],
            ...EmployeeCard::educationRules('educations.*.'),

            /* Учёная степень, звание */
            // A list answered before it is filled, as the education above is.
            // The three blocks that follow this pattern were one line of text
            // apiece until November 2026, and each was being asked to hold more
            // than one answer — a degree and a title, two medals, the standing
            // and the rank in the reserve — with one scan to prove all of it.
            //
            // No row says which record on file it is, as the places of study do
            // on the card: nothing is on file for a colleague who does not
            // exist yet, so every row here is a new one.
            'has_degrees' => ['nullable', 'boolean'],
            'degrees' => [...$rows, EmployeeCard::DEGREES_REQUIRED],
            ...EmployeeCard::paperRules('degrees.*.'),

            /* Трудовая деятельность */
            'has_experience' => ['nullable', 'boolean'],
            'experiences' => [...$rows, EmployeeCard::EXPERIENCE_REQUIRED],
            ...EmployeeCard::experienceRules('experiences.*.'),

            /* Государственные награды, поощрения */
            'has_awards' => ['nullable', 'boolean'],
            'awards' => [...$rows, EmployeeCard::AWARDS_REQUIRED],
            ...EmployeeCard::paperRules('awards.*.'),

            /* Отношение к воинской обязанности */
            'has_military_records' => ['nullable', 'boolean'],
            'military_records' => [...$rows, EmployeeCard::MILITARY_RECORDS_REQUIRED],
            ...EmployeeCard::paperRules('military_records.*.'),

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
            // The role and the jobs have to agree, and on a card being written
            // there is nothing older than this request for them to disagree with.
            fn (Validator $validator) => $this->guardRoleAgainstPositions($validator),
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
            ...EmployeeCard::mainAttributes(),
            'citizenship' => 'гражданство',
            'citizenship.*' => 'гражданство',
            'email' => 'электронная почта',
            'role' => 'роль',
            ...EmployeeCard::accountAttributes(),
            ...EmployeeCard::employmentAttributes(),
            ...Placements::attributes(),
            ...EmployeeCard::contactAttributes(),
            ...EmployeeCard::languageAttributes(),
            ...EmployeeCard::passportAttributes(),
            ...EmployeeCard::familyAttributes(),
            ...EmployeeCard::educationAttributes('educations.*.'),
            // Each record of the three lists is named by the block it stands
            // in: a refusal reading «Поле название обязательно» says nothing
            // about which part of the page was being filled in.
            ...EmployeeCard::paperAttributes('degrees.*.', 'учёная степень, звание'),
            ...EmployeeCard::paperAttributes('awards.*.', 'награда или поощрение'),
            ...EmployeeCard::paperAttributes('military_records.*.', 'отношение к воинской обязанности'),
            ...EmployeeCard::experienceAttributes('experiences.*.'),
            ...EmployeeCard::equipmentAttributes(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...EmployeeCard::contactMessages(),
            ...EmployeeCard::familyMessages(),
            ...EmployeeCard::listMessages(),
        ];
    }
}
