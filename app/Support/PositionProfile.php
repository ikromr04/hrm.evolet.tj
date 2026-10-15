<?php

namespace App\Support;

use App\Models\Department;
use App\Models\Position;

/**
 * What «Профиль должности» says for itself — the sheet the company calls СФД:
 * the words its heading begins with, and the table of «01 ОБЩАЯ ИНФОРМАЦИЯ»,
 * which nobody types.
 *
 * The document belongs to a position and is kept exactly as «Положение о
 * работе» is kept — a template of nine standard sections, one position's
 * wording in the place of a section it rewrote, its own arrangement of the
 * whole, its own heading. Document holds all of that; this holds the two things
 * that are the profile's own.
 *
 * It has no placeholders. The regulation needs them because one text is read on
 * twenty-six department pages and has to name each of them inside a sentence;
 * the profile says who it is about in a table at the top, filled from the
 * directory, so a body of it is read exactly as it was written and the editor
 * has no tokens to offer.
 */
final class PositionProfile
{
    /**
     * The words the heading of the sheet begins with, before the name of the
     * position it is written for.
     */
    public const TITLE = 'ПРОФИЛЬ ДОЛЖНОСТИ';

    /**
     * The heading of the printed sheet for one position: what somebody typed
     * for it, or — while nobody has — the name of the document followed by the
     * position's own name, upper-cased exactly as it stands.
     *
     * «ПРОФИЛЬ ДОЛЖНОСТИ РУКОВОДИТЕЛЬ» is the wrong case, and on purpose, for
     * the same reason the regulation's heading is: Russian wants the genitive
     * here, nothing in this application declines a name, and a rule that gets
     * one name right gets another wrong on a sheet that is printed and signed.
     * So the default is one ending away from correct and visibly so, and
     * whoever reads it corrects the word in the field once.
     */
    public static function title(Position $position): string
    {
        $own = trim((string) $position->profile_title);

        return $own === ''
            ? self::TITLE.' '.mb_strtoupper($position->name)
            : $own;
    }

    /**
     * «01 ОБЩАЯ ИНФОРМАЦИЯ» — the one section of the document nobody writes.
     *
     * In the Word file it is a table of seven fields, and six of them are
     * things the application already knows or is told: where the position sits,
     * what it is called, and the two words the company grades it by. So it is
     * answered from the directory rather than typed — a profile whose first
     * section is typed is a profile that disagrees with the structure a week
     * later — and it travels as the fields themselves rather than as a built
     * table, so the page draws the document's own table and nothing has to read
     * markup to find a value.
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
     * field somebody fills in, the way the category and the grade did. The page
     * draws them empty meanwhile, because the table has those rows whatever we
     * can say in them.
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
            // «КАТЕГОРИЯ» and «ГРАЙД»: the company's own words for what a
            // position is worth, which nothing can work out and whoever keeps
            // the positions types.
            'category' => $position->category,
            'grade' => $position->grade,
            // «ПОДЧИНЕНИЕ» and «ПОДЧИНЕННЫЕ», for which see above: the
            // application has no hierarchy of positions to read them from.
            'reports_to' => null,
            'subordinates' => [],
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
