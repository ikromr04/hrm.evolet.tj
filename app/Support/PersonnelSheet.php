<?php

namespace App\Support;

use App\Models\User;

/**
 * How far a card has got through «Личный листок по учёту кадров».
 *
 * The sheet is the paper a personnel office keeps, and the card is where its
 * answers live. This counts the lines of it that have an answer, so that a
 * department can see which cards are still waiting for one and ask the person
 * rather than go looking themselves.
 *
 * What is counted is answers, not rows. The languages and the relatives are not
 * counted at all: there is no number of them that is right, and a card naming
 * one language would otherwise count as finished while a card naming three
 * counted the same. «Учёная степень», «Государственные награды» and «Отношение
 * к воинской обязанности» are each one line, and «Нет» on them is an answer like
 * any other — it is the empty line that means nobody has asked yet.
 *
 * The places of study and the jobs held before are counted the same way, though
 * they are lists: each is answered before it is filled, so what counts is that
 * somebody said whether there are any — not how many were then written down.
 *
 * Counted over the lines this reader may read, and no others. A figure taken
 * over the whole card would say «60%» to somebody who may see four lines of it,
 * which is a statement about the lines they may not see — and the list of what
 * is still wanted would name them outright.
 */
final class PersonnelSheet
{
    /**
     * The lines of the sheet that are counted: the card's own field key, and the
     * name the sheet gives it.
     *
     * The key is a field of App\Support\EmployeeFields, which is what makes a
     * line countable at all — a line of the sheet with no field behind it is one
     * the card cannot answer.
     *
     * In the order the sheet asks them, part by part, because that order is what
     * is shown: «Не заполнено» is read beside the sheet itself, and a list that
     * named the military standing before the schooling would send somebody up
     * the page to look for a part that is below. The parts the sheet draws and
     * does not count — the languages, the relatives — leave gaps in the run, and
     * that is all they leave.
     *
     * @var array<string, string>
     */
    public const LINES = [
        'surname' => 'Фамилия',
        'name' => 'Имя',
        'patronymic' => 'Отчество',
        'sex' => 'Пол',
        'birth_date' => 'Дата рождения',
        'birth_place' => 'Место рождения',
        'nationality' => 'Национальность',
        'citizenship' => 'Гражданство',
        'educations' => 'Образование',
        'degree' => 'Учёная степень, звание',
        'work_experiences' => 'Трудовая деятельность',
        'award' => 'Государственные награды, поощрения',
        'marital_status' => 'Семейное положение',
        'children' => 'Дети',
        'passport_number' => 'Серия и номер паспорта',
        'passport_issued_at' => 'Дата выдачи паспорта',
        'passport_issued_by' => 'Кем выдан паспорт',
        'tax_number' => 'ИНН',
        'home_address' => 'Домашний адрес',
        'phone' => 'Телефон',
        'sos_phone' => 'Экстренный контакт',
        'military' => 'Отношение к воинской обязанности',
    ];

    /**
     * The lines still wanted, as the sheet names them, in the order it asks.
     *
     * @return list<string>
     */
    public static function missing(User $employee, User $viewer): array
    {
        $answered = self::answers($employee);

        return array_values(array_map(
            fn (string $line) => self::LINES[$line],
            array_filter(self::counted($employee, $viewer), fn (string $line) => ! $answered[$line]),
        ));
    }

    /**
     * How much of the sheet is answered, as a whole number of per cent.
     *
     * A hundred where there is nothing to count: a reader who may read none of
     * these lines is told nothing rather than nought, which would read as a card
     * nobody has filled in.
     */
    public static function filled(User $employee, User $viewer): int
    {
        $counted = self::counted($employee, $viewer);

        if ($counted === []) {
            return 100;
        }

        $answered = self::answers($employee);

        return (int) round(count(array_filter($counted, fn (string $line) => $answered[$line])) / count($counted) * 100);
    }

    /**
     * The counted lines themselves, each as its key and the name the sheet
     * gives it, in the order it asks them.
     *
     * The two figures above are the saved truth, and that is all the card needs
     * while nobody is touching it. But the sheet is now retyped in place, and
     * somebody halfway through filling it in wants «Не заполнено» to answer for
     * what they have just typed rather than for what is still on file — so the
     * page repeats the «is this line answered» test over the form's own values
     * while the sheet is open, and falls back to filled() and missing() the
     * moment it is closed. To repeat that test it has to know which lines are
     * this reader's to count, which is what this hands over: the same list
     * counted() works out, surfaced rather than worked out a second time in the
     * browser, where a line that quietly joined the sheet would be missed.
     *
     * @return list<array{key: string, title: string}>
     */
    public static function lines(User $employee, User $viewer): array
    {
        return array_map(
            fn (string $line) => ['key' => $line, 'title' => self::LINES[$line]],
            self::counted($employee, $viewer),
        );
    }

