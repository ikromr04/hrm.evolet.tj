<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The sheet somebody is asking for, line by line beside the sheet on file.
 *
 * «Руководитель» and «Специалист» are shown their own card and may not write to
 * it. They retype «Личный листок по учёту кадров» and send it instead, and the
 * personnel office opens the request and writes it onto the card or turns it
 * down. This is what the office reads while deciding.
 *
 * The whole sheet, and not only what moved. Accepting a request writes every
 * line of the payload — see App\Support\SheetWriter — so the office is
 * answering for all of it, and a page showing the three lines that changed
 * would be asking somebody to accept twenty-five sight unseen. The lines that
 * differ are marked, which is a different thing: it says where to look, not
 * what is being agreed to.
 *
 * In the order the paper form asks them, under the names it asks them by, which
 * is why the order comes from PersonnelSheet::LINES rather than from any list
 * of its own: the office reads this beside the sheet itself, and a page that
 * named the military standing before the schooling would send somebody up the
 * paper to find a part that is further down it. The three parts that sheet draws
 * and does not count — the languages, the spouse, the rest of the family — stand
 * here where the card's own sheet stands them, because a line left out of this
 * reading is a line that would be written on acceptance with nobody having seen
 * it.
 *
 * Both columns are written by one hand. The card is first turned into the shape
 * a request arrives in, and then one set of readers writes both sides of every
 * line — so «Было» and «Стало» cannot come to be worded differently, which is
 * the one thing that would make a line nobody touched look as though it had.
 *
 * Nothing here asks who is reading, and that is not an oversight: the request is
 * read by the office, which may read the whole card, and the payload was already
 * cut to the lines its sender may change on the way in. A line the request does
 * not carry is read off the card on both sides and marked as unchanged, because
 * that is exactly what the save will do with it.
 */
final class SheetDiff
{
    /** What an unanswered line reads as, here as everywhere the card is read. */
    private const NOTHING = '—';

    /**
     * The lines of the sheet that are one value apiece, and the fields each of
     * them arrives in.
     *
     * The same thing App\Http\Requests\UpdateSheetRequest::LINES says, said
     * again because it says it for another purpose: there the map decides which
     * rules a viewer gets, here it decides whether this request carries the line
     * at all. A line none of whose fields arrived was never this sender's to
     * change, and «now» is then the card's own answer rather than an empty one —
     * a sheet that read «—» against a passport nobody was shown would be
     * offering the office a blank to accept.
     *
     * The lists are not here: each of them is carried by the name of the list
     * itself, which is what asked() goes by for the same reason SheetWriter
     * does — a flag with no rows beside it is not an answer about them.
     *
     * @var array<string, list<string>>
     */
    private const FIELDS = [
        'surname' => ['surname'],
        'name' => ['name'],
        'patronymic' => ['patronymic'],
        'sex' => ['sex'],
        'birth_date' => ['birth_date'],
        'birth_place' => ['birth_place'],
        'nationality' => ['nationality'],
        'citizenship' => ['citizenship'],
        'educations' => ['educations'],
        'degree' => ['degrees'],
        'languages' => ['languages'],
        'work_experiences' => ['experiences'],
        'award' => ['awards'],
        'marital_status' => ['marital_status'],
        'spouse' => ['spouse_name', 'spouse_birth_date', 'spouse_occupation'],
        'children' => ['children'],
        'relatives' => ['relatives'],
        // The series and the number are one line of the sheet, as they are one
        // line of a passport.
        'passport_number' => ['passport_series', 'passport_number'],
        'passport_issued_at' => ['passport_issued_at'],
        'passport_issued_by' => ['passport_issued_by'],
        'tax_number' => ['tax_number'],
        'home_address' => ['home_address'],
        'phone' => ['phone'],
        // A number and whose it is: half of that answer is no use to anybody.
        'sos_phone' => ['sos_phone', 'sos_contact'],
        'military' => ['military_records'],
    ];

    /**
     * The three parts of the sheet PersonnelSheet::LINES leaves a gap for, and
     * the line each of them follows.
     *
     * They are not counted towards a card being filled in — there is no number
     * of languages or of relatives that is right — but they are asked for on
     * the paper and they are written on acceptance, so they are read here. Each
     * stands where the card's own sheet stands it: the languages between the
     * degrees and the work, the spouse under «Семейное положение», the rest of
     * the family under the children.
     *
     * @var array<string, array<string, string>>
     */
    private const BESIDE = [
        'degree' => ['languages' => 'Знание языков'],
        'marital_status' => ['spouse' => ''],
        'children' => ['relatives' => 'Состав семьи'],
    ];

