<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateSheetRequest;
use App\Models\User;
use App\Notifications\SheetFillingRequested;
use App\Support\Access;
use App\Support\DocumentStore;
use App\Support\SheetWriter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * «Личный листок по учёту кадров», saved in one go.
 *
 * The card's own windows each keep a block of it, and every one of them is
 * still there; this is the sheet read as the paper it is. Eleven blocks are
 * retyped together and written together — one transaction, so either all of it
 * is on file or none of it is.
 *
 * This is the door for whoever may change the lines: they press «Сохранить» and
 * the card is written. The two roles that are shown their own card come through
 * SheetChangeRequestController instead — they press «Отправить», nothing is
 * written, and the same words reach the card only when personnel accept the
 * request. The writing itself is App\Support\SheetWriter's, shared by both
 * doors, because a second copy of it would be the copy that stops matching.
 */
class EmployeeSheetController extends Controller
{
    /**
     * The sheet retyped.
     *
     * The documents the save stops pointing at are taken off the disk after the
     * transaction has closed and not inside it: a save that fails leaves the
     * card with the papers it still names, and a file removed inside a
     * transaction is gone whether or not the rows come back.
     */
    public function update(UpdateSheetRequest $request, User $employee): RedirectResponse
    {
        $data = $request->validated();

        $replaced = DB::transaction(fn () => SheetWriter::write($employee, $data));

        foreach ($replaced as $path) {
            DocumentStore::forget($path);
        }

        return back();
    }

    /**
     * The colleague asked to finish their own sheet.
     *
     * The one thing to be done about a list of lines nobody has answered, and
     * the reason that list stands beside the sheet at all. Pressed by whoever
     * is reading the card; read by the person whose card it is, as a note with
     * the missing lines named in it.
     *
     * What is short is not named in the note. The sheet says it itself, in the
     * two lists standing beside it, and a note carrying a copy of them would be
     * a copy out of date the moment one line was answered.
     *
     * Not offered to the two roles that are shown their own card: the only card
     * they can reach is theirs, and asking oneself to fill in one's own sheet
     * is a note from nobody. Nor to anybody about themselves, for the same
     * reason — personnel have their own sheets too.
     *
     * Back with nothing said. Nothing on the card changes when a note is sent —
     * the lines are still short and the list still says so — so the press is
     * answered where it was made, by a toast the page raises itself, rather than
     * by a sentence flashed to the top of a card somebody is reading the foot
     * of.
     */
    public function ask(Request $request, User $employee): RedirectResponse
    {
        $asker = $request->user();

        abort_if(Access::ownCardOnly($asker) || $asker->is($employee), 403);
        abort_unless($asker->can('viewPrivateDetails', $employee), 403);

        $employee->notify(new SheetFillingRequested($asker));

        return back();
    }
}