    /**
     * The lines that are short of their paper: a line saying there is something
     * is a line with proof of it somewhere, and these are the ones that say so
     * and have nothing attached.
     *
     * Not part of filled() and not part of missing(), because a paper is not an
     * answer. The line «Учёная степень, звание» is answered the moment somebody
     * writes what the degree is; whether the diploma has been handed in is the
     * other question, and a card that mixed the two would say «не заполнено» of
     * a line that is filled in and send somebody to ask about it.
     *
     * Only where there is something to prove. «Нет» has no document and an
     * unanswered line has nothing to ask for yet, so neither is short of
     * anything. A place of study still being studied at is the same case: the
     * diploma is wanted once the studying has ended, and not while somebody is
     * in their second year.
     *
     * Over the lines this reader may read, as everything else here is.
     *
     * @return list<string>
     */
    public static function papers(User $employee, User $viewer): array
    {
        $visible = EmployeeFields::visibleTo($viewer, $employee);

        // Read without being attached, as answers() reads the private row and
        // for the same reason: on one's own profile this employee is the
        // signed-in account, which is shared to every page with whatever hangs
        // off it. Asking whether a diploma is on file is not the same as being
        // allowed to read the card it is clipped to.
        $details = $employee->relationLoaded('details') ? $employee->details : $employee->details()->first();

        $wanted = [];

        if (in_array('educations', $visible, true)) {
            $educations = $employee->relationLoaded('educations') ? $employee->educations : $employee->educations()->get();

            foreach ($educations as $education) {
                if ($education->graduated_year !== null && $education->document_path === null) {
                    // Named by the place of study, since a card may name three
                    // of them and «Образование» three times over is a list that
                    // says there are three papers missing and not which.
                    $wanted[] = trim((string) $education->institution) === ''
                        ? self::LINES['educations']
                        : self::LINES['educations'].' — '.trim((string) $education->institution);
                }
            }
        }

        foreach (['degree', 'award', 'military'] as $line) {
            $said = trim((string) ($details?->{$line} ?? ''));

            if (in_array($line, $visible, true) && $said !== '' && $said !== EmployeeCard::NONE && $details?->{$line.'_document_path'} === null) {
                $wanted[] = self::LINES[$line];
            }
        }

        return $wanted;
    }

    /**
     * Which lines are this reader's to count.
     *
     * @return list<string>
     */
    private static function counted(User $employee, User $viewer): array
    {
        $visible = EmployeeFields::visibleTo($viewer, $employee);

        return array_values(array_filter(
            array_keys(self::LINES),
            fn (string $line) => in_array($line, $visible, true),
        ));
    }

    /**
     * Whether each counted line has an answer.
     *
     * A line is answered when it says something. «Нет» says something; an empty
     * line does not. The two the card keeps as flags are read the same way —
     * «Женат» and «Не женат» are both answers, and so are «Есть дети» and «Нет»,
     * while a card nobody has asked holds neither.
     *
     * @return array<string, bool>
     */
    private static function answers(User $employee): array
    {
        // Read without being attached to the model, unless it is already there.
        // On one's own profile this employee is the signed-in account itself,
        // and that account is shared to every page as «auth.user» with whatever
        // relations happen to hang off it — so loading the private row here to
        // count it would hand the whole of it to the browser: the passport, the
        // tax number, both telephones, the spouse. Counting a line is not the
        // same as being allowed to read it.
        $details = $employee->relationLoaded('details') ? $employee->details : $employee->details()->first();

        $said = fn (?string $value) => $value !== null && trim($value) !== '';

        return [
            'surname' => $said($employee->surname),
            'name' => $said($employee->name),
            'patronymic' => $said($employee->patronymic),
            'sex' => $said($employee->sex),
            'birth_date' => $details?->birth_date !== null,
            'birth_place' => $said($details?->birth_place),
            'nationality' => $said($details?->nationality),
            // Kept as a list of countries rather than as a line, but answered
            // once like a line: a person has a citizenship or has not said.
            'citizenship' => $employee->citizenships()->exists(),
            'degree' => $said($details?->degree),
            'award' => $said($details?->award),
            'military' => $said($details?->military),
            // Two lists, counted by their answer rather than by their length.
            // How many universities a person went to is not a measure of how far
            // the sheet has got; whether anybody asked them is.
            'educations' => $details?->has_educations !== null,
            'work_experiences' => $details?->has_experience !== null,
            'marital_status' => $said($details?->marital_status),
            'children' => $details?->has_children !== null,
            // The series and the number are one line of the sheet, as they are
            // one line of a passport.
            'passport_number' => $said($details?->passport_series) || $said($details?->passport_number),
            'passport_issued_at' => $details?->passport_issued_at !== null,
            'passport_issued_by' => $said($details?->passport_issued_by),
            'tax_number' => $said($details?->tax_number),
            'home_address' => $said($details?->home_address),
            'phone' => $said($details?->phone),
            'sos_phone' => $said($details?->sos_phone),
        ];
    }
}
