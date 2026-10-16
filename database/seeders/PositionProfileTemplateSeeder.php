<?php

namespace Database\Seeders;

use App\Models\RegulationSection;
use App\Support\Document;
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
     * The nine sections, written while there are none.
     *
     * Written once and then the company's own: every later run leaves the
     * template exactly as it stands, because the headings are theirs to correct
     * and the bodies theirs to write, and a seeder that put its own version back
     * would undo that work on every update. A section they dropped stays
     * dropped for the same reason.
     */
    public function run(): void
    {
        $template = RegulationSection::where('document', Document::PROFILE)->whereNull('position_id');

        if ($template->exists()) {
            return;
        }

        DB::transaction(function () {
            foreach (self::SECTIONS as $place => $title) {
                RegulationSection::create([
                    'document' => Document::PROFILE,
                    'title' => $title,
                    // Nothing under any of them: the text of a profile is the
                    // company's to write, and «01 ОБЩАЯ ИНФОРМАЦИЯ» has no text
                    // to write at all.
                    'body' => null,
                    'computed' => $title === self::FROM_DATA,
                    // A hundred apart, which leaves room between any two
                    // standard sections for one written later.
                    'order' => ($place + 1) * 100,
                ]);
            }
        });
    }
}
