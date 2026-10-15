<?php

namespace Database\Seeders;

use App\Models\RegulationSection;
use App\Support\Document;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RegulationTemplateSeeder extends Seeder
{
    /**
     * «Положение о работе» as the company wrote it, transcribed from the Word
     * file it was passed around in: the standard sections every department
     * reads, with the department's name left as a placeholder because one text
     * serves them all.
     *
     * This is the template — the sections that belong to no department. A
     * department that needs its own wording of one of them rewrites it on its
     * own page, which keeps a copy of its own and leaves the company's text
     * alone; everywhere nobody has done that, this is what is read. Which is
     * also why the text is written with {подразделение} in it rather than with
     * anybody's name: the same sentence has to read correctly on twenty-six
     * pages.
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
     * A section they dropped stays dropped for the same reason.
     */
    public function run(): void
    {
        // This document's own template, and not the other's: «Профиль
        // должности» is kept in the same table and its standard sections belong
        // to no department either, so without the document named here the
        // regulation would be read as already written the moment a profile was
        // seeded.
        if (RegulationSection::where('document', Document::REGULATION)->whereNull('department_id')->exists()) {
            return;
        }

        DB::transaction(function () {
            foreach (array_keys(self::SECTIONS) as $place => $title) {
                RegulationSection::create([
                    'department_id' => null,
                    'template_section_id' => null,
                    'title' => $title,
                    'body' => self::body(self::SECTIONS[$title]),
                    // A hundred apart, which leaves room between any two
                    // standard sections for a department's own.
                    'order' => ($place + 1) * 100,
                ]);
            }
        });
    }

    /**
     * The clauses as the editor would have written them: one numbered list to a
     * section, one paragraph to a clause.
     *
     * Through the same sanitizer every body written on the page goes through, so
     * the standard text is on file in exactly the shape somebody typing it would
     * have left — and the first person to correct a sentence in it is not handed
     * a document that quietly rewrites itself as they save.
     *
     * @param  list<string>  $clauses
     */
    private static function body(array $clauses): string
    {
        $lines = array_map(fn (string $clause) => "<li><p>{$clause}</p></li>", $clauses);

        return (string) Document::sanitize('<ol>'.implode('', $lines).'</ol>');
    }
}