    /**
     * The six lists answered «есть или нет» before they are filled, and the
     * flag that answers for each.
     *
     * The very map SheetWriter walks, and walked here for the same reason: «нет»
     * and «nobody has asked» look the same in an empty list, so what the office
     * is shown has to be worked out exactly as the save works it out.
     *
     * @var array<string, string>
     */
    private const FLAGGED = [
        'educations' => 'has_educations',
        'degrees' => 'has_degrees',
        'experiences' => 'has_experience',
        'awards' => 'has_awards',
        'children' => 'has_children',
        'military_records' => 'has_military_records',
    ];

    /** The four lists that keep a paper, and the relation each is kept in. */
    private const PAPERED = [
        'educations' => 'educations',
        'degrees' => 'degrees',
        'awards' => 'awards',
        'military_records' => 'militaryRecords',
    ];

    private const SEXES = ['male' => 'Мужской', 'female' => 'Женский'];

    /**
     * Said of the person it is about: «Не женат» and «Не замужем» are the same
     * answer in two words. Where the card does not say which, the masculine
     * form stands — Russian has no third one to fall back on, and the card's own
     * sheet settles it the same way.
     *
     * @var array<string, array<string, string>>
     */
    private const MARITAL = [
        'male' => ['single' => 'Не женат', 'married' => 'Женат'],
        'female' => ['single' => 'Не замужем', 'married' => 'Замужем'],
    ];

    private const LEVELS = ['beginner' => 'Начальный', 'intermediate' => 'Средний', 'advanced' => 'Продвинутый'];

    /**
     * Every month cut to three letters and a full stop, as the card writes them
     * wherever a date is read — see formatDate() in resources/js/lib/employee.ts,
     * which this is the other half of. A sheet whose «Было» read «05 ноя. 2026»
     * and whose «Стало» read «2026-11-05» would be one date written two ways,
     * and the office would be left working out whether that is the change.
     *
     * @var list<string>
     */
    private const MONTHS = ['янв.', 'фев.', 'мар.', 'апр.', 'мая.', 'июн.', 'июл.', 'авг.', 'сен.', 'окт.', 'ноя.', 'дек.'];

    /**
     * Every line of the sheet: what the card says, what the request asks for,
     * and whether the two differ.
     *
     * «changed» is a comparison of answers and not of wording. Each reader
     * hands back both the line as it reads and the answer behind it, and it is
     * the answers that are held against each other: a telephone kept as
     * «992901243299» on an old card and sent as «+992901243299» is the same
     * number, and a place of birth with a space on the end of it is the same
     * place. A line marked for nothing is worse than it sounds — the office
     * reads the marks to find what to check, and three false ones teach it to
     * stop looking.
     *
     * @param  array<string, mixed>  $payload  a sheet as UpdateSheetRequest validated it,
     *                                         with every uploaded paper already stored:
     *                                         a record carries document_path/document_name,
     *                                         never an UploadedFile
     * @return list<array{key: string, title: string, was: string, now: string, changed: bool}>
     */
    public static function between(User $employee, array $payload): array
    {
        $card = self::onCard($employee);
        $asked = self::asked($employee, $payload);

        $rows = [];

        foreach (self::lines($card['sex']) as $key => $title) {
            $was = self::reading($key, $card);
            $now = self::carried($key, $asked) ? self::reading($key, $asked) : $was;

            $rows[] = [
                'key' => $key,
                'title' => $title,
                'was' => $was['read'],
                'now' => $now['read'],
                'changed' => $was['answer'] !== $now['answer'],
            ];
        }

        return $rows;
    }

