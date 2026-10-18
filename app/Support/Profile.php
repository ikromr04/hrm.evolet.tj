<?php

namespace App\Support;

use App\Models\Department;
use App\Models\Position;
use App\Models\RegulationSection;
use App\Models\User;

/**
 * What «Профиль должности» says for itself — the sheet the company calls СФД:
 * the words it is named by, and the table of facts the directory answers,
 * which nobody types and which is read beside the document now.
 *
 * The document is written for a position and for a department alike. The
 * company asked for a unit's СФД to be the same sheet as a job's rather than a
 * list of the jobs under it, and it is the same sheet: one template of nine
 * standard sections, the owner's wording in the place of a section it rewrote,
 * its own arrangement of the whole, its own heading. Document holds all of
 * that; this holds the two things that are the profile's own, each of which
 * answers for both kinds of owner.
 *
 * The first section is the one that differs, and it differs because the
 * application knows different things about the two: a job sits somewhere and is
 * called something, a unit sits somewhere, is led by somebody, holds people and
 * has units of its own. Neither table says a word the application cannot
 * answer.
 *
 * It has one placeholder, and only since the sheet began naming itself in its
 * own first line. It used to have none: the document said who it was about in
 * the table at the top, filled from the directory, so a body of it was read
 * exactly as it was written. That table has left the document for the column
 * beside it, the heading has left its field for the text, and one template is
 * read by both kinds of owner — so the first line has to say «ПРОФИЛЬ
 * ДОЛЖНОСТИ Аналитик» to one reader and «ПРОФИЛЬ ПОДРАЗДЕЛЕНИЯ Отдел дизайна»
 * to another, which is what {заголовок} is for and the whole of what it is for.
 */
final class Profile
{
    /**
     * The words the heading of the sheet begins with, before the name of the
     * thing it is written for — one opening for a job and one for a unit,
     * because «ПРОФИЛЬ ДОЛЖНОСТИ ОТДЕЛ ДИЗАЙНА» would say that a department is
     * a job.
     */
    public const TITLE = 'ПРОФИЛЬ ДОЛЖНОСТИ';

    public const UNIT_TITLE = 'ПРОФИЛЬ ПОДРАЗДЕЛЕНИЯ';

    /**
     * The one placeholder this document has, and what it stands for: not a
     * name, but the whole opening line of the sheet — which kind of sheet it is
     * and whose.
     *
     * It is there because one template is read by both kinds of owner and the
     * two openings differ: «ПРОФИЛЬ ДОЛЖНОСТИ Аналитик» for a job and «ПРОФИЛЬ
     * ПОДРАЗДЕЛЕНИЯ Отдел дизайна» for a unit. The document names itself in its
     * own first line now, so that line has to say two things at once, and a
     * token is how this application already solved exactly that for «Положение
     * о работе». The alternative was two templates, which is the one thing the
     * design refuses to fork.
     *
     * @var array<string, string>
     */
    public const PLACEHOLDERS = [
        '{заголовок}' => 'Заголовок листа: вид профиля и чей он',
    ];

    /**
     * What the placeholder reads as for this owner — the opening the sheet
     * belongs to and the owner's name after it.
     *
     * Read with no owner it stands for itself, which is how the standard text
     * is written and read: whoever edits the template sees the token.
     *
     * The name is the full one rather than the abbreviation, and it is read as
     * it stands. «ПРОФИЛЬ ПОДРАЗДЕЛЕНИЯ ОД» is not how a printed sheet is
     * headed, and «ПРОФИЛЬ ПОДРАЗДЕЛЕНИЯ ОТДЕЛ ДИЗАЙНА» is the nominative where
     * Russian wants the genitive — one ending away from right. Nothing here
     * declines a name, and now nothing has to: the line is a line of the text,
     * so whoever wants the ending right writes the word they want.
     *
     * @return array<string, string> token => what is read in its place
     */
    public static function values(Department|Position|null $owner): array
    {
        if ($owner === null) {
            return array_combine(array_keys(self::PLACEHOLDERS), array_keys(self::PLACEHOLDERS));
        }

        $opening = $owner instanceof Department ? self::UNIT_TITLE : self::TITLE;

        return ['{заголовок}' => $opening.' '.$owner->name];
    }

