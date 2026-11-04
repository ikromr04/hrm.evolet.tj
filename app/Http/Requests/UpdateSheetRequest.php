<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ListsCitizenships;
use App\Models\User;
use App\Support\EmployeeCard;
use App\Support\EmployeeFields;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * «Личный листок по учёту кадров», retyped on the card and sent in one request.
 *
 * The sheet is the paper a personnel office keeps, and until now its answers
 * were corrected a window at a time: the name in «Основное», the passport in
 * «Паспорт», the places of study one record each. Read as a sheet it is a single
 * page, so it is now edited as one — eleven blocks of the card filled in
 * together and saved by one button, because a sheet saved by halves is a sheet
 * somebody has to be asked about twice.
 *
 * The keys are the «Новый сотрудник» page's own, deliberately: the same eleven
 * blocks are filled in there, and one shape serving both is one shape to keep
 * right. The rules are that page's too — every one of them comes from
 * EmployeeCard, so the sheet cannot come to disagree with the card about what a
 * diploma's year may be or how a telephone number is written.
 *
 * What is not on the sheet is not accepted here, however much of the card the
 * viewer may change: the sign-in address, the company's number for them, the
 * kind of work, the hire date, the role, where they stand, the hardware and the
 * photograph. Those are other blocks of the card, each with a window of its own,
 * and a save that reached them from this door would be writing what the page
 * never showed.
 *
 * Who may change what is weighed line by line, the way UpdatePersonalDataRequest
 * weighs it: a line this viewer may not change has no rules here at all, which
 * keeps it out of validated() and so out of the save. That is the whole defence
 * of the sheet — it spans the lines of eleven different rights, and a form built
 * from a stale page, or by somebody trying it on, may carry a field its owner
 * was never shown.
 */
class UpdateSheetRequest extends FormRequest
{
    // The citizenships are tidied before the rules look at them, and so are the
    // telephones: one method prepares the request, so it calls them both.
    use ListsCitizenships {
        prepareForValidation as prepareCitizenships;
    }

    /**
     * The lines of the sheet, in the order it asks them, and what each is called
     * in the request.
     *
     * The key on the left is a field of App\Support\EmployeeFields, which is the
     * right that decides it; the names on the right are what the form sends, and
     * they are more than one wherever the card reads a line as a line and keeps
     * it in several columns — the series and the number of a passport are one
     * answer, so are the emergency number and whose it is, and so is a
     * three-way list with the flag that says whether it has anything in it.
     *
     * The sheet is the eleven blocks of the card and no more: nothing here names
     * the account, the role, the placements, the hardware or the photograph.
     *
     * @var array<string, list<string>>
     */
    private const LINES = [
        // Always readable, which is not the same as always theirs to retype.
        'surname' => ['surname'],
        'name' => ['name'],
        'patronymic' => ['patronymic'],
        'sex' => ['sex'],
        'birth_date' => ['birth_date'],
        'birth_place' => ['birth_place'],
        'nationality' => ['nationality'],
        'citizenship' => ['citizenship'],
        // The three lines that keep a document apiece: the line, its paper and
        // the asking for that paper to go are one answer, so one right carries
        // all three. Somebody who may not retype the line may not strip it of
        // what it is proof of either.
        'degree' => ['degree', 'degree_document', 'degree_document_remove'],
        'award' => ['award', 'award_document', 'award_document_remove'],
        'military' => ['military', 'military_document', 'military_document_remove'],
        // A list answered before it is filled: the rows and the «есть или нет»
        // behind them are the same question, so they stand or fall together.
        'educations' => ['has_educations', 'educations'],
        'languages' => ['languages'],
        'work_experiences' => ['has_experience', 'experiences'],
        'marital_status' => ['marital_status'],
        'spouse' => ['spouse_name', 'spouse_birth_date', 'spouse_occupation'],
        'children' => ['has_children', 'children'],
        'relatives' => ['relatives'],
        // One line of the sheet, as it is one line of a passport.
        'passport_number' => ['passport_series', 'passport_number'],
        'passport_issued_at' => ['passport_issued_at'],
        'passport_issued_by' => ['passport_issued_by'],
        'tax_number' => ['tax_number'],
        'home_address' => ['home_address'],
        'phone' => ['phone'],
        // A number and whose it is: half of that answer is no use to anybody.
        'sos_phone' => ['sos_phone', 'sos_contact'],
    ];

    /**
     * Whoever may change at least one line of the sheet may open it.
     *
     * Asked here and not on the route, because the route has one gate to give
     * and the sheet spans eleven blocks: a single block gate would be asking
     * whether somebody may change the passport in order to decide whether they
     * may retype a surname. What each of them actually writes is then decided
     * line by line below, which is where the answer belongs.
     */
    public function authorize(): bool
    {
        return $this->editableLines() !== [];
    }