    /**
     * The lines to read, in the sheet's order and under the sheet's names.
     *
     * @param  string|null  $sex  whose sheet it is, which one line's name turns on
     * @return array<string, string>
     */
    private static function lines(?string $sex): array
    {
        $lines = [];

        foreach (PersonnelSheet::LINES as $key => $title) {
            $lines[$key] = $title;

            foreach (self::BESIDE[$key] ?? [] as $gap => $named) {
                // Said of the spouse and not of the person whose sheet this is,
                // so the word is the other one of the pair: a man's spouse is
                // «Супруга». Named off the card rather than off the request —
                // the heading stands over both columns, and the card is what
                // «Было» is.
                $lines[$gap] = $gap === 'spouse' ? (($sex ?? 'male') === 'female' ? 'Супруг' : 'Супруга') : $named;
            }
        }

        return $lines;
    }

    /**
     * The card in the shape a request arrives in.
     *
     * Turned around this way, rather than each line being read twice over, so
     * that one set of readers writes both columns: two readings of «Трудовая
     * деятельность» would be one of them somebody corrects and one they do not,
     * and the half that was not corrected is the half the office is comparing
     * against.
     *
     * The relations are read off the model where they are already loaded and
     * asked for separately where they are not, which is what PersonnelSheet
     * does and for the reason it gives: on their own card this employee is the
     * signed-in account itself, and that account is handed to every page with
     * whatever hangs off it — so attaching the private rows here to read them
     * would send the passport, the tax number and the family to the browser.
     * Reading a line is not the same as being allowed to read it.
     *
     * @return array<string, mixed>
     */
    private static function onCard(User $employee): array
    {
        $details = $employee->relationLoaded('details') ? $employee->details : $employee->details()->first();

        $lists = [
            'educations' => self::rowsOf($employee, 'educations'),
            'degrees' => self::rowsOf($employee, 'degrees'),
            'experiences' => self::rowsOf($employee, 'workExperiences'),
            'awards' => self::rowsOf($employee, 'awards'),
            'children' => self::rowsOf($employee, 'children'),
            'military_records' => self::rowsOf($employee, 'militaryRecords'),
        ];

        $flags = [];

        // Answered by the very call the save answers it by: rows on file mean
        // «есть» whatever the flag says, so a card whose flag and rows ever drew
        // apart still reads as one answer — and reads as the same answer the
        // request beside it is read as.
        foreach (self::FLAGGED as $list => $flag) {
            $flags[$flag] = EmployeeCard::listFlag($details?->{$flag}, $lists[$list]);
        }

        return [
            ...$lists,
            ...$flags,
            'surname' => $employee->surname,
            'name' => $employee->name,
            'patronymic' => $employee->patronymic,
            'sex' => $employee->sex,
            'birth_date' => $details?->birth_date,
            'birth_place' => $details?->birth_place,
            'nationality' => $details?->nationality,
            'citizenship' => Arr::pluck(self::rowsOf($employee, 'citizenships'), 'name'),
            'languages' => self::rowsOf($employee, 'languages'),
            'relatives' => self::rowsOf($employee, 'relatives'),
            'marital_status' => $details?->marital_status,
            'spouse_name' => $details?->spouse_name,
            'spouse_birth_date' => $details?->spouse_birth_date,
            'spouse_occupation' => $details?->spouse_occupation,
            'passport_series' => $details?->passport_series,
            'passport_number' => $details?->passport_number,
            'passport_issued_at' => $details?->passport_issued_at,
            'passport_issued_by' => $details?->passport_issued_by,
            'tax_number' => $details?->tax_number,
            'home_address' => $details?->home_address,
            'phone' => $details?->phone,
            'sos_phone' => $details?->sos_phone,
            'sos_contact' => $details?->sos_contact,
        ];
    }

