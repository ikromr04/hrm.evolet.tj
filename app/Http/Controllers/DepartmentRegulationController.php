<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\RegulationSection;
use App\Support\Document;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Writing «Положение о работе»: the shared standard text, and what one
 * department keeps of its own.
 *
 * The same controller does both, because they are the same document seen from
 * two addresses. /departments/regulation is the regulation of no department —
 * the template, the sentences every department reads, and the only address the
 * standard sections themselves are added to or dropped from — and
 * /departments/{department}/regulation is one department's: its wording of the
 * standard sections it rewrote. Which address a section is written at decides
 * who the change reaches, and that is the whole difference between correcting a
 * sentence for the company and correcting it for one department.
 *
 * The document is the standard sections and nothing else. A department rewrites
 * one of them and puts the standard text back; it does not add a section of its
 * own to the document and does not take a section out of it, because then the
 * sheet one department prints would no longer be the sheet the company wrote.
 *
 * All of which is true of «Профиль должности» too, word for word with the
 * position in the department's place, so the rules themselves live in
 * DocumentController and this says only which document it is writing, whose it
 * is, and in what words it refuses. Nothing here is a copy of anything there:
 * a correction to how a document is kept reaches both documents.
 *
 * Keeping the regulation is keeping the department, so both are guarded by
 * directories.edit.departments through the directories.manage.departments gate
 * — the right that writes the structure down in «Подразделения». Reading needs
 * nothing: a department's page is open to everybody signed in, and so is the
 * document on it.
 */
class DepartmentRegulationController extends DocumentController
{
    protected function document(): string
    {
        return Document::REGULATION;
    }

    protected function titleColumn(): string
    {
        return 'regulation_title';
    }

    protected function heading(): string
    {
        return 'заголовок положения';
    }

    /**
     * @return array{added: string, taken_out: string, arranged: string, overridden: string, too_long: string}
     */
    protected function refusals(): array
    {
        return [
            'added' => 'Положение состоит из четырёх стандартных разделов — новые разделы в него не добавляются.',
            'taken_out' => 'Положение состоит из четырёх стандартных разделов: раздел нельзя удалить, можно только вернуть его стандартный текст.',
            'arranged' => 'Нужно перечислить все разделы положения, каждый по одному разу.',
            'overridden' => 'Стандартный текст переопределяет отдел, а не сам шаблон.',
            'too_long' => 'Заголовок положения не должен быть длиннее '.Document::TITLE_LENGTH.' символов.',
        ];
    }

    public function store(Request $request, ?Department $department = null): RedirectResponse
    {
        return $this->writeSection($request, $department);
    }

    public function title(Request $request, Department $department): RedirectResponse
    {
        return $this->nameDocument($request, $department);
    }

    public function update(Request $request, ?Department $department = null, ?RegulationSection $section = null): RedirectResponse
    {
        return $this->rewriteSection($request, $department, $section);
    }

    public function destroy(?Department $department = null, ?RegulationSection $section = null): RedirectResponse
    {
        return $this->dropSection($department, $section);
    }

    public function order(Request $request, ?Department $department = null): RedirectResponse
    {
        return $this->arrange($request, $department);
    }
}