    /**
     * What a position's sheet used to open with — «01 ОБЩАЯ ИНФОРМАЦИЯ», the
     * one section of the document nobody wrote.
     *
     * Not a section any more. It is read in the column beside the document,
     * where the page was already saying most of it: the page's own heading is
     * the job, and the card above the sheet is where it sits. So the fields go
     * on travelling and nothing draws them twice.
     *
     * Five fields, and three of them are things the application already knows:
     * where the position sits, in both steps of the structure, and what it is
     * called. So they are answered from the directory rather than typed — a
     * profile whose first section is typed is a profile that disagrees with the
     * structure a week later — and they travel as the fields themselves rather
     * than as a built table, so the page draws the document's own table and
     * nothing has to read markup to find a value.
     *
     * The Word file has two rows more, «КАТЕГОРИЯ» and «ГРАЙД», and the company
     * keeps neither: there is no ladder written down to grade a job against. So
     * they are not rows here either, rather than two lines nobody would ever
     * fill in on a sheet that is printed and signed.
     *
     * The structure of the company is two deep, and the table's two top fields
     * are those two levels: a position's department is «ОТДЕЛ» and the
     * department above it is «ДЕПАРТАМЕНТ». A position attached to a top-level
     * department belongs to the department itself, and then there is no отдел to
     * name — the field is empty rather than filled with the same name twice.
     *
     * Two fields are left empty, and this is the honest part. «ПОДЧИНЕНИЕ» is
     * the position this one answers to and «ПОДЧИНЕННЫЕ» are the positions that
     * answer to it, and the application keeps no hierarchy of positions at all.
     * What it keeps is which department sits inside which, and which positions
     * lead a department — the is_head flag on a position, which says that
     * whoever holds it heads the unit.
     *
     * That flag is nearer to an answer than the one it replaced, which was a
     * fact about a person, but it is still not this one. A department may have
     * several leading positions or none; one of them does not say which of the
     * others answer to it; and nothing says what a leading position of a
     * sub-department answers to in the department above. A sheet that is
     * printed and signed is the last place to guess, so these two wait for a
     * hierarchy somebody writes down. The page draws them empty meanwhile,
     * because the table has those rows whatever we can say in them.
     *
     * @return array<string, mixed>
     */
    public static function general(Position $position): array
    {
        $own = $position->department;
        $above = $own?->parent;

        return [
            // «ОТДЕЛ» and «ДЕПАРТАМЕНТ»: the unit the position belongs to and
            // the department that unit is part of. A position the directory
            // could not place yet names neither.
            'unit' => $above === null ? null : self::names($own),
            'department' => self::names($above ?? $own),
            // «ДОЛЖНОСТЬ», as the directory spells it.
            'position' => $position->name,
            // «ПОДЧИНЕНИЕ» and «ПОДЧИНЕННЫЕ», for which see above: the
            // application has no hierarchy of positions to read them from.
            'reports_to' => null,
            'subordinates' => [],
        ];
    }

