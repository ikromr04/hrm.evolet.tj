<?php

namespace App\Support;

use App\Models\Department;
use App\Models\Position;
use App\Models\RegulationSection;
use App\Models\User;

/**
 * What «Положение о работе» says for itself: the words it is named by, and what
 * the standard text puts in the place of a department's name.
 *
 * The document is one text read for every department, so the sentence on file
 * says «{подразделение} является самостоятельным структурным подразделением…»
 * and the department's page reads its own name there. The placeholders are few
 * and fixed: a short list is one a person can learn, and every one of them
 * answers out of what the application already knows.
 *
 * Russian declines a name, and nothing here tries to — nor has to. Every
 * sentence names the department after a word that carries the case for it,
 * «структура подразделения «Отдел дизайна»» rather than «структура Отдела
 * дизайна», so the one name in the nominative is the whole of it and nobody
 * is asked to type their department's name twice more.
 *
 * The document is one text, and the whole of it is in that text. The company
 * writes it as one file, so the name of the sheet is its first line and its
 * parts — ОБЩЕЕ ПОЛОЖЕНИЕ, ПРАВА, ОБЯЗАННОСТИ, ОТВЕТСТВЕННОСТЬ — are headings
 * after it, and whoever writes it moves, renames and renumbers any of them like
 * any other line. Nothing here draws a heading around it or works one out: the
 * standard text names itself «ПОЛОЖЕНИЕ О РАБОТЕ {подразделение}», which reads
 * as the department's own name like every other placeholder in it, and a
 * department that wants it in the genitive writes the word it wants.
 *
 * Which is the one thing it does not share with «Профиль должности», where the
 * company fills in nine sections one at a time under a heading kept in a field:
 * a regulation is a document of one section, read and written by exactly the
 * machinery the nine are.
 *
 * Everything else it shares with that document — what a body may be made of,
 * how long it may be, how a placeholder is answered — is in Document, because
 * the two are one piece of machinery with two names.
 */
final class Regulation
{
    /**
     * Every placeholder the text may carry, and what it stands for — the list
     * the editor offers and the only tokens that are ever replaced.
     *
     * @var array<string, string>
     */
    public const PLACEHOLDERS = [
        '{заголовок}' => 'Заголовок листа: вид положения и чьё оно',
        '{подразделение}' => 'Название подразделения',
        '{аббревиатура}' => 'Аббревиатура подразделения',
        '{руководитель}' => 'Руководитель подразделения',
        '{департамент}' => 'Департамент, в состав которого входит подразделение',
    ];

    /**
     * The words the heading of the sheet begins with, before the name of the
     * thing it is written for.
     */
    public const TITLE = 'ПОЛОЖЕНИЕ О РАБОТЕ';

    /**
     * What each placeholder reads as for this owner.
     *
     * For the template there is nobody to ask, so every placeholder stands for
     * itself: the standard text is written and read with the braces showing,
     * which is exactly what whoever edits it needs to see.
     *
     * A position answers the department's placeholders out of the unit it is
     * filed in. The standard text is written about a подразделение — «{подразделение}
     * является самостоятельным структурным подразделением…» — and a job filed
     * in a unit is a job in that unit, so the sentences read about the unit
     * until somebody writes the job its own. A position filed nowhere answers
     * them with the dash a printed sheet uses for what nobody has filled in.
     *
     * @return array<string, string> token => what is read in its place
     */
    public static function values(Department|Position|null $owner): array
    {
        if ($owner === null) {
            return array_combine(array_keys(self::PLACEHOLDERS), array_keys(self::PLACEHOLDERS));
        }

        // The unit the sentences are about: the department itself, or the one a
        // position is filed in.
        $department = $owner instanceof Department ? $owner : $owner->department;

        $heads = $department === null ? collect() : $department->heads()
            ->active()
            ->orderBy('surname')
            ->orderBy('name')
            ->get(['users.id', 'users.name', 'users.surname'])
            ->map(fn (User $head) => "{$head->surname} {$head->name}");

        return [
            '{заголовок}' => self::TITLE.' '.self::whose($owner),
            // The full name, not the abbreviation: this is a document, and
            // «ОЦМ является самостоятельным структурным подразделением» is not
            // how one is written.
            '{подразделение}' => $department?->name ?: Document::UNKNOWN,
            '{аббревиатура}' => $department === null ? Document::UNKNOWN : ($department->abbreviation ?: $department->name),
            '{руководитель}' => $heads->implode(', ') ?: Document::UNKNOWN,
            '{департамент}' => $department?->parent?->name ?: Document::UNKNOWN,
        ];
    }