    /**
     * The request in the same shape, holding what the save would write and
     * nothing else.
     *
     * A list is taken as carried by the name of the list and not by the flag in
     * front of it, which is how SheetWriter reads it too: a flag that arrived
     * without its rows is not an answer about them, and reading it as one would
     * promise the office a «Нет» that the save then does not write.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function asked(User $employee, array $payload): array
    {
        $asked = Arr::only($payload, [
            'surname', 'name', 'patronymic', 'sex',
            'birth_date', 'birth_place', 'nationality', 'citizenship',
            'languages', 'relatives',
            'marital_status', 'spouse_name', 'spouse_birth_date', 'spouse_occupation',
            'passport_series', 'passport_number', 'passport_issued_at', 'passport_issued_by', 'tax_number',
            'home_address', 'phone', 'sos_phone', 'sos_contact',
        ]);

        foreach (self::FLAGGED as $list => $flag) {
            if (! array_key_exists($list, $payload)) {
                continue;
            }

            $rows = (array) $payload[$list];

            $asked[$list] = isset(self::PAPERED[$list]) ? self::papered($employee, $list, $rows) : $rows;
            $asked[$flag] = EmployeeCard::listFlag($payload[$flag] ?? null, $rows);
        }

        return $asked;
    }

    /**
     * The records of a list that keeps a paper, each with the paper it would be
     * left holding.
     *
     * A request brings no file for a document it is not changing — no file has
     * always meant «do not touch the file» on this card — so the paper of a
     * record already on file has to be looked up, and it is looked up by the id
     * the record came back as. Read any other way, a sheet nobody changed would
     * show every diploma on it coming off, and a list that gained a row in the
     * middle would read as every row below having changed hands.
     *
     * The three answers in the order SheetWriter::papered() settles them: a file
     * that came beats everything, because somebody who attached one plainly
     * wants it; then the asking for the old one to go, which this page is where
     * anybody will see; and otherwise whatever the card holds for that record.
     *
     * @param  string  $list  what the request calls it: «educations», «degrees», «awards», «military_records»
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private static function papered(User $employee, string $list, array $rows): array
    {
        $onFile = Arr::pluck(self::rowsOf($employee, self::PAPERED[$list]), 'document_name', 'id');

        return array_map(fn (array $row) => [...$row, 'document_name' => match (true) {
            ($row['document_path'] ?? null) !== null => $row['document_name'] ?? null,
            (bool) ($row['document_remove'] ?? false) => null,
            is_numeric($row['id'] ?? null) => $onFile[(int) $row['id']] ?? null,
            default => null,
        }], array_values($rows));
    }

    /**
     * Whether this request carries the line at all.
     *
     * @param  array<string, mixed>  $asked
     */
    private static function carried(string $line, array $asked): bool
    {
        foreach (self::FIELDS[$line] as $field) {
            if (array_key_exists($field, $asked)) {
                return true;
            }
        }

        return false;
    }

    /**
     * One line of the sheet as it reads, and the answer the reading is of.
     *
     * The answer is what «changed» is decided on, and it is kept apart from the
     * reading because the two are not the same question. A list is the plainest
     * case: the card keeps its languages in alphabetical order and its jobs by
     * the year they started, so the order a request happens to hold them in is
     * no answer of anybody's — which is why a list is compared as the records it
     * holds and not as the column it prints. The reading stays each side's own,
     * so «Было» goes on reading the way the card reads.
     *
     * No arm for a line this class has not been taught to read, deliberately: a
     * part added to the sheet would then be shown to the office as a line
     * nobody has answered, which is a thing it may well accept — and what it
     * would be accepting is whatever that part actually carries.
     *
     * @param  array<string, mixed>  $sheet
     * @return array{read: string, answer: mixed}
     */
    private static function reading(string $line, array $sheet): array
    {
        return match ($line) {
            'surname', 'name', 'patronymic', 'birth_place', 'nationality',
            'passport_issued_by', 'tax_number', 'home_address' => self::words($sheet[$line] ?? null),
            'sex' => self::oneOf($sheet['sex'] ?? null, self::SEXES),
            'marital_status' => self::oneOf(
                $sheet['marital_status'] ?? null,
                self::MARITAL[($sheet['sex'] ?? null) === 'female' ? 'female' : 'male'],
            ),
            'birth_date', 'passport_issued_at' => self::day($sheet[$line] ?? null),
            'citizenship' => self::countries($sheet['citizenship'] ?? []),
            // The series and the number read as one, the way they are printed
            // in the passport itself, and are compared as the two answers they
            // were typed as.
            'passport_number' => self::said(
                implode(' ', self::tidied([$sheet['passport_series'] ?? null, $sheet['passport_number'] ?? null])),
                [self::tidy($sheet['passport_series'] ?? null), self::tidy($sheet['passport_number'] ?? null)],
            ),
            'phone' => self::phone($sheet['phone'] ?? null, null),
            // Whose number it is goes on the line with the number, the way the
            // card keeps the two together.
            'sos_phone' => self::phone($sheet['sos_phone'] ?? null, $sheet['sos_contact'] ?? null),
            // One person at most, read as one of the family rather than as
            // three lines of the details row — and read as nobody where there
            // is no name, which is how the card reads the same three columns.
            'spouse' => self::records(
                self::tidy($sheet['spouse_name'] ?? null) === '' ? [] : [[
                    'full_name' => $sheet['spouse_name'] ?? null,
                    'birth_date' => $sheet['spouse_birth_date'] ?? null,
                    'occupation' => $sheet['spouse_occupation'] ?? null,
                ]],
                null,
                self::person(...),
            ),
            'educations' => self::records($sheet['educations'] ?? [], $sheet['has_educations'] ?? null, self::education(...)),
            'degree' => self::records($sheet['degrees'] ?? [], $sheet['has_degrees'] ?? null, self::paper(...)),
            'languages' => self::records($sheet['languages'] ?? [], null, self::language(...)),
            'work_experiences' => self::records($sheet['experiences'] ?? [], $sheet['has_experience'] ?? null, self::job(...)),
            'award' => self::records($sheet['awards'] ?? [], $sheet['has_awards'] ?? null, self::paper(...)),
            'children' => self::records($sheet['children'] ?? [], $sheet['has_children'] ?? null, self::person(...)),
            'relatives' => self::records($sheet['relatives'] ?? [], null, self::relative(...)),
            'military' => self::records($sheet['military_records'] ?? [], $sheet['has_military_records'] ?? null, self::paper(...)),
        };
    }

