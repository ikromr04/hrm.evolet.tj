<?php

namespace App\Support;

use App\Models\Department;
use App\Models\Position;
use App\Models\RegulationSection;
use App\Models\User;

/**
 * What «Профиль должности» says for itself — the sheet the company calls СФД:
 * the words its heading begins with, and the table of «01 ОБЩАЯ ИНФОРМАЦИЯ»,
 * which nobody types.
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
 * It has no placeholders. The regulation needs them because one text is read on
 * twenty-six department pages and has to name each of them inside a sentence;
 * the profile says who it is about in a table at the top, filled from the
 * directory, so a body of it is read exactly as it was written and the editor
 * has no tokens to offer.
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
     * The heading of the printed sheet for one owner: what somebody typed for
     * it, or — while nobody has — the name of the document followed by the
     * owner's own name, upper-cased exactly as it stands.
     *
     * «ПРОФИЛЬ ДОЛЖНОСТИ РУКОВОДИТЕЛЬ» is the wrong case, and on purpose, for
     * the same reason the regulation's heading is: Russian wants the genitive
     * here, nothing in this application declines a name, and a rule that gets
     * one name right gets another wrong on a sheet that is printed and signed.
     * So the default is one ending away from correct and visibly so, and
     * whoever reads it corrects the word in the field once.
     *
     * A department is named in full rather than by its abbreviation: this is
     * the line a printed sheet is headed with, and «ПРОФИЛЬ ПОДРАЗДЕЛЕНИЯ ОД»
     * is not how one is written.
     */
    public static function title(Department|Position $owner): string
    {
        $own = trim((string) $owner->profile_title);

        if ($own !== '') {
            return $own;
        }

        $opening = $owner instanceof Department ? self::UNIT_TITLE : self::TITLE;

        return $opening.' '.mb_strtoupper($owner->name);
    }

    /**
     * «01 ОБЩАЯ ИНФОРМАЦИЯ» of a position's sheet — the one section of the
     * document nobody writes.
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
     * What it keeps is which department sits inside which, and which *people*
     * lead a department — the is_head flag on department_user, which is a fact
     * about a person and not about a job.
     *
     * A hierarchy read out of that would be read out of whoever happens to hold
     * the job today: that one person may hold two positions and there is no
     * saying which of them leads, that a department may have several heads or
     * none, and above all that a position nobody holds would answer nothing —
     * while a profile of a vacant position is exactly the one somebody opens,
     * because it is what the job is advertised and hired against. A sheet that
     * is printed and signed is the last place to guess, so these two wait for a
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
     * And «01 ОБЩАЯ ИНФОРМАЦИЯ» of a department's sheet, which is the same
     * section answered about a unit: five fields, every one of them something
     * the application is told rather than something it works out.
     *
     * «ОТДЕЛ» and «ДЕПАРТАМЕНТ» are read exactly as the position's sheet reads
     * them, because they are the same two steps of the same structure: a
     * department with a parent is an отдел inside that департамент, and a
     * top-level one is the департамент itself and names no отдел rather than
     * naming itself twice.
     *
     * Then three the unit answers and a job does not. «РУКОВОДИТЕЛЬ» is the
     * is_head flag on department_user — the one piece of «who leads whom» the
     * application actually keeps — read among the people who still work here,
     * and a department may have several or none. «ЧИСЛЕННОСТЬ» is how many work
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
     * travel beside the sections as `general`, so the page draws the document's
     * own table instead of reading a built one. The body comes twice, as the
     * regulation's does, although here the two are the same string: there are
     * no placeholders in this document, so what was written is what is read.
     *
     * @return array{title: string, general: array<string, mixed>, sections: list<array<string, mixed>>}
     */
    public static function paper(Department|Position $owner): array
    {
        return [
            'title' => self::title($owner),
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
                    'body' => $section->body,
                    // The same body as somebody typed it, for the editor. The
                    // profile has no placeholders, so it is the same body.
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