    /**
     * Whose sheet it is, as the opening line names it.
     *
     * A department is named in full: this is the line a printed sheet is headed
     * with, and «ПОЛОЖЕНИЕ О РАБОТЕ ОД» is not how one is written. A position is
     * named as every screen names it — the job and the unit it is filed in,
     * «Аналитик (ОАП)» — because two departments may both have an «Аналитик»
     * and a sheet that is printed and signed has to say which one this is.
     */
    private static function whose(Department|Position $owner): string
    {
        return $owner instanceof Department ? $owner->name : $owner->display_name;
    }

    /**
     * The document itself: the one section its text is, read for this owner.
     *
     * Two pages ask for it, and each asks about itself now — a department's own
     * and a position's own. A position used to read the regulation of the
     * department it was filed in, read-only; the company asked for a job to
     * keep one of its own, so the page that reads it is the page that writes
     * it, and this is where both of them get the same shape of answer.
     *
     * No heading travels with it, because there is nothing left to head: the
     * name of the sheet is the first line of the text, written like the rest of
     * it and read like the rest of it. «ПОЛОЖЕНИЕ О РАБОТЕ ДЕПАРТАМЕНТ
     * АНАЛИТИКИ И СТАТИСТИКИ» used to be worked out from the name, in the
     * nominative where Russian wants the genitive, and offered in a field for
     * somebody to correct; now the word somebody wants is a word they type into
     * the document, in the one place the document is written.
     *
     * @return array{sections: list<array<string, mixed>>}
     */
    public static function paper(Department|Position $owner): array
    {
        $values = self::values($owner);
        $read = $owner instanceof Department
            ? RegulationSection::forDepartment($owner)
            : RegulationSection::regulationForPosition($owner);

        return [
            'sections' => $read->map(fn (RegulationSection $section, int $place) => [
                'id' => $section->id,
                // "template" — the company's own text, read as it is written;
                // "override" — this owner's wording of the standard text;
                // "own" — a section no template has.
                'origin' => $section->origin(),
                'template_section_id' => $section->template_section_id,
                // Worked out here and stored nowhere: the number is the place
                // the section is read in. The page does not draw it — this
                // document is one text, and the numbers a reader sees are the
                // ones typed into its headings — but it is part of what a
                // section is, and «Профиль должности» travels in the same
                // shape and does draw it.
                'number' => RegulationSection::number($place),
                'title' => $section->title,
                'order' => $section->order,
                // Sanitized where it was written, so it is already safe to put
                // on the page as the markup it is.
                'body' => Document::resolve($section->body, $values),
                // The same body as somebody typed it, for the editor.
                'body_written' => $section->body,
            ])->values()->all(),
        ];
    }

    /**
     * The placeholders as the editor offers them: what to type, what it means,
     * and what it would read as here, so the one choosing is not guessing.
     *
     * @return list<array{token: string, title: string, value: string}>
     */
    public static function offer(Department|Position|null $owner): array
    {
        $values = self::values($owner);

        $offer = [];

        foreach (self::PLACEHOLDERS as $token => $title) {
            $offer[] = ['token' => $token, 'title' => $title, 'value' => $values[$token]];
        }

        return $offer;
    }
}