    /**
     * The citizenships arrive as a list to be tidied; the telephones arrive as
     * typed and are stored in E.164, the way every form of this card stores them.
     *
     * And the five lists arrive as empty where they did not arrive at all,
     * which is not the contradiction of the «present» rules below that it
     * looks like. «Present» says what it has always said — that the sheet edits
     * every one of these lists whole, so the save speaks for all five and a form
     * that silently dropped one must not read as «take those away». What it
     * cannot say is how a form sends an empty list, because a form cannot: a
     * field is a name and a value, a list with no values appends no field, and
     * the name never arrives. Over plain form encoding nothing noticed, but the
     * moment a paper is attached anywhere on the sheet — a diploma, or the
     * document of the degree, the awards or the military papers — the save goes
     * multipart and every empty list goes missing with it. A colleague with no
     * relatives could not be given a diploma.
     *
     * So the two are read together: on a whole-sheet save a list that did not
     * come is an empty list. «Present» then goes on saying what it says — that
     * all five belong to every save of this sheet — without having to catch a
     * form out over the way a browser encodes nothing, which it cannot tell from
     * a field somebody dropped. The create page defaults three of its lists for
     * the same reason and says the same thing about them.
     *
     * The languages keep their «min:1» and an empty one is still refused — by
     * the rule that means it rather than by an accident of encoding — and the
     * two lists answered «есть» before they are filled keep their «required_if»,
     * which only fires because the list is here to be looked at.
     */
    protected function prepareForValidation(): void
    {
        $this->prepareCitizenships();

        $this->merge(EmployeeCard::normalizedPhones($this->all()));

        foreach (['educations', 'experiences', 'children', 'relatives', 'languages'] as $list) {
            $this->merge([$list => $this->input($list, [])]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // Every list of the sheet is edited whole, so it travels even when it is
        // empty: the page shows all of them at once, and a form that forgot a
        // field must not read as «take those away». Which is the one thing the
        // sheet says differently from «Новый сотрудник», where nothing is on
        // file yet and a list left out has nothing to wipe.
        $rows = ['present', 'array', 'max:'.EmployeeCard::MAX_ROWS];

        /** @var User $employee */
        $employee = $this->route('employee');

        return $this->ofEditableLines([
            /* Основное */
            // The eight plain lines of the block, described in EmployeeCard
            // with everything else the card's own windows and «Новый
            // сотрудник» ask for in common. The home address is among them,
            // which is why it is not down with the telephones.
            ...EmployeeCard::mainRules(),
            ...$this->citizenshipRules(),

            /* Учёная степень, звание */
            ...EmployeeCard::degreeRules(),
            // And the paper taken off the line, which the sheet asks for rather
            // than doing: the card's own windows delete a document outright,
            // and such a removal would be the one thing on this page «Отмена»
            // could not put back. So it travels as an answer among the others
            // and happens when they do.
            //
            // Asked for in so many words, because no file has always meant «do
            // not touch the file» on this card: inferred from an absence, every
            // save of a sheet nobody changed would strip every paper it holds.
            // Which is why it is nullable as well as optional — a form that
            // carries the flag unset is saying nothing, and nothing is the
            // answer that leaves the paper alone.
            'degree_document_remove' => ['nullable', 'boolean'],

            /* Образование */
            'has_educations' => ['nullable', 'boolean'],
            'educations' => [...$rows, EmployeeCard::EDUCATIONS_REQUIRED],
            ...EmployeeCard::educationRules('educations.*.'),
            // Which record of study a row is, so that one already on the card
            // goes on being that row and keeps the diploma hanging off it. The
            // sheet edits the whole list, and a list rewritten from scratch
            // would hand every row a new id and leave its paper behind — a save
            // that brings no file means «do not change the file», here as
            // everywhere else on this card.
            //
            // Which card the row belongs to is asked outright and not assumed:
            // an id from somebody else's card is refused rather than quietly
            // dropped, the same answer the windows that take one record at a
            // time give it. «Новый сотрудник» sends no ids at all — nothing is
            // on file yet for a row to be.
            'educations.*.id' => [
                'nullable', 'integer',
                Rule::exists('user_educations', 'id')->where('user_id', $employee->id),
            ],
            // And the diploma of a record taken off it, on the terms the three
            // lines' papers are taken off theirs. It belongs to the row rather
            // than to the list, because that is what a diploma hangs off: a row
            // without an id has nothing on file to take off, and a row with one
            // says which paper is meant.
            'educations.*.document_remove' => ['nullable', 'boolean'],

            /* Знание языков */
            // At least one, because everybody speaks something: an empty list
            // here is a part somebody skipped rather than an answer.
            'languages' => ['present', 'array', 'min:1'],
            ...EmployeeCard::languageRules(),

            /* Трудовая деятельность */
            'has_experience' => ['nullable', 'boolean'],
            'experiences' => [...$rows, EmployeeCard::EXPERIENCE_REQUIRED],
            ...EmployeeCard::experienceRules('experiences.*.'),
            // And which past job a row is, on the terms the places of study are
            // identified. There is no document at stake here, only the row
            // itself: a list rewritten from scratch on every save churns the
            // table and files the jobs again in whatever order the form happened
            // to hold them.
            'experiences.*.id' => [
                'nullable', 'integer',
                Rule::exists('user_work_experiences', 'id')->where('user_id', $employee->id),
            ],

            /* Государственные награды, поощрения */
            ...EmployeeCard::awardRules(),
            'award_document_remove' => ['nullable', 'boolean'],

            /* Отношение к воинской обязанности */
            ...EmployeeCard::militaryRules(),
            'military_document_remove' => ['nullable', 'boolean'],

            /* Семейное положение и состав семьи */
            ...EmployeeCard::familyRules(),
            'children' => [...$rows, EmployeeCard::CHILDREN_REQUIRED],
            'relatives' => $rows,

            /* Паспорт */
            ...EmployeeCard::passportRules(),

            /* Контакты */
            'home_address' => ['nullable', 'string', 'max:255'],
            ...EmployeeCard::contactRules(),
        ]);
    }

    /**
     * The rules of the lines this viewer may change, and nothing else.
     *
     * A line that is not theirs loses its rules outright rather than being
     * softened to «nullable»: that is what keeps it out of validated(), and so
     * out of the save, with no second list for the controller to remember. The
     * form may carry whatever it likes — an old page, a hand-built request — and
     * the keys it was not shown are simply not read.
     *
     * The rules of a list hang off its name — «educations.*.institution» — so
     * they go with the line that owns the list.
     *
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    private function ofEditableLines(array $rules): array
    {
        $editable = $this->editableLines();

        foreach (self::LINES as $line => $keys) {
            if (in_array($line, $editable, true)) {
                continue;
            }

            $rules = array_filter(
                $rules,
                fn (string $key) => ! collect($keys)->contains(
                    fn (string $own) => $key === $own || str_starts_with($key, "{$own}."),
                ),
                ARRAY_FILTER_USE_KEY,
            );
        }

        return $rules;
    }

    /**
     * Which lines of the sheet are this viewer's to change.
     *
     * editableBy() picks the scope itself: one's own card is read against the
     * profile rights, anybody else's against the employees ones.
     *
     * @return list<string>
     */
    private function editableLines(): array
    {
        /** @var User $employee */
        $employee = $this->route('employee');

        return array_values(array_intersect(
            array_keys(self::LINES),
            EmployeeFields::editableBy($this->user(), $employee),
        ));
    }

    /**
     * The one check no rule can make a value at a time: whether a past job was
     * left before it was joined.
     *
     * Said on the very field it happened on — «experiences.2.ended_year» — so
     * the page can show it under the box somebody typed it in, however far down
     * the sheet that box is. And only where the jobs are this viewer's to
     * change: a list that is not being saved is not argued with.
     *
     * @return array<int, Closure>
     */
    public function after(): array
    {
        return [
            fn (Validator $validator) => in_array('work_experiences', $this->editableLines(), true)
                ? collect((array) $this->input('experiences', []))
                    ->each(fn (mixed $record, int|string $index) => EmployeeCard::checkExperienceDates(
                        $validator,
                        is_array($record) ? $record : [],
                        "experiences.{$index}.",
                    ))
                : null,
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
            ...EmployeeCard::degreeAttributes(),
            ...EmployeeCard::educationAttributes('educations.*.'),
            // Named for the sake of the one refusal they can produce: a row
            // claiming to be a record of somebody else's card.
            'educations.*.id' => 'учебное заведение',
            'experiences.*.id' => 'место работы',
            // And the four flags that ask for a paper to go, named for the same
            // reason: no page can send one of them as anything but yes or no,
            // but the refusal of a request built by hand still has to read as
            // Russian rather than as the name of a column.
            'degree_document_remove' => 'документ',
            'award_document_remove' => 'документ',
            'military_document_remove' => 'документ',
            'educations.*.document_remove' => 'документ',
            ...EmployeeCard::languageAttributes(),
            ...EmployeeCard::experienceAttributes('experiences.*.'),
            ...EmployeeCard::awardAttributes(),
            ...EmployeeCard::militaryAttributes(),
            ...EmployeeCard::familyAttributes(),
            ...EmployeeCard::passportAttributes(),
            ...EmployeeCard::contactAttributes(),
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