    /**
     * A line that is a list of records: one record per line of text, as the
     * sheet prints them.
     *
     * Empty, it is the one place where «Нет» and «nobody has asked» have to be
     * told apart, and the flag in front of the list is what tells them: six
     * parts of the sheet are answered before they are filled in, and «Нет» is
     * an answer the office may be accepting. The lists with no flag — the
     * languages, the family besides the children — have only the dash.
     *
     * The records are compared as what they say and not as the order they came
     * in: see reading() above. A record says everything it holds in the line it
     * prints, both columns being printed by this same call, so two records that
     * read alike are two records that say the same thing.
     *
     * @param  array<array-key, mixed>  $rows
     * @param  callable(array<string, mixed>): string  $read
     * @return array{read: string, answer: mixed}
     */
    private static function records(array $rows, ?bool $flag, callable $read): array
    {
        $printed = array_values(array_filter(array_map(
            fn (mixed $row) => self::tidy($read(is_array($row) ? $row : [])),
            $rows,
        ), fn (string $line) => $line !== ''));

        return [
            'read' => $printed === [] ? ($flag === false ? 'Нет' : self::NOTHING) : implode("\n", $printed),
            'answer' => [$flag, self::sorted($printed)],
        ];
    }

    /**
     * One record of the three parts that are a line of text and one paper
     * apiece — a degree, an award, a standing with the army — named by what it
     * says and by the paper behind it: «Кандидат наук — diploma.pdf».
     *
     * @param  array<string, mixed>  $row
     */
    private static function paper(array $row): string
    {
        return self::clipped(self::tidy($row['name'] ?? null), $row);
    }

    /**
     * One place of study: where and in what faculty, what it made of them, the
     * years it took, and the diploma.
     *
     * The place and the faculty read as one address and the subject and the
     * qualification as one phrase, the way the card's own sheet rules them into
     * columns — a faculty on its own answers nothing. The year of leaving is
     * empty while somebody is still studying, which is what «н. в.» says.
     *
     * @param  array<string, mixed>  $row
     */
    private static function education(array $row): string
    {
        $years = self::tidy($row['started_year'] ?? null);

        return self::clipped(self::joined([
            implode(', ', self::tidied([$row['institution'] ?? null, $row['faculty'] ?? null])),
            implode(', ', self::tidied([$row['specialty'] ?? null, $row['qualification'] ?? null])),
            $years === '' ? '' : $years.'–'.(self::tidy($row['graduated_year'] ?? null) ?: 'н. в.'),
        ]), $row);
    }