    /**
     * And the same for a department's sheet, answered about a unit: five
     * fields, every one of them something the application is told rather than
     * something it works out.
     *
     * On that page they were the column beside the document written out a
     * second time — who leads the unit, what is under it and how many work
     * there are three cards of it — so they are read there and nowhere else
     * now.
     *
     * «ОТДЕЛ» and «ДЕПАРТАМЕНТ» are read exactly as the position's sheet reads
     * them, because they are the same two steps of the same structure: a
     * department with a parent is an отдел inside that департамент, and a
     * top-level one is the департамент itself and names no отдел rather than
     * naming itself twice.
     *
     * Then three the unit answers and a job does not. «РУКОВОДИТЕЛЬ» is
     * whoever holds a leading position of this department — the one piece of
     * «who leads whom» the application actually keeps — read among the people
     * who still work here, and a department may have several or none. «ЧИСЛЕННОСТЬ» is how many work
     * there, counted twice: the department itself, and the whole branch under
     * it, which differ only where it has units of its own. «ОТДЕЛЫ В СОСТАВЕ»
     * are those units.
     *
     * The position's two empty rows are not here. «ПОДЧИНЕНИЕ» and
     * «ПОДЧИНЕННЫЕ» say that the application keeps no hierarchy of positions;
     * about a department it keeps one, and it is already written down in the
     * two rows above and the one below — so they would be the same facts under
     * a second pair of names, which is worse than leaving them out.
     *
     * @return array<string, mixed>
     */
    public static function unit(Department $department): array
    {
        $above = $department->parent;

        return [
            // «ОТДЕЛ» and «ДЕПАРТАМЕНТ».
            'unit' => $above === null ? null : self::names($department),
            'department' => self::names($above ?? $department),
            // «РУКОВОДИТЕЛЬ»: by surname, as every list of people is read, and
            // empty where nobody has been appointed — which is a fact about the
            // unit and not a field somebody forgot.
            'heads' => $department->heads()
                ->active()
                ->orderBy('surname')
                ->orderBy('name')
                ->get(['users.id', 'users.name', 'users.surname'])
                ->map(fn (User $head) => [
                    'id' => $head->id,
                    'name' => "{$head->surname} {$head->name}",
                ])
                ->values()
                ->all(),
            // «ЧИСЛЕННОСТЬ»: the people of this department, and of everything
            // under it. Each person once, however many of its units they are
            // in, which is how the staff list counts a department too.
            'staff' => [
                'own' => $department->users()->active()->count(),
                'total' => User::active()
                    ->whereHas('departments', fn ($query) => $query->whereIn('departments.id', $department->descendantIds(true)))
                    ->count(),
            ],
            // «ОТДЕЛЫ В СОСТАВЕ»: in name order, each a way back to its own
            // page. Empty for a unit that has none, and for every unit inside
            // one — the structure is two deep.
            'children' => $department->children->map(fn (Department $child) => self::names($child))->values()->all(),
        ];
    }

    /**
     * The document itself: its heading, the section nobody writes and the
     * sections somebody does, read for this owner.
     *
     * Both pages that carry a profile ask for it this way — a position's and a
     * department's — so that one shape reaches one component and neither page
     * grows an idea of its own about what the document is. It is the shape the
     * regulation travels in, with two differences: a section says whether it is
     * drawn from data rather than written, and the fields it is drawn from
     * travel as `general` — beside the document rather than inside it now, for
     * the column at the side of the page to read. The body comes twice, as the
     * regulation's does: once with the opening line answered for this owner,
     * and once as it was written, which is what the editor puts back.
     *
     * No heading travels with it. The sheet names itself in the first line of
     * its own text, written and read like the rest of it.
     *
     * @return array{general: array<string, mixed>, sections: list<array<string, mixed>>}
     */
    public static function paper(Department|Position $owner): array
    {
        $values = self::values($owner);

        return [
            'general' => $owner instanceof Department ? self::unit($owner) : self::general($owner),
            'sections' => RegulationSection::forOwner(Document::PROFILE, $owner)
                ->map(fn (RegulationSection $section, int $place) => [
                    'id' => $section->id,
                    // "template" — the company's own text, read as it is
                    // written; "override" — this owner's wording of a standard
                    // section; "own" — a section no template has.
                    'origin' => $section->origin(),
                    'template_section_id' => $section->template_section_id,
                    // Worked out here and stored nowhere: the number is the
                    // place the section is read in this owner's document, and
                    // one section of the template is read at a different place
                    // in every owner's.
                    'number' => RegulationSection::number($place),
                    'title' => $section->title,
                    'order' => $section->order,
                    // Sanitized where it was written, so it is already safe to
                    // put on the page as the markup it is.
                    'body' => Document::resolve($section->body, $values),
                    // The same body as somebody typed it, braces and all, which
                    // is what the editor has to put back in front of whoever
                    // edits it.
                    'body_written' => $section->body,
                    // Drawn from the directory rather than written: the page
                    // reads `general` in its place, and the editor does not
                    // open on it.
                    'computed' => $section->computed,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * A department as a field of the table names it: the short form it is known
     * by, with the full name along for the hover that says what those letters
     * stand for — and the id, so the field can be a way back to the department
     * it names.
     *
     * @return array{id: int, name: string, full_name: string}|null
     */
    private static function names(?Department $department): ?array
    {
        return $department === null ? null : [
            'id' => $department->id,
            'name' => $department->display_name,
            'full_name' => $department->name,
        ];
    }
}
