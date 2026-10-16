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
 * Nine sections, and nine is the whole of it: «01 ОБЩАЯ ИНФОРМАЦИЯ» through
 * «09 ОЗНАКОМЛЕНИЕ» as the document's own contents page lists them. A position
 * rewrites one of them and puts the standard text back; it does not add a
 * section of its own and does not take one out, because then the sheet printed
 * for one job would no longer be the sheet the company wrote.
 *
 * The first section is the one nobody writes: it is a table of where the
 * position sits and what it is called, read from the directory. A body typed
 * into it would be a second answer to the same question, so it is refused — see
 * DocumentController, which holds every rule the two documents share.
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

    protected function titleColumn(): string
    {
        return 'profile_title';
    }

    protected function heading(): string
    {
        return 'заголовок профиля должности';
    }

    /**
     * @return array{added: string, taken_out: string, arranged: string, overridden: string, too_long: string}
     */
    protected function refusals(): array
    {
        return [
            'added' => 'Профиль должности состоит из девяти стандартных разделов — новые разделы в него не добавляются.',
            'taken_out' => 'Профиль должности состоит из девяти стандартных разделов: раздел нельзя удалить, можно только вернуть его стандартный текст.',
            'arranged' => 'Нужно перечислить все разделы профиля должности, каждый по одному разу.',
            'overridden' => 'Стандартный текст переопределяет должность, а не сам шаблон.',
            'too_long' => 'Заголовок профиля должности не должен быть длиннее '.Document::TITLE_LENGTH.' символов.',
        ];
    }

    public function store(Request $request, ?Position $position = null): RedirectResponse
    {
        return $this->writeSection($request, $position);
    }

    public function title(Request $request, Position $position): RedirectResponse
    {
        return $this->nameDocument($request, $position);
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
