<?php

namespace App\Support;

use App\Models\Department;
use App\Models\RegulationSection;
use App\Models\User;

/**
 * What «Положение о работе» says for itself: the words its heading begins with,
 * and what the standard text puts in the place of a department's name.
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
 * Everything this document shares with «Профиль должности» — what a body may be
 * made of, how long it may be, how a placeholder is answered — is in Document,
 * because the two are one piece of machinery with two names.
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
        '{подразделение}' => 'Название подразделения',
        '{аббревиатура}' => 'Аббревиатура подразделения',
        '{руководитель}' => 'Руководитель подразделения',
        '{департамент}' => 'Департамент, в состав которого входит подразделение',
    ];

    /**
     * The words the heading of the sheet begins with, before the name of the
     * department it is written for.
     */
    public const TITLE = 'ПОЛОЖЕНИЕ О РАБОТЕ';

    /**
     * What each placeholder reads as for this department.
     *
     * For the template there is no department to ask, so every placeholder
     * stands for itself: the standard text is written and read with the braces
     * showing, which is exactly what whoever edits it needs to see.
     *
     * @return array<string, string> token => what is read in its place
     */
    public static function values(?Department $department): array
    {
        if ($department === null) {
            return array_combine(array_keys(self::PLACEHOLDERS), array_keys(self::PLACEHOLDERS));
        }

        $heads = $department->heads()
            ->active()
            ->orderBy('surname')
            ->orderBy('name')
            ->get(['users.id', 'users.name', 'users.surname'])
            ->map(fn (User $head) => "{$head->surname} {$head->name}");

        return [
            // The full name, not the abbreviation: this is a document, and
            // «ОЦМ является самостоятельным структурным подразделением» is not
            // how one is written.
            '{подразделение}' => $department->name,
            '{аббревиатура}' => $department->abbreviation ?: $department->name,
            '{руководитель}' => $heads->implode(', ') ?: Document::UNKNOWN,
            '{департамент}' => $department->parent?->name ?: Document::UNKNOWN,
        ];
    }

    /**
     * The heading of the printed sheet for one department: what that department
     * typed, or — while nobody has typed anything — the name of the document
     * followed by the department's own name, upper-cased exactly as it stands.
     *
     * «ПОЛОЖЕНИЕ О РАБОТЕ ДЕПАРТАМЕНТ АНАЛИТИКИ И СТАТИСТИКИ» is the wrong
     * case, and on purpose. Russian wants the genitive of the name here, and
     * nothing in this application declines a name: a rule that gets «Отдела
     * дизайна» right gets somebody else's name wrong, and this is printed and
     * signed. So the default is one ending away from correct and visibly so —
     * whoever reads it fixes the word in the field, once, and the heading is
     * theirs from then on. Obviously wrong where somebody can correct it beats
     * quietly wrong where nobody looks.
     *
     * A heading goes through the placeholders like any other text of the
     * document, so somebody who writes {подразделение} into it reads the name
     * there and is not the one person told that the tokens work everywhere
     * else.
     */
    public static function title(Department $department): string
    {
        $own = trim((string) $department->regulation_title);

        return $own === ''
            ? self::TITLE.' '.mb_strtoupper($department->name)
            : (string) Document::resolve($own, self::values($department));
    }

    /**
     * The document itself: its heading and its sections, read for this
     * department. Two pages ask for it — the department's own, where it is also
     * written, and a position's, where the regulation of the department the
     * position belongs to is read and nothing more. Keeping it here is what
     * stops the second page from growing a second idea of what the document is.
     *
     * @return array{title: string, sections: list<array<string, mixed>>}
     */
    public static function paper(Department $department): array
    {
        $values = self::values($department);

        return [
            'title' => self::title($department),
            'sections' => RegulationSection::forDepartment($department)->map(fn (RegulationSection $section, int $place) => [
                'id' => $section->id,
                // "template" — the company's own text, read as it is written;
                // "override" — this department's wording of a standard section;
                // "own" — a section no template has.
                'origin' => $section->origin(),
                'template_section_id' => $section->template_section_id,
                // Worked out here and stored nowhere: the number is the place
                // the section is read in this department's document, and one
                // section of the template is read at a different place in every
                // department's.
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
    public static function offer(?Department $department): array
    {
        $values = self::values($department);

        $offer = [];

        foreach (self::PLACEHOLDERS as $token => $title) {
            $offer[] = ['token' => $token, 'title' => $title, 'value' => $values[$token]];
        }

        return $offer;
    }
}