    /**
     * One job held before: when it was held, where, as what, and why it ended.
     *
     * The two ends of the period in figures and not by the names of the months,
     * because they are read against each other rather than as dates of their
     * own — the card writes a period that way and a date of birth the other.
     *
     * @param  array<string, mixed>  $row
     */
    private static function job(array $row): string
    {
        $from = self::period($row['started_year'] ?? null, $row['started_month'] ?? null);
        $to = self::period($row['ended_year'] ?? null, $row['ended_month'] ?? null);

        return self::joined([
            $from === '' ? '' : $from.' — '.($to ?: 'н. в.'),
            self::tidy($row['organization'] ?? null),
            self::tidy($row['position'] ?? null),
            self::tidy($row['reason'] ?? null),
        ]);
    }

    /**
     * One of the family the card names, asked the three things all of them are
     * asked: what they are called, the month they were born in, where they
     * spend their days. The spouse and the children read this way.
     *
     * @param  array<string, mixed>  $row
     */
    private static function person(array $row): string
    {
        return self::joined([
            self::tidy($row['full_name'] ?? null),
            self::month($row['birth_date'] ?? null),
            self::tidy($row['occupation'] ?? null),
        ]);
    }

    /**
     * The rest of the family, which is asked one thing more: what they are to
     * this person. A spouse and a child are already what they are.
     *
     * @param  array<string, mixed>  $row
     */
    private static function relative(array $row): string
    {
        return self::joined([self::tidy($row['relation'] ?? null), self::person($row)]);
    }

    /**
     * A language and how well it is spoken, in the words the card's own «Знание
     * языков» gives the level.
     *
     * @param  array<string, mixed>  $row
     */
    private static function language(array $row): string
    {
        return self::joined([
            self::tidy($row['name'] ?? null),
            self::LEVELS[$row['level'] ?? ''] ?? '',
        ]);
    }

    /**
     * A record and the paper it keeps, which is named rather than counted: the
     * office is deciding whether the card may say this, and «документ приложен»
     * does not say which document.
     *
     * @param  array<string, mixed>  $row
     */
    private static function clipped(string $read, array $row): string
    {
        $paper = self::tidy($row['document_name'] ?? null);

        return $read === '' || $paper === '' ? $read : $read.' — '.$paper;
    }

    /**
     * A line of the card as it was typed, and the words themselves as the
     * answer behind it.
     *
     * @return array{read: string, answer: mixed}
     */
    private static function words(mixed $value): array
    {
        $read = self::tidy($value);

        return self::said($read, $read);
    }

    /**
     * Every country the person is a citizen of, on one line: there may be
     * several and the sheet asks the line once, which is how the card's own
     * «Общие сведения» reads them.
     *
     * @param  array<array-key, mixed>  $countries
     * @return array{read: string, answer: mixed}
     */
    private static function countries(array $countries): array
    {
        $named = self::tidied($countries);

        return self::said(implode(', ', $named), self::sorted($named));
    }

    /**
     * A line with a fixed set of answers, under the name the card gives it.
     *
     * The answer kept is the card's own word for it — «male», «married» — so
     * that a sheet changing the sex does not light up the marital line it is
     * read through.
     *
     * @param  array<string, string>  $named
     * @return array{read: string, answer: mixed}
     */
    private static function oneOf(mixed $value, array $named): array
    {
        $said = is_string($value) ? $value : '';

        return self::said($named[$said] ?? '', $said);
    }

    /**
     * A date as every date in this application is written: «05 ноя. 2026», the
     * day padded so that a column of them is one width.
     *
     * The answer behind it is the day itself, because the two columns do not
     * hold a date the same way — the card hands over a Carbon and a request a
     * string somebody typed.
     *
     * @return array{read: string, answer: mixed}
     */
    private static function day(mixed $value): array
    {
        $date = self::date($value);

        return self::said(
            $date === null ? '' : $date->format('d').' '.self::MONTHS[(int) $date->format('n') - 1].' '.$date->format('Y'),
            $date?->format('Y-m-d') ?? '',
        );
    }

    /**
     * A date the card knows only to the month — the family's dates of birth —
     * written «мар. 1971».
     *
     * Named and not counted from, so May is «май» here: the months above are
     * cut for standing after a day, and one of them is a whole word in that
     * case rather than a short form. The card's own formatMonth() says this.
     */
    private static function month(mixed $value): string
    {
        $date = self::date($value);

        if ($date === null) {
            return '';
        }

        $month = (int) $date->format('n');

        return ($month === 5 ? 'май' : self::MONTHS[$month - 1]).' '.$date->format('Y');
    }

