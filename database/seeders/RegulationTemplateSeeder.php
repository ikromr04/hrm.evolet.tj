<?php

namespace Database\Seeders;

use App\Models\RegulationSection;
use App\Support\Document;
use App\Support\Regulation;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RegulationTemplateSeeder extends Seeder
{
    /**
     * «Положение о работе» as the company wrote it, transcribed from the Word
     * file it was passed around in: the parts of the document and the clauses
     * under each, with the department's name left as a placeholder because one
     * text serves them all.
     *
     * The document is one text, and these are the headings inside it rather
     * than sections of their own — the company writes it as one file and asked
     * for one editor, so the parts are parts of a body and whoever writes it
     * moves, renames and renumbers them like any other line of it. The numbers
     * are typed in once, here, and nothing counts them afterwards.
     *
     * This is the template — the text that belongs to no department. A
     * department that needs its own wording rewrites it on its own page, which
     * keeps a copy of its own and leaves the company's text alone; everywhere
     * nobody has done that, this is what is read. Which is also why the text is
     * written with {подразделение} in it rather than with anybody's name: the
     * same sentence has to read correctly on twenty-six pages.
     *
     * Only the numbered clauses of the document are here. Two of its parts are
     * left out on purpose: «ЗАДАЧИ И ЦЕННЫЙ КОНЕЧНЫЙ ПРОДУКТ», which is a
     * two-column table that was always empty in the file and is the one part no
     * template can fill in for a department, and «ОЗНАКОМЛЕНИЕ», which is the
     * date and the signature lines of the printed sheet and carries no text at
     * all.
     *
     * @var array<string, list<string>> heading => its clauses, in order
     */
    public const SECTIONS = [
        'ОБЩЕЕ ПОЛОЖЕНИЕ' => [
            '{подразделение} является самостоятельным структурным подразделением Представительства «Evolet Healthcare» в Республике Таджикистан (далее — Представительство).',
            '{подразделение} создается и ликвидируется приказом Главы Представительства.',
            '{подразделение} возглавляет руководитель, который назначается на должность приказом Главы Представительства.',
            '{подразделение} подчиняется в своей деятельности непосредственно Главе Представительства.',
            'Состав и структура подразделения «{подразделение}» определяется согласно штатному расписанию, утвержденного Главой Представительства.',
            'Работники подразделения «{подразделение}» принимаются на работу и переводятся на должности отдела приказом Главы Представительства по представлению руководителя Департамента Управления Человеческими Ресурсами.',
            'Свою деятельность {подразделение} осуществляет на основе: устава предприятия (учреждения, организации); настоящего Положения; действующего законодательства.',
        ],
        'ПРАВА' => [
            'Давать обязательные для исполнения указания руководителям и работникам структурных подразделений.',
            'Запрашивать и получать необходимую информацию и документы.',
            'Представлять Представительство в отношениях с другими предприятиями, учреждениями, организациями.',
            'Давать разъяснения, указания, рекомендации.',
            'Проводить совещания, либо участвовать на общих собраниях по вопросам, касающихся деятельности Представительства.',
            'Взаимодействовать с другими структурными подразделениями Представительства.',
        ],
        'ОБЯЗАННОСТИ' => [
            'Соблюдать Трудовое Законодательство и иные нормативные Правовые Акты, содержащие нормы Трудового Права, локальные Нормативные Акты, Соглашений и Трудовых Договоров.',
            'Своевременно выполнять приказы и распоряжения Главы Представительства.',
            'Соблюдать правила внутреннего трудового распорядка, трудовую дисциплину, требования по охране труда и обеспечению безопасности труда, выполнять установленные нормы труда.',
            'Бережно относиться к имуществу работодателя и других работников.',
            'Предоставлять информацию, необходимую работнику в связи с осуществлением им своей трудовой деятельности. Предоставлять информацию, необходимую Главе Представительства, руководителям других структурных подразделений по вопросам своей деятельности.',
            'Осуществлять свою деятельность в строгом соответствии с предписаниями настоящего Положения.',
        ],
        'ОТВЕТСТВЕННОСТЬ' => [
            'Ответственность за надлежащее и своевременное выполнение подразделением «{подразделение}» своих задач и функций, предусмотренных настоящим Положением, несет его руководитель в соответствии с трудовым Законодательством.',
            'Ответственность сотрудников подразделения «{подразделение}» устанавливается их должностными инструкциями, трудовыми договорами и Трудовым Законодательством.',
        ],
    ];

    /**
     * The standard text, written while there is none.
     *
     * It is written once and then belongs to the company: every later run leaves
     * it exactly as it stands, because the sentences are theirs to correct and a
     * seeder that put its own wording back would undo that work on every update.
     * A part of it they dropped stays dropped for the same reason.
     *
     * One row, because the document is one text. The four parts of it are
     * headings inside that text, so a department opens one editor and reads one
     * file, which is how the company works on it.
     */
    public function run(): void
    {
        // This document's own template, and nobody's: «Профиль должности» is
        // kept in the same table and its standard text belongs to no department
        // either, so without the document named here the regulation would be
        // read as already written the moment a profile was seeded — and both
        // columns are asked about, because a position keeps a regulation of its
        // own now and such a row names no department at all. Asking about one
        // column would read one job's own wording as the company's and seed
        // nothing.
        $template = RegulationSection::where('document', Document::REGULATION)
            ->whereNull('department_id')
            ->whereNull('position_id');

        if ($template->exists()) {
            return;
        }

        DB::transaction(fn () => RegulationSection::create([
            'department_id' => null,
            'template_section_id' => null,
            // What the one section is called, which nothing draws: the document
            // names itself in its own first line, and the parts of it have
            // their own headings after that. So the row is called after the
            // document it is the whole of.
            'title' => Regulation::TITLE,
            'body' => self::body(),
            'order' => 100,
        ]));
    }

    /**
     * The document as the editor would have written it: its own name at the
     * top, then each part under a heading of its own, numbered as the company
     * numbers them, and its clauses as one numbered list, one paragraph to a
     * clause.
     *
     * Through the same sanitizer every body written on the page goes through, so
     * the standard text is on file in exactly the shape somebody typing it would
     * have left — and the first person to correct a sentence in it is not handed
     * a document that quietly rewrites itself as they save.
     */
    private static function body(): string
    {
        // The name of the sheet, which is the first line of it the way a
        // printed document opens with its own name — written as the one
        // placeholder that stands for the whole opening line, because this text
        // is read by departments and by positions alike and each has to read
        // its own there.
        $parts = ['<h2>{заголовок}</h2>'];

        foreach (array_values(array_keys(self::SECTIONS)) as $place => $title) {
            $clauses = array_map(fn (string $clause) => "<li><p>{$clause}</p></li>", self::SECTIONS[$title]);

            $parts[] = '<h2>'.str_pad((string) ($place + 1), 2, '0', STR_PAD_LEFT).' '.$title.'</h2>'
                .'<ol>'.implode('', $clauses).'</ol>';
        }

        return (string) Document::sanitize(implode('', $parts));
    }
}
