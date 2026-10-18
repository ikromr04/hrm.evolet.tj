<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\RegulationSection;
use App\Support\Document;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Writing «СФД» as a department keeps it: one department's wording of the nine
 * standard sections, its arrangement of them and the heading it prints them
 * under.
 *
 * The same document a position carries, and the same one text: the company
 * writes one profile and the tab of a department holds it, rather than the list
 * of the jobs under it that stood there before. So there is no template address
 * here. The standard text is one text for both kinds of owner and is written
 * where it has always been written — /positions/profile — and a department that
 * rewrites a section rewrites it for itself, which is what this address is for.
 *
 * Everything about how a document is kept is in DocumentController, word for
 * word the same as for the regulation beside it and for the position's profile:
 * this says only which document it writes, whose it is, and in what words it
 * refuses.
 *
 * Keeping it is keeping the department, so it is guarded by
 * directories.edit.departments through the directories.manage.departments gate
 * — the same right that writes the department's «Положение о работе», and not
 * the positions right, because what is being written here is the department's
 * own sheet. Reading needs nothing: a department's page is open to everybody
 * signed in, and so are both documents on it.
 */
class DepartmentProfileController extends DocumentController
{
    protected function document(): string
    {
        return Document::PROFILE;
    }

    /**
     * No column and no field: the sheet is named in the first line of its own
     * text, so there is nothing kept apart from the document and nothing to
     * refuse a heading by.
     */
    protected function titleColumn(): ?string
    {
        return null;
    }

    protected function heading(): ?string
    {
        return null;
    }

    /**
     * @return array{added: string, taken_out: string, arranged: string, overridden: string}
     */
    protected function refusals(): array
    {
        return [
            'added' => 'Профиль подразделения — один текст: разделы в него не добавляются.',
            'taken_out' => 'Профиль подразделения — один текст: его нельзя удалить, можно только вернуть стандартный.',
            'arranged' => 'Нужно перечислить все разделы профиля подразделения, каждый по одному разу.',
            'overridden' => 'Стандартный текст переопределяет подразделение, а не сам шаблон.',
        ];
    }

    public function store(Request $request, Department $department): RedirectResponse
    {
        return $this->writeSection($request, $department);
    }

    public function update(Request $request, Department $department, ?RegulationSection $section = null): RedirectResponse
    {
        return $this->rewriteSection($request, $department, $section);
    }

    public function destroy(Department $department, ?RegulationSection $section = null): RedirectResponse
    {
        return $this->dropSection($department, $section);
    }

    public function order(Request $request, Department $department): RedirectResponse
    {
        return $this->arrange($request, $department);
    }
}
