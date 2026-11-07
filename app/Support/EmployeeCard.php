<?php

namespace App\Support;

use App\Models\Equipment;
use App\Models\User;
use App\Models\UserLanguage;
use App\Models\UserPaperRecord;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * What one block of a card may say, said once.
 *
 * A block is now filled in from two places: on the card, where it is edited on
 * its own through a form of its own, and on «Новый сотрудник», which sends every
 * block of the card in a single request. Two callers, one block — and two copies
 * of a rule is one rule somebody will change and one they will not. The year a
 * diploma may be dated to, the twenty rows a list is capped at, the shape of a
 * telephone number: all of it lives here, and both sides ask for it rather than
 * writing it out again.
 *
 * The writes that are more than «put these columns on the details row» live here
 * for the same reason. Adding a language the directory has not heard of, working
 * out whether an empty list of children means «нет детей» or «не указано»,
 * handing over a unit of hardware only if it is free: those are decisions, and
 * the two callers must make them the same way.
 *
 * Nothing here asks who is filling the block in. The card's own forms weigh that
 * against EmployeeFields line by line, and the create page is covered by
 * «employees.create» for the whole card at once; so the rights stay with the
 * callers, and this is only about what a block can hold.
 */
final class EmployeeCard
{
    /**
     * How many rows one of the card's lists may carry at a time: degrees, past
     * jobs, children, units of hardware handed over in one go.
     *
     * The cap belongs here rather than in each caller's wrapper, because the
     * wrappers differ for a reason the cap has nothing to do with: a card that
     * edits a list sends it even when empty («present»), so a form that forgot
     * the field cannot read as «take them all away», while nothing has yet been
     * filed for a colleague who is being created, and a block the create page
     * left out has nothing to wipe.
     */
    public const MAX_ROWS = 20;

    /** The three lines the «Семья» block is given out in, each with a right of its own. */
    public const FAMILY_LINES = ['marital_status', 'spouse', 'children', 'relatives'];

    /**
     * «Есть» is an answer that needs a row behind it: a card that says there
     * are children and names none is a card somebody has to go back and ask
     * about, which is the whole thing the three-way answer was there to avoid.
     *
     * Both forms of the flag, because a form carrying a file sends it as «1»
     * and one without sends it as true.
     *
     * It is a rule on the list itself, and the two places that take a list of
     * children describe that list differently — «present» on a card, where a
     * form that forgot the field must not wipe what is on file, and «nullable»
     * on the page that files a new colleague, where there is nothing to wipe.
     * So it is added to theirs rather than being one of ours.
     */
    public const CHILDREN_REQUIRED = 'required_if:has_children,true,1';

    /**
     * The same, for the two lists that were being left empty and read as
     * answered: where somebody studied and where they worked before.
     *
     * An empty list says two things — «нет» and «nobody asked» — so each of them
     * is asked outright first, and only «есть» brings out the rows. The rule
     * belongs to the list for the reason the children's does: the two places
     * that take one describe it differently.
     */
    public const EDUCATIONS_REQUIRED = 'required_if:has_educations,true,1';

    public const EXPERIENCE_REQUIRED = 'required_if:has_experience,true,1';

    /**
     * And the same again for the three blocks that were one line of text apiece
     * until November 2026: a degree, an award, a standing with the army.
     *
     * Each of them used to carry the word «Нет» in the line itself to be told
     * from a line nobody had filled in. They are lists now — a person has two
     * medals as readily as one, and each medal has a paper of its own — so the
     * «нет» moved to a flag beside the list, which is where the children's has
     * always been.
     */
    public const DEGREES_REQUIRED = 'required_if:has_degrees,true,1';

    public const AWARDS_REQUIRED = 'required_if:has_awards,true,1';

    public const MILITARY_RECORDS_REQUIRED = 'required_if:has_military_records,true,1';

    /* ------------------------------------------------------------ основное */

