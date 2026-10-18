<?php

namespace App\Http\Controllers;

use App\Models\Position;
use App\Models\RegulationSection;
use App\Support\Document;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Writing «Профиль должности», the sheet the company calls СФД: the shared
 * standard text, and what one position keeps of its own.
 *
 * The same controller does both, because they are the same document seen from
 * two addresses. /positions/profile is the profile of no position — the
 * template, the nine standard sections every profile is made of, and the only
 * address those sections are added to or dropped from — and
 * /positions/{position}/profile is one position's: its wording of the standard
 * sections somebody rewrote for it, its arrangement of them and the heading it
 * is printed under.
 *
 * The document is one text, and the whole of it is in that text. The company
 * writes this sheet as one file and asked for one editor, so the name of the
 * sheet is its first line and its parts — ДОЛЖНОСТНЫЕ ОБЯЗАННОСТИ through
 * ОЗНАКОМЛЕНИЕ, as the document's own contents page lists them — are headings
 * after it. In the machinery below it is a document of one section with no
 * heading kept apart from it: a position rewrites that text and puts the
 * standard one back; it does not add a section of its own and does not take one
 * out, because then the sheet printed for one job would no longer be the sheet
 * the company wrote.
 *
 * «01 ОБЩАЯ ИНФОРМАЦИЯ» is not part of it at all. It was never text — it is
 * where the position sits and what it is called, read from the directory — and
 * it is drawn in the column beside the document now, where the page was already
 * saying most of it.
 *
 * Keeping the profile is keeping the position, so it is guarded by
 * directories.edit.positions through the directories.manage.positions gate —
 * the right that writes the list of job titles. Reading it takes
 * directories.view.positions, the right that opens that list, because the
 * profile is read on a position's own page.
 */
class PositionProfileController extends DocumentController
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
            'added' => 'Профиль должности — один текст: разделы в него не добавляются.',
            'taken_out' => 'Профиль должности — один текст: его нельзя удалить, можно только вернуть стандартный.',
            'arranged' => 'Нужно перечислить все разделы профиля должности, каждый по одному разу.',
            'overridden' => 'Стандартный текст переопределяет должность, а не сам шаблон.',
        ];
    }

    public function store(Request $request, ?Position $position = null): RedirectResponse
    {
        return $this->writeSection($request, $position);
    }

    public function update(Request $request, ?Position $position = null, ?RegulationSection $section = null): RedirectResponse
    {
        return $this->rewriteSection($request, $position, $section);
    }

    public function destroy(?Position $position = null, ?RegulationSection $section = null): RedirectResponse
    {
        return $this->dropSection($position, $section);
    }

    public function order(Request $request, ?Position $position = null): RedirectResponse
    {
        return $this->arrange($request, $position);
    }
}
