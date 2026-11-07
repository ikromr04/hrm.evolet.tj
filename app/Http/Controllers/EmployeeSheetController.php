<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateSheetRequest;
use App\Models\User;
use App\Support\DocumentStore;
use App\Support\SheetWriter;
use Illuminate\Http\RedirectResponse;
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
}