    /**
     * The eight plain lines of the first block: who the person is.
     *
     * Here for the reason everything else in this class is here — three forms
     * ask for them now. The card's own «Основное» window, «Новый сотрудник» and
     * «Личный листок» each wrote the eight out in full, and three copies of a
     * rule is two copies somebody will not change.
     *
     * Only the name is insisted on, and only because a card with no name on it
     * answers nothing. The rest of a card is filled in from what somebody
     * remembers, over however many conversations that takes.
     *
     * The citizenships are not here: they are a list rather than a line, tidied
     * before the rules look at them, and they live with the tidying — see
     * App\Http\Requests\Concerns\ListsCitizenships.
     *
     * @return array<string, mixed>
     */
    public static function mainRules(): array
    {
        return [
            'surname' => ['required', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:100'],
            'patronymic' => ['nullable', 'string', 'max:100'],
            // Said where it is known and left out where it is not: one of the
            // two values or nothing at all, never a third thing.
            'sex' => ['nullable', Rule::in(['male', 'female'])],
            'birth_date' => ['nullable', 'date', 'before_or_equal:today'],
            'birth_place' => ['nullable', 'string', 'max:255'],
            'nationality' => ['nullable', 'string', 'max:255'],
            'home_address' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function mainAttributes(): array
    {
        return [
            'surname' => 'фамилия',
            'name' => 'имя',
            'patronymic' => 'отчество',
            'sex' => 'пол',
            'birth_date' => 'дата рождения',
            'birth_place' => 'место рождения',
            'nationality' => 'национальность',
            'home_address' => 'домашний адрес',
        ];
    }

    /* ------------------------------------------------------------- паспорт */

    /**
     * Every field of the passport is optional: a new hire may be on file before
     * their document is, and a card is no place to refuse somebody over it.
     *
     * @return array<string, mixed>
     */
    public static function passportRules(): array
    {
        return [
            'passport_series' => ['nullable', 'string', 'max:10'],
            'passport_number' => ['nullable', 'string', 'max:20'],
            'passport_issued_at' => ['nullable', 'date', 'before_or_equal:today'],
            'passport_issued_by' => ['nullable', 'string', 'max:150'],
            // Written down and compared, never counted, so a string — and no
            // pattern beyond its length: the shape of such a code is the tax
            // office's business to change, not this application's to refuse.
            'tax_number' => ['nullable', 'string', 'max:20'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function passportAttributes(): array
    {
        return [
            'passport_series' => 'серия паспорта',
            'passport_number' => 'номер паспорта',
            'passport_issued_at' => 'дата выдачи',
            'passport_issued_by' => 'кем выдан',
            'tax_number' => 'ИНН',
        ];
    }

    /* ---------------------------------------------------- начало работы */

    /**
     * The hire date. Tenure is counted from it, so a date in the future would
     * read as negative service.
     *
     * @return array<string, mixed>
     */
    public static function employmentRules(): array
    {
        return ['hired_at' => ['nullable', 'date', 'before_or_equal:today']];
    }

    /**
     * @return array<string, string>
     */
    public static function employmentAttributes(): array
    {
        return ['hired_at' => 'начало работы'];
    }

    /* ----------------------------------------------------- учётная запись */

    /**
     * The two lines that sit on the account itself rather than beside it: the
     * company's own number for a person, and the kind of work they were taken
     * on for.
     *
     * The number is kept as text and not as a number, because the first
     * character is a nought on nearly every card and a number would eat it.
     * Its shape is the company's business rather than this form's: anything
     * up to twenty characters is accepted, and an empty box is no number at
     * all rather than a number of none.
     *
     * It is the one line of a card no two people may share, and that is the
     * whole reason this takes an argument: whoever is being edited is excused
     * from the comparison with themselves, and nobody is excused when a
     * colleague is being added, because there is nobody there yet to excuse.
     *
     * The kind of work is named rather than numbered: one the list has not
     * heard of is written into the list on the way in, the way a country typed
     * into the citizenships is, so a card can be filled in without a trip to
     * «Справочники» first.
     *
     * @return array<string, mixed>
     */
    public static function accountRules(?User $employee = null): array
    {
        $unique = Rule::unique('users', 'employee_number');

        return [
            'employee_number' => [
                'nullable', 'string', 'max:20',
                $employee === null ? $unique : $unique->ignore($employee),
            ],
            'employment_type' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function accountAttributes(): array
    {
        return [
            'employee_number' => 'номер сотрудника',
            'employment_type' => 'тип занятости',
        ];
    }
    /* ------------------------------------------------------------ контакты */

    /**
     * The telephones and whom the second of them belongs to. The sign-in address
     * is not here: it is the account rather than a line of this block, and the
     * two callers ask about it differently — one must not clash with anybody,
     * the other must not clash with anybody but its own owner.
     *
     * @return array<string, mixed>
     */
    public static function contactRules(): array
    {
        $phone = ['nullable', 'string', 'regex:/^\+\d{11,15}$/'];

        return [
            'phone' => $phone,
            'sos_phone' => $phone,
            'sos_contact' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function contactAttributes(): array
    {
        return [
            'phone' => 'телефон',
            'sos_phone' => 'экстренный контакт',
            'sos_contact' => 'чей это номер',
        ];
    }

    /**
     * The regular expression above says nothing a person would want to read, so
     * both forms say this instead.
     *
     * @return array<string, string>
     */
    public static function contactMessages(): array
    {
        return [
            'phone.regex' => 'Телефон указан неверно.',
            'sos_phone.regex' => 'Экстренный контакт указан неверно.',
        ];
    }

    /**
     * Phones arrive as typed — «90 123 45 67», «+992 90 123-45-67» — and are
     * stored in E.164 like the rest of the data, so whichever form sent them
     * tidies them the same way before the rules above look at them.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, string>
     */
    public static function normalizedPhones(array $input): array
    {
        return collect(['phone', 'sos_phone'])
            ->filter(fn (string $key) => filled($input[$key] ?? null))
            ->mapWithKeys(fn (string $key) => [$key => self::normalizePhone((string) $input[$key])])
            ->all();
    }

    public static function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone);

        // A local 9-digit mobile number gets the Tajik country code.
        return '+'.(strlen($digits) === 9 ? '992'.$digits : $digits);
    }

    /* --------------------------------------------------------------- языки */

    /**
     * Each language once with a level against it, written as it was typed.
     *
     * @return array<string, mixed>
     */
    public static function languageRules(string $key = 'languages'): array
    {
        return [
            // Each language once, however it was spelled, so the list cannot
            // hold two levels for one.
            "{$key}.*.name" => ['required', 'string', 'max:100', 'distinct:ignore_case'],
            "{$key}.*.level" => ['required', Rule::in(UserLanguage::LEVELS)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function languageAttributes(string $key = 'languages'): array
    {
        return [
            "{$key}.*.name" => 'язык',
            "{$key}.*.level" => 'уровень',
        ];
    }

    /**
     * The languages on file, replaced by the list that was sent — the block is
     * edited whole, so it is saved whole.
     *
     * @param  array<array-key, array<string, mixed>>  $spoken
     */
    public static function saveLanguages(User $employee, array $spoken): void
    {
        $employee->languages()->delete();
        $employee->languages()->createMany(self::named($spoken, fn (array $l) => ['level' => $l['level']]));
    }

    /* ---------------------------------------------------------- гражданства */

    /**
     * The countries on file, replaced by the list that was sent, as the
     * languages are.
     *
     * @param  array<array-key, string>  $countries
     */
    public static function saveCitizenships(User $employee, array $countries): void
    {
        $employee->citizenships()->delete();
        $employee->citizenships()->createMany(self::named(array_map(fn (string $name) => ['name' => $name], $countries)));
    }

    /**
     * A list of named rows, trimmed, emptied of the blank ones and holding each
     * name once.
     *
     * Once however it was spelled: «Китайский» and «китайский» are one language
     * and two rows would be two answers to one question. The first spelling
     * wins, because that is the one the person typing is looking at.
     *
     * @param  array<array-key, array<string, mixed>>  $rows
     * @param  null|callable(array<string, mixed>): array<string, mixed>  $rest  What else each row carries.
     * @return list<array<string, mixed>>
     */
    private static function named(array $rows, ?callable $rest = null): array
    {
        return collect($rows)
            ->map(fn (array $row) => ['name' => trim((string) $row['name']), ...($rest === null ? [] : $rest($row))])
            ->filter(fn (array $row) => $row['name'] !== '')
            ->unique(fn (array $row) => mb_strtolower($row['name']))
            ->values()
            ->all();
    }

    /** One name as it is kept: trimmed, and nothing at all where nothing was said. */
    public static function name(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /* --------------------------------------------------------------- семья */

    /**
     * Marital status, the spouse, the children and the rest of the family. The
     * block is given out line by line, so a line the caller was not handed has
     * no rules here at all: that keeps it out of validated() and so out of the
     * save, and an empty spouse sent by somebody who may only set the marital
     * status cannot wipe what HR has on file.
     *
     * The three kinds of people the block names are asked the same three
     * things — what they are called, when they were born, where they work or
     * study — because «Состав семьи» is one list of people and the sheet reads
     * it as one. Only a relative is asked what they are to this person: a
     * spouse and a child are already what they are, and a row that said so
     * would be saying it twice.
     *
     * The lists of children and of relatives are wrapped by the caller — see
     * MAX_ROWS.
     *
     * @param  list<string>  $lines
     * @return array<string, mixed>
     */
    public static function familyRules(array $lines = self::FAMILY_LINES): array
    {
        $rules = [];

        if (in_array('marital_status', $lines, true)) {
            $rules['marital_status'] = ['nullable', Rule::in(['single', 'married'])];
        }

        if (in_array('spouse', $lines, true)) {
            // Asked for once the card says there is a spouse, and not before:
            // «женат» with nobody named is half an answer, and a card that
            // insisted on a name from everybody else would be asking the
            // unmarried to invent one. What they do for a living is not asked
            // for that way — a wife who keeps the house is an answer nobody
            // writes down, and the line stays empty where it is not known.
            $rules['spouse_name'] = ['nullable', 'required_if:marital_status,married', 'string', 'max:150'];
            $rules['spouse_birth_date'] = ['nullable', 'required_if:marital_status,married', ...self::monthOfBirth()];
            $rules['spouse_occupation'] = ['nullable', 'string', 'max:200'];
        }

        if (in_array('children', $lines, true)) {
            // Only false carries meaning here ("HR says there are none"); the true
            // case is derived from the rows, so the two cannot drift.
            $rules['has_children'] = ['nullable', 'boolean'];
            $rules['children.*.full_name'] = ['required', 'string', 'max:150'];
            $rules['children.*.birth_date'] = ['nullable', ...self::monthOfBirth()];
            // «3 класс, СОШ № 12» as readily as «Студент, ТТУ им. Осими»: the
            // line says where somebody spends their days, and a schoolchild
            // spends them somewhere too.
            $rules['children.*.occupation'] = ['nullable', 'string', 'max:200'];
        }

        if (in_array('relatives', $lines, true)) {
            // What they are to this person makes the row a row along with the
            // name, and it is the one question the spouse and the children are
            // not asked. The month and the work are asked for and not insisted
            // on, here as for the other two: a card is filled in from what
            // somebody remembers.
            $rules['relatives.*.relation'] = ['required', 'string', 'max:50'];
            $rules['relatives.*.full_name'] = ['required', 'string', 'max:150'];
            $rules['relatives.*.birth_date'] = ['nullable', ...self::monthOfBirth()];
            $rules['relatives.*.occupation'] = ['nullable', 'string', 'max:200'];
        }

        return $rules;
    }

    /**
     * When somebody of the family was born, as every line of the block now asks
     * it: a month and a year, written «1971-03», with no day at all.
     *
     * Said in one place because it is asked of the spouse, of every child and
     * of every relative, and three copies of a date format is two somebody will
     * not change. A personnel office is told the month a brother was born in
     * and is never told the day of it; a card that insisted on the day would be
     * given one somebody made up, which reads as having been checked and was
     * not. Carbon takes «1971-03» for the first of March, so the column goes on
     * being a date — there is none for a month — and keeps the first of it.
     *
     * The ceiling is this month rather than today, because the value is a
     * month: holding it against a day would need the day the answer does not
     * have.
     *
     * @return list<string>
     */
    private static function monthOfBirth(): array
    {
        return ['date_format:Y-m', 'before_or_equal:'.date('Y-m')];
    }

    /**
     * @return array<string, string>
     */
    public static function familyAttributes(): array
    {
        return [
            'marital_status' => 'семейное положение',
            'spouse_name' => 'ФИО супруга',
            'spouse_birth_date' => 'дата рождения супруга',
            'spouse_occupation' => 'работа или учёба супруга',
            'children.*.full_name' => 'ФИО ребёнка',
            'children.*.birth_date' => 'дата рождения ребёнка',
            'children.*.occupation' => 'работа или учёба ребёнка',
            'relatives.*.relation' => 'родство',
            'relatives.*.full_name' => 'ФИО родственника',
            'relatives.*.birth_date' => 'дата рождения родственника',
            'relatives.*.occupation' => 'работа или учёба родственника',
        ];
    }

    /**
     * What the refusals of this block are to say.
     *
     * The rules behind them are «required_if», whose own wording names the
     * field it depends on and the value it was compared with — «…когда
     * семейное положение равно married» — which is the inside of the form
     * read out to somebody filling it in.
     *
     * @return array<string, string>
     */
    public static function familyMessages(): array
    {
        return [
            'spouse_name.required_if' => 'Укажите ФИО супруга.',
            'spouse_birth_date.required_if' => 'Укажите дату рождения супруга.',
            'children.required_if' => 'Добавьте хотя бы одного ребёнка или ответьте «Нет».',
        ];
    }

    /**
     * The same sentence for the two other lists that are answered before they
     * are filled, said where each of them is asked.
     *
     * @return array<string, string>
     */
    public static function listMessages(): array
    {
        return [
            'educations.required_if' => 'Добавьте хотя бы одно учебное заведение или ответьте «Нет».',
            'experiences.required_if' => 'Добавьте хотя бы одно место работы или ответьте «Нет».',
            // And the three blocks that are lists as of November 2026, each
            // asked the same way: «есть» with nothing under it is the answer
            // the flag was added to do away with.
            'degrees.required_if' => 'Добавьте хотя бы одну степень или звание, или ответьте «Нет».',
            'awards.required_if' => 'Добавьте хотя бы одну награду или поощрение, или ответьте «Нет».',
            'military_records.required_if' => 'Добавьте хотя бы одну запись или ответьте «Нет».',
            // The languages are not asked «есть или нет» — one of them there has
            // to be — so the sentence says what to do rather than offering a way
            // out of it.
            'languages.min' => 'Добавьте хотя бы один язык.',
        ];
    }

    /**
     * What a list that is answered before it is filled comes to.
     *
     * «Не указано» and «нет» look the same in an empty list, so a flag keeps
     * them apart. Rows on file always mean «есть», whatever the flag was sent
     * as, so the two cannot drift; only «нет» has to be taken at its word,
     * because nothing else records it.
     *
     * The three lists asked this way — the children, the languages and the jobs
     * held before — are the same question three times, so they get one answer.
     *
     * @param  array<array-key, mixed>  $rows
     */
    public static function listFlag(mixed $said, array $rows): ?bool
    {
        return match (true) {
            $rows !== [] => true,
            $said === false => false,
            default => null,
        };
    }

    /**
     * The children on file, replaced by the list that was sent: both the card's
     * dialog and the create page edit them wholesale.
     *
     * The month arrives as «2015-05» and is written as the first of May, which
     * is what the column has held for every line of the block since it stopped
     * asking for the day — see monthOfBirth().
     *
     * @param  array<array-key, array<string, mixed>>  $children
     */
    public static function saveChildren(User $employee, array $children): void
    {
        $employee->children()->delete();
        $employee->children()->createMany(array_map(fn (array $child) => [
            'full_name' => $child['full_name'],
            'birth_date' => $child['birth_date'] ?? null,
            'occupation' => $child['occupation'] ?? null,
        ], $children));
    }

    /**
     * And the rest of the family, replaced by the list that was sent, for the
     * reason the children are: both the card's dialog and the create page edit
     * the list whole rather than a row at a time.
     *
     * @param  array<array-key, array<string, mixed>>  $relatives
     */
    public static function saveRelatives(User $employee, array $relatives): void
    {
        $employee->relatives()->delete();
        $employee->relatives()->createMany(array_map(fn (array $relative) => [
            'relation' => $relative['relation'],
            'full_name' => $relative['full_name'],
            'birth_date' => $relative['birth_date'] ?? null,
            'occupation' => $relative['occupation'] ?? null,
        ], $relatives));
    }

    /* ---------------------------------------------------------- образование */

    /**
     * One place of study, optionally under a prefix so the same rules cover a
     * list of them as well as a single record.
     *
     * @return array<string, mixed>
     */
    public static function educationRules(string $prefix = ''): array
    {
        // "gte:started_year" has to point at the sibling in the same record.
        $started = "{$prefix}started_year";

        return [
            "{$prefix}institution" => ['required', 'string', 'max:200'],
            "{$prefix}faculty" => ['required', 'string', 'max:150'],
            "{$prefix}specialty" => ['required', 'string', 'max:150'],
            // What the diploma made of them. Asked for like the speciality
            // beside it: a record of study that does not say what it led to
            // answers half the question. The column stays nullable because
            // the rows entered before it existed have nothing in it, and a
            // record is only made to name one when it is next written.
            "{$prefix}qualification" => ['required', 'string', 'max:150'],
            $started => ['required', 'integer', 'min:1950', 'max:'.date('Y')],
            // Empty while still studying; a diploma cannot predate enrolment.
            //
            // And required once a document is attached, which is the whole of
            // what ties the two together: a scan of a diploma is proof that
            // the studying ended, so a record cannot carry one and still say
            // it is going on. Said on the year rather than on the file because
            // the year is the answer that is missing.
            "{$prefix}graduated_year" => [
                'nullable',
                "required_with:{$prefix}document",
                'integer',
                "gte:{$started}",
                // This year at the latest, not ten ahead of it. The year used to
                // be a place to write down when somebody expected to finish; it
                // says that the studying ended now, which is what brings the
                // document out and what the document is proof of. Somebody who
                // has not finished leaves it empty, which is what empty means.
                'max:'.date('Y'),
            ],
            // The diploma itself, as a PDF: every document this application
            // keeps is one, so that a reader opens them all the same way and
            // nobody is handed a photograph of a diploma taken at an angle. Ten
            // megabytes is a scanned page with room to spare.
            //
            // What to call it is not asked for. It used to be a box of its own
            // beside this one, which had HR typing «Диплом бакалавра» next to a
            // file already called that; the name of the file is now edited in
            // the form before it is sent, so the one name that arrives is the
            // name the card shows.
            "{$prefix}document" => ['nullable', 'file', 'mimes:pdf', 'max:10240'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function educationAttributes(string $prefix = ''): array
    {
        return [
            "{$prefix}institution" => 'учебное заведение',
            "{$prefix}faculty" => 'факультет',
            "{$prefix}specialty" => 'специальность',
            "{$prefix}qualification" => 'квалификация',
            "{$prefix}started_year" => 'год поступления',
            "{$prefix}graduated_year" => 'год окончания',
            "{$prefix}document" => 'документ',
        ];
    }

    /**
     * Put a place of study on file, with the document it may carry.
     *
     * The file is the one part of a record that is not a column: it goes to the
     * private disk and the row keeps where it went. Which is why the three
     * places that save a record — the whole card of a new colleague, a record
     * added to a card, a record corrected — all come through here rather than
     * writing the columns themselves.
     *
     * What the document is called comes with it: the name the file arrived
     * under is the name the card shows, and that name is the one somebody typed
     * over it in the form before saving. It used to be a box of its own, which
     * had the same title typed twice on most records.
     *
     * @param  array<string, mixed>  $record  as validated, the file included
     * @return array<string, mixed> the columns to write
     */
    public static function educationRow(User $employee, array $record): array
    {
        $file = $record['document'] ?? null;
        unset($record['document']);

        if ($file instanceof UploadedFile) {
            $record['document_path'] = DocumentStore::keep($file, $employee->id);
            $record['document_name'] = $file->getClientOriginalName();
        }

        return $record;
    }

    /**
     * Several of them, for a list filed in one go.
     *
     * @param  list<array<string, mixed>>  $records
     */
    public static function saveEducations(User $employee, array $records): void
    {
        foreach ($records as $record) {
            $employee->educations()->create(self::educationRow($employee, $record));
        }
    }

    /* ------ учёная степень, награды и отношение к воинской обязанности */

    /**
     * One record of a list that is a line of text and the one paper behind it.
     *
     * Three blocks of the card are kept on exactly these terms: «Учёная
     * степень, звание», «Государственные награды, поощрения» and «Отношение к
     * воинской обязанности». Each of them was one line with one document until
     * November 2026, and each of them was being asked to hold more than one
     * answer — a degree and a title, two medals and a letter of thanks, the
     * standing and the rank in the reserve. Crammed into a single line they
     * came out as one string with one scan to prove all of it.
     *
     * So they are lists now, answered «есть или нет» first like the places of
     * study, and this is one record of any of the three: nothing tells them
     * apart but what the block is called, which is why the rules are written
     * once. The prefix is the list the record stands in — «degrees.*.» — or
     * nothing at all, for the doors that take one record on its own.
     *
     * The line is insisted on, which is the one thing a list changes about the
     * question: an empty line used to mean «nobody has asked», and the flag
     * beside the list says that now. A record that names nothing is a record
     * somebody opened and did not fill in.
     *
     * One paper, and a PDF: every document this application keeps is one, so
     * that a reader opens them all the same way and nobody is handed a
     * photograph of a diploma taken at an angle. Ten megabytes is a scanned
     * page with room to spare. No name is asked for the paper — the file
     * arrives under the name it is to be kept under, which is the name somebody
     * typed over it in the form before saving.
     *
     * @return array<string, mixed>
     */
    public static function paperRules(string $prefix = ''): array
    {
        return [
            "{$prefix}name" => ['required', 'string', 'max:200'],
            "{$prefix}document" => ['nullable', 'file', 'mimes:pdf', 'max:10240'],
        ];
    }

    /**
     * What the refusals call a record of one of the three lists.
     *
     * The line is named by the block it stands in — a refusal reading «Поле
     * название обязательно» says nothing about which part of the sheet was
     * being filled in — and the paper is «документ» in all three, because that
     * is what it is called on the page.
     *
     * @param  string  $named  what one record of this list is, in the sheet's own words
     * @return array<string, string>
     */
    public static function paperAttributes(string $prefix = '', string $named = 'название'): array
    {
        return [
            "{$prefix}name" => $named,
            "{$prefix}document" => 'документ',
        ];
    }

    /**
     * One such record as the columns to write, with the paper put away.
     *
     * The file is the one part of a record that is not a column: it goes to the
     * private disk and the row keeps where it went and what it is called. A
     * record that brings no file leaves whatever is on file alone — not every
     * save is about the paper, and absent is not «take it away», here as
     * everywhere else on this card.
     *
     * The name is the file's own. It used to be asked for in a box of its own
     * on the places of study, which had HR typing «Диплом бакалавра» beside a
     * file already called that; the form now lets the name of the file be
     * edited before it is sent, so the one name that arrives is the one name
     * the card shows.
     *
     * @param  array<string, mixed>  $record  as validated, the file included
     * @return array<string, mixed> the columns to write
     */
    public static function paperRow(User $employee, array $record): array
    {
        $file = $record['document'] ?? null;
        unset($record['document']);

        if ($file instanceof UploadedFile) {
            $record['document_path'] = DocumentStore::keep($file, $employee->id);
            $record['document_name'] = $file->getClientOriginalName();
        }

        return $record;
    }

    /**
     * A list of them filed in one go, as «Новый сотрудник» files one.
     *
     * The relation is handed over rather than named, because the three lists
     * are three relations of the same shape and the caller is the one that
     * knows which of them it is filling.
     *
     * @param  HasMany<UserPaperRecord, User>  $records
     * @param  list<array<string, mixed>>  $rows
     */
    public static function savePapers(User $employee, HasMany $records, array $rows): void
    {
        foreach ($rows as $row) {
            $records->create(self::paperRow($employee, $row));
        }
    }

    /* ------------------------------------------------- трудовая деятельность */

    /**
     * One previous job, optionally under a prefix, as the degrees above.
     *
     * @return array<string, mixed>
     */
    public static function experienceRules(string $prefix = ''): array
    {
        return [
            "{$prefix}organization" => ['required', 'string', 'max:200'],
            "{$prefix}position" => ['required', 'string', 'max:150'],
            "{$prefix}started_month" => ['required', 'integer', 'between:1,12'],
            "{$prefix}started_year" => ['required', 'integer', 'min:1950', 'max:'.date('Y')],
            // Both empty while the person still works there; one without the
            // other would be half a date.
            "{$prefix}ended_month" => ['nullable', "required_with:{$prefix}ended_year", 'integer', 'between:1,12'],
            "{$prefix}ended_year" => ['nullable', "required_with:{$prefix}ended_month", 'integer', 'min:1950', 'max:'.date('Y')],
            // And a job that ended says why. Nobody still working somewhere has
            // an answer to give, so it is asked for with the leaving date and
            // not before it.
            "{$prefix}reason" => ['nullable', "required_with:{$prefix}ended_year", 'string', 'max:200'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function experienceAttributes(string $prefix = ''): array
    {
        return [
            "{$prefix}organization" => 'организация',
            "{$prefix}position" => 'должность',
            "{$prefix}started_month" => 'месяц вступления',
            "{$prefix}started_year" => 'год вступления',
            "{$prefix}ended_month" => 'месяц ухода',
            "{$prefix}ended_year" => 'год ухода',
            "{$prefix}reason" => 'причина ухода',
        ];
    }

    /**
     * Neither date in the future, the leaving date after the joining one, and a
     * reason only where there is a leaving to give one for. Rules cannot say
     * any of this on their own: the two halves of a date are separate fields,
     * so they are compared here.
     *
     * Called from inside the validator's own `after`, which is where both callers
     * register it — one record at a time, so whatever it finds is said on the row
     * it happened in.
     *
     * @param  array<string, mixed>  $record
     */
    public static function checkExperienceDates(Validator $validator, array $record, string $prefix = ''): void
    {
        // Months counted from year 0, so the two dates compare as plain numbers.
        $month = fn ($year, $m) => is_numeric($year) && is_numeric($m) ? (int) $year * 12 + (int) $m : null;
        $now = (int) date('Y') * 12 + (int) date('n');

        $start = $month($record['started_year'] ?? null, $record['started_month'] ?? null);
        $end = $month($record['ended_year'] ?? null, $record['ended_month'] ?? null);

        if ($start !== null && $start > $now) {
            $validator->errors()->add("{$prefix}started_year", 'Дата вступления не может быть в будущем.');
        }
        if ($end !== null && $end > $now) {
            $validator->errors()->add("{$prefix}ended_year", 'Дата ухода не может быть в будущем.');
        }
        if ($start !== null && $end !== null && $end < $start) {
            $validator->errors()->add("{$prefix}ended_year", 'Дата ухода раньше даты вступления.');
        }

        // The rules above ask for a reason with a leaving date; this is the same
        // tie read the other way round, which they cannot say. Somebody who
        // still works there has not left for any reason, and a record that said
        // so would be two answers that contradict each other.
        if ($end === null && trim((string) ($record['reason'] ?? '')) !== '') {
            $validator->errors()->add("{$prefix}reason", 'Причина ухода — только у места работы, которое человек покинул.');
        }
    }

    /* --------------------------------------------------------- оборудование */

    /**
     * The units handed over in one go, and the day they were. Both callers name
     * the fields the same way, so there is no prefix to pass.
     *
     * @return array<string, mixed>
     */
    public static function equipmentRules(): array
    {
        return [
            'equipment.*' => ['integer', 'distinct', Rule::exists('equipment', 'id')],
            'issued_at' => ['nullable', 'date', 'before_or_equal:today'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function equipmentAttributes(): array
    {
        return [
            'equipment' => 'оборудование',
            'issued_at' => 'дата выдачи',
        ];
    }

    /**
     * Hand the units over, putting the workplace together in one go.
     *
     * @param  array<array-key, int|string>  $ids
     */
    public static function issueEquipment(User $employee, array $ids, ?string $issuedAt = null): void
    {
        $day = $issuedAt ?? Carbon::today()->toDateString();

        Equipment::query()->whereIn('id', $ids)->get()->each(function (Equipment $unit) use ($employee, $day) {
            // Only what is free may be handed over; anything else is left alone
            // rather than quietly taken from whoever has it.
            if ($unit->status !== 'stock') {
                return;
            }

            $unit->update([
                'status' => 'issued',
                'holder_user_id' => $employee->id,
                'issued_at' => $day,
            ]);
        });
    }
}
