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
        'degree' => 'Учёная степень, звание',
        'award' => 'Государственные награды, поощрения',
        'military' => 'Отношение к воинской обязанности',
        'educations' => 'Образование',
        'work_experiences' => 'Трудовая деятельность',
        'marital_status' => 'Семейное положение',
        'children' => 'Дети',
        'passport_number' => 'Серия и номер паспорта',
        'passport_issued_at' => 'Дата выдачи паспорта',
        'passport_issued_by' => 'Кем выдан паспорт',
        'tax_number' => 'ИНН',
        'home_address' => 'Домашний адрес',
        'phone' => 'Телефон',
        'sos_phone' => 'Экстренный контакт',
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
