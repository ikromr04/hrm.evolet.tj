<?php

namespace Database\Seeders;

use App\Models\RegulationSection;
use App\Support\Document;
use App\Support\Profile;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PositionProfileTemplateSeeder extends Seeder
{
    /**
     * «Профиль должности» as the company laid it out: the nine sections its own
     * contents page lists, in that order, and nothing inside them.
     *
     * The headings are the document — they are what the company decided a
     * profile of a job has to answer — and they are the whole of what can be
     * written down here. What belongs under «МЯГКИЕ НАВЫКИ» is different for
     * every position in the company, and a seeder that invented a paragraph of
     * it would be putting words the company never wrote into a sheet somebody
     * signs. So the sections are seeded empty, and whoever keeps a position
     * writes them: once in the template where they are the same for everybody,
     * or on the position's own page where they are not.
     *
     * The first section is the exception twice over: it has no body and never
     * gets one. «01 ОБЩАЯ ИНФОРМАЦИЯ» is a table of where the position sits and
     * what it is called, and both of those are already in the directory — so it
     * is read from there every time the sheet is opened, rather than typed once
     * and left to go stale the week somebody is moved.
     *
     * @var list<string>
     */
    public const SECTIONS = [
        'ОБЩАЯ ИНФОРМАЦИЯ',
        'ДОЛЖНОСТНЫЕ ОБЯЗАННОСТИ',
        'ОБРАЗОВАНИЕ И ОПЫТ РАБОТЫ',
        'ЗНАНИЕ ЯЗЫКОВ',
        'ЖЕСТКИЕ НАВЫКИ',
        'МЯГКИЕ НАВЫКИ',
        'КРИТЕРИИ ОЦЕНКИ ЭФФЕКТИВНОСТИ РАБОТЫ',
        'ОТВЕТСТВЕННОСТЬ ЗА ДОКУМЕНТЫ ФАЙЛЫ',
        'ОЗНАКОМЛЕНИЕ',
    ];

    /**
     * The one section the application fills in. Named rather than counted: the
     * company may put it second tomorrow, and it would still be the section
     * drawn from data.
     */
    public const FROM_DATA = 'ОБЩАЯ ИНФОРМАЦИЯ';

    /**
     * The document, written while there is none.
     *
     * Written once and then the company's own: every later run leaves the
     * template exactly as it stands, because the headings are theirs to correct
     * and the text theirs to write, and a seeder that put its own version back
     * would undo that work on every update. A part of it they dropped stays
     * dropped for the same reason.
     *
     * One row, because the document is one text. Its first line is the name of
     * the sheet, written with the one placeholder this document has — both
     * kinds of owner read the same template and each has to read its own
     * opening there — and the parts after it are the headings the company's
     * contents page lists, with nothing under any of them.
     *
     * «01 ОБЩАЯ ИНФОРМАЦИЯ» is not among them any more. It was never text: it
     * is where the thing sits, who leads it and how many work there, and all of
     * that is in the column beside the document now, read from the directory
     * every time the page is opened.
     */
    public function run(): void
    {
        $template = RegulationSection::where('document', Document::PROFILE)
            ->whereNull('department_id')
            ->whereNull('position_id');

        if ($template->exists()) {
            return;
        }

        DB::transaction(fn () => RegulationSection::create([
            'document' => Document::PROFILE,
            // What the one section is called, which nothing draws: the document
            // names itself in its own first line. A job's sheet and a unit's
            // are one template, so the row is called after the document the
            // company lists it as.
            'title' => Profile::TITLE,
            'body' => self::body(),
            'computed' => false,
            'order' => 100,
        ]));
    }

    /**
     * The document as the editor would have written it: the name of the sheet,
     * then a heading for each part the company's contents page lists.
     *
     * Nothing under any of them. What belongs under «МЯГКИЕ НАВЫКИ» is
     * different for every position in the company, and a seeder that invented a
     * paragraph of it would be putting words the company never wrote into a
     * sheet somebody signs.
     *
     * Through the same sanitizer every body written on the page goes through,
     * so the standard text is on file in exactly the shape somebody typing it
     * would have left.
     */
    private static function body(): string
    {
        $parts = ['<h2>{заголовок}</h2>'];

        foreach (self::written() as $place => $title) {
            $parts[] = '<h2>'.str_pad((string) ($place + 1), 2, '0', STR_PAD_LEFT).' '.$title.'</h2>';
        }

        return (string) Document::sanitize(implode('', $parts));
    }

    /**
     * The parts of the document that are written: every heading the company
     * lists except the one that was never text.
     *
     * @return list<string>
     */
    public static function written(): array
    {
        return array_values(array_filter(self::SECTIONS, fn (string $title) => $title !== self::FROM_DATA));
    }
}
