<?php

namespace App\Http\Controllers;

use App\Models\Equipment;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Transfer, fire, restore and delete employees.
 *
 * Transferred and fired people keep their record and move to their own
 * lists; deleting removes the person and all their data for good, and puts
 * whatever equipment they held back on the balance sheet.
 */
class EmployeeStatusController extends Controller
{
    public function transfer(Request $request, User $employee): RedirectResponse
    {
        return $this->leave($request, $employee, 'transferred', noteRequired: true) ?? back();
    }

    public function fire(Request $request, User $employee): RedirectResponse
    {
        return $this->leave($request, $employee, 'fired', noteRequired: false) ?? back();
    }

    public function restore(User $employee): RedirectResponse
    {
        $employee->update(['status' => 'active', 'status_changed_at' => null, 'status_note' => null]);

        return back();
    }

    public function destroy(Request $request, User $employee): RedirectResponse
    {
        abort_if($request->user()->is($employee), 403, 'Нельзя удалить самого себя.');

        // Deleted from the list, going back keeps the filters; deleted from the
        // employee's own profile, there is no page to go back to.
        $fromProfile = url()->previous() === route('employees.show', $employee);

        DB::transaction(function () use ($employee) {
            // What they hold goes back on the books first. Left to the foreign
            // key, a unit would stay "issued" to nobody: off the shelf, yet
            // with no one to ask for it. Nobody looked these units over, so the
            // day they were last checked and their state stay as they were.
            // The note says why the journal shows a return nobody brought in,
            // and carries the name because the holder in the entry's diff has
            // nobody behind it once the row is gone.
            $note = "Сотрудник {$employee->surname} {$employee->name} удалён из системы";

            Equipment::query()->where('holder_user_id', $employee->id)->each(function (Equipment $unit) use ($note) {
                $unit->journalNote = $note;
                // Nobody is left to be told that the unit was taken from them.
                $unit->unannounced = true;
                $unit->putOnBalance();
            });

            // What the bell held for them goes too: the table hangs on the
            // person by a pair of columns rather than a foreign key, so
            // nothing would clear it for us.
            $employee->notifications()->delete();

            // Details, children, roles, positions and departments go with the row.
            $employee->delete();
        });

        return $fromProfile ? to_route('employees.index') : back();
    }

    /**
     * Hands back the way to go rather than a status when there is nothing left
     * to change: what asks is a dialog, and it closes on a page of ours and
     * stays open, silent, on anything else. Nothing is written in that case.
     */
    private function leave(Request $request, User $employee, string $status, bool $noteRequired): ?RedirectResponse
    {
        abort_if($request->user()->is($employee), 403, 'Нельзя изменить статус самому себе.');

        // The list was open while they had already left.
        if (! $employee->isActive()) {
            return back()->with('notice', 'Сотрудник уже не работает.');
        }

        $data = $request->validate([
            'date' => ['required', 'date'],
            'note' => [$noteRequired ? 'required' : 'nullable', 'string', 'max:255'],
        ], attributes: [
            'date' => 'дата',
            'note' => $status === 'transferred' ? 'куда переведён' : 'причина',
        ]);

        $employee->update([
            'status' => $status,
            'status_changed_at' => $data['date'],
            'status_note' => $data['note'] ?? null,
        ]);

        return null;
    }
}