    /** One end of a period of work, in figures: «10.2024». */
    private static function period(mixed $year, mixed $month): string
    {
        return blank($year) || blank($month)
            ? ''
            : str_pad((string) (int) $month, 2, '0', STR_PAD_LEFT).'.'.(int) $year;
    }

    /**
     * A telephone written as the card writes it — «+992 90 124 32 99» — and
     * whose it is, where the line asks.
     *
     * Both the reading and the answer are taken off the number put through the
     * very call every form of this card puts it through before saving, so that a
     * number typed one way and stored another is neither read as something else
     * nor marked as a number somebody changed. A sheet arrives in E.164 — see
     * UpdateSheetRequest — and so does everything saved since the card began
     * tidying them; a card from before that is read as the card would write it
     * today rather than left looking unlike the column beside it.
     *
     * @return array{read: string, answer: mixed}
     */
    private static function phone(mixed $number, mixed $whose): array
    {
        $typed = self::tidy($number);
        $digits = $typed === '' ? '' : EmployeeCard::normalizePhone($typed);
        $contact = self::tidy($whose);

        return self::said(
            self::joined([
                preg_replace('/^\+992(\d{2})(\d{3})(\d{2})(\d{2})$/', '+992 $1 $2 $3 $4', $digits),
                $contact,
            ]),
            [$digits, $contact],
        );
    }

    /**
     * A line and the answer behind it, with the dash standing in for a line
     * nobody has answered.
     *
     * @return array{read: string, answer: mixed}
     */
    private static function said(string $read, mixed $answer): array
    {
        return ['read' => $read === '' ? self::NOTHING : $read, 'answer' => $answer];
    }

    /**
     * The facts of one record on one line, in the order the sheet asks them,
     * with whatever the record does not say left out rather than dashed: a dash
     * belongs to a line of the sheet that was asked and not answered, and a
     * record is one answer however many boxes it was typed into.
     *
     * @param  list<string>  $facts
     */
    private static function joined(array $facts): string
    {
        return implode(' · ', array_filter($facts, fn (string $fact) => $fact !== ''));
    }

    /** One value with its edges and its doubled spaces taken off. */
    private static function tidy(mixed $value): string
    {
        return is_scalar($value) ? trim((string) preg_replace('/\s+/u', ' ', (string) $value)) : '';
    }

    /**
     * Several of them, the empty ones dropped.
     *
     * @param  array<array-key, mixed>  $values
     * @return list<string>
     */
    private static function tidied(array $values): array
    {
        return array_values(array_filter(array_map(self::tidy(...), $values), fn (string $value) => $value !== ''));
    }

    /**
     * The same lines in an order of this class's own, for holding one list
     * against another.
     *
     * @param  list<string>  $lines
     * @return list<string>
     */
    private static function sorted(array $lines): array
    {
        sort($lines, SORT_STRING);

        return $lines;
    }

    /**
     * A date however the column or the form hands it over, or nothing at all.
     *
     * Always brought back to the clock this company keeps, because the two
     * columns of the reading reach it by different roads. What the form sent is
     * a written day — «2015-05» — and is read as a day in Dushanbe. What the
     * card holds arrives through a row serialised to an array, and Eloquent
     * writes a date there in UTC: the first of May comes back as
     * «2015-04-30T19:00:00Z», five hours earlier and, for a day that is the
     * first of its month, a month earlier as well.
     *
     * Left alone, every child and every relative would be read a month before
     * they were born, and the two lines they stand on would be marked changed
     * by a sheet that changes nothing — which is worse than it sounds, because
     * a mark that is always lit is a mark nobody looks at.
     */
    private static function date(mixed $value): ?Carbon
    {
        $zone = config('app.timezone');

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->setTimezone($zone);
        }

        return blank($value) || ! is_string($value)
            ? null
            : rescue(fn () => Carbon::parse($value)->setTimezone($zone), null, false);
    }

    /**
     * One of the card's lists as plain rows.
     *
     * @return list<array<string, mixed>>
     */
    private static function rowsOf(User $employee, string $relation): array
    {
        /** @var Collection<int, Model> $rows */
        $rows = $employee->relationLoaded($relation) ? $employee->{$relation} : $employee->{$relation}()->get();

        return $rows->map->toArray()->values()->all();
    }
}
