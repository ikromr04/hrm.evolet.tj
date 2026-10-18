<?php

namespace App\Http\Controllers;

use App\Models\Position;
use App\Models\RegulationSection;
use App\Support\Document;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Writing «Положение о работе» as one position keeps it.
 *
 * A job has one of its own now. It used to read the regulation of the
 * department it was filed in, read-only, on the grounds that the document
 * describes a подразделение and a second copy per position would be two texts
 * that must agree and will not; the company asked for the other thing — «у
 * должности свои Положение и СФД» — so the page that reads it is the page that
 * writes it, exactly as that page already kept its «Профиль должности».
 *
 * One template still, and it is the department's. The standard text is a
 * section owned by nobody, so a position reads the company's wording until it
 * writes its own, and a correction the company makes to that wording reaches
 * every owner that has not. What a position does not get from it is the
 * sentences: they are written about a подразделение, and a job filed in a unit
 * reads them about that unit until somebody rewrites them here. That is the
 * company's text to change, not ours.
 *
 * There is no template address here. The standard text of this document is
 * written at /departments/regulation, because there is one of it; this serves
 * one position's own wording and nothing else.
 *
 * The document is one text, and the whole of it is in that text: the name of
 * the sheet is its first line and its parts are headings after it. So a
 * position rewrites that text and puts the standard one back; it adds no
 * section and takes none out.
 *
 * Keeping the regulation is keeping the position, so it is guarded by
 * directories.edit.positions through the directories.manage.positions gate —
 * the right that writes the list of job titles, and not the one that keeps the
 * departments. Reading it takes directories.view.positions, the right that
 * opens that list, because the document is read on a position's own page.
 */
class PositionRegulationController extends DocumentController
{
    protected function document(): string
    {
        return Document::REGULATION;
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
            'added' => 'Положение — один текст: разделы в него не добавляются.',
            'taken_out' => 'Положение — один текст: его нельзя удалить, можно только вернуть стандартный.',
            'arranged' => 'Нужно перечислить все разделы положения, каждый по одному разу.',
            'overridden' => 'Стандартный текст переопределяет должность, а не сам шаблон.',
        ];
    }

    public function store(Request $request, Position $position): RedirectResponse
    {
        return $this->writeSection($request, $position);
    }

    public function update(Request $request, Position $position, ?RegulationSection $section = null): RedirectResponse
    {
        return $this->rewriteSection($request, $position, $section);
    }

    public function destroy(Position $position, ?RegulationSection $section = null): RedirectResponse
    {
        return $this->dropSection($position, $section);
    }

    public function order(Request $request, Position $position): RedirectResponse
    {
        return $this->arrange($request, $position);
    }
}
