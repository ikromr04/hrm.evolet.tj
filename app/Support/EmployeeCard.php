<?php

namespace App\Support;

use App\Models\Equipment;
use App\Models\Language;
use App\Models\User;
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
    public const FAMILY_LINES = ['marital_status', 'spouse', 'children'];

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
            'sos_phone' => 'телефон SOS',
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
            'sos_phone.regex' => 'Телефон SOS указан неверно.',
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
     * Each language once with a level against it, named where the directory has
     * not heard of it yet.
     *
     * @return array<string, mixed>
     */
    public static function languageRules(string $key = 'languages'): array
    {
        return [
            // Each language once, so the list cannot hold two levels for one.
            "{$key}.*.id" => ['nullable', "required_without:{$key}.*.name", 'integer', 'distinct', Rule::exists('languages', 'id')],
            // A language missing from the list is named instead, and added to it
            // on the save: whoever may fill this block in may also add the language.
            "{$key}.*.name" => ['nullable', "required_without:{$key}.*.id", 'string', 'max:100'],
            "{$key}.*.level" => ['required', Rule::in(Language::LEVELS)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function languageAttributes(string $key = 'languages'): array
    {
        return [
            "{$key}.*.id" => 'язык',
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
        // Matched without regard to case, so "китайский" finds "Китайский"
        // rather than putting a second one on the list.
        $known = Language::query()->get(['id', 'name'])->keyBy(fn (Language $l) => mb_strtolower($l->name));

        $levels = collect($spoken)->mapWithKeys(function (array $l) use ($known) {
            $id = $l['id'] ?? null;

            if ($id === null) {
                $name = trim($l['name']);
                $id = ($known[mb_strtolower($name)] ?? Language::create(['name' => $name]))->id;
            }

            return [$id => ['level' => $l['level']]];
        });

        $employee->languages()->sync($levels);
    }

    /* --------------------------------------------------------------- семья */

    /**
     * Marital status, the spouse and the children. The block is given out line
     * by line, so a line the caller was not handed has no rules here at all:
     * that keeps it out of validated() and so out of the save, and an empty
     * spouse sent by somebody who may only set the marital status cannot wipe
     * what HR has on file.
     *
     * The list of children is wrapped by the caller — see MAX_ROWS.
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
            $rules['spouse_name'] = ['nullable', 'string', 'max:150'];
            $rules['spouse_birth_date'] = ['nullable', 'date', 'before_or_equal:today'];
        }

        if (in_array('children', $lines, true)) {
            // Only false carries meaning here ("HR says there are none"); the true
            // case is derived from the rows, so the two cannot drift.
            $rules['has_children'] = ['nullable', 'boolean'];
            $rules['children.*.full_name'] = ['required', 'string', 'max:150'];
            $rules['children.*.birth_date'] = ['nullable', 'date', 'before_or_equal:today'];
        }

        return $rules;
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
            'children.*.full_name' => 'ФИО ребёнка',
            'children.*.birth_date' => 'дата рождения ребёнка',
        ];
    }

    /**
     * What the «Дети» line comes to. «Не указано» and «детей нет» look the same
     * in an empty list, so the flag keeps them apart; rows on file always mean
     * there are children, whatever the flag was set to.
     *
     * @param  array<array-key, mixed>  $children
     */
    public static function childrenFlag(mixed $said, array $children): ?bool
    {
        return match (true) {
            $children !== [] => true,
            $said === false => false,
            default => null,
        };
    }

    /**
     * The children on file, replaced by the list that was sent: both the card's
     * dialog and the create page edit them wholesale.
     *
     * @param  array<array-key, array<string, mixed>>  $children
     */
    public static function saveChildren(User $employee, array $children): void
    {
        $employee->children()->delete();
        $employee->children()->createMany(array_map(
            fn (array $child) => ['full_name' => $child['full_name'], 'birth_date' => $child['birth_date'] ?? null],
            $children,
        ));
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
            // The diploma itself. Ten megabytes is a scanned page with room to
            // spare; the formats are the three a scanner or a telephone gives.
            "{$prefix}document" => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
            // And what to call it. Asked for rather than read off the file,
            // because «scan_0007.pdf» is what a scanner called it and the card
            // shows this name as the thing one presses to open the document.
            "{$prefix}document_name" => ['nullable', "required_with:{$prefix}document", 'string', 'max:255'],
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
            "{$prefix}document_name" => 'название документа',
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
     * What the document is called is not here: it is a column, typed like any
     * other, and goes in with the rest of the record.
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
            "{$prefix}country" => ['required', 'string', 'max:100'],
            "{$prefix}started_month" => ['required', 'integer', 'between:1,12'],
            "{$prefix}started_year" => ['required', 'integer', 'min:1950', 'max:'.date('Y')],
            // Both empty while the person still works there; one without the
            // other would be half a date.
            "{$prefix}ended_month" => ['nullable', "required_with:{$prefix}ended_year", 'integer', 'between:1,12'],
            "{$prefix}ended_year" => ['nullable', "required_with:{$prefix}ended_month", 'integer', 'min:1950', 'max:'.date('Y')],
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
            "{$prefix}country" => 'страна',
            "{$prefix}started_month" => 'месяц вступления',
            "{$prefix}started_year" => 'год вступления',
            "{$prefix}ended_month" => 'месяц ухода',
            "{$prefix}ended_year" => 'год ухода',
        ];
    }

    /**
     * Neither date in the future, and the leaving date after the joining one.
     * Rules cannot say this on their own: the two halves of a date are separate
     * fields, so they are compared here.
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
