<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\KeepsEquipmentPhotos;
use App\Models\Equipment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * A unit's life: handed out, taken back, written off. Each
 * move sets the status and the holder together, so the two never disagree, and
 * writes the spell it ends into the unit's history.
 */
class EquipmentStatusController extends Controller
{
    use KeepsEquipmentPhotos;

    /**
     * Handed over to whoever answers for it: one colleague by name, or one
     * department. A laptop goes to the person who works on it; a printer in a
     * corridor, a router in the ceiling and a spare nobody has been given yet
     * go to the department, which answers for them with no one to sign.
     *
     * Nothing about the thing decides which of the two it is — the same model
     * of laptop can be either — so the move asks, and the answer is this one
     * handover's. One of the two is required and the other must then be empty:
     * a unit stands against one name, and the database refuses a row that
     * stands against two.
     */
    public function issue(Request $request, Equipment $equipment): RedirectResponse
    {
        $data = $request->validate([
            // Nullable and required at once is no contradiction: the form sends
            // the answer it did not use as an empty field, and a null is read
            // as "not answered" rather than refused for not being a number —
            // which is what `required_without` then objects to when neither
            // was answered.
            'holder_user_id' => [
                'nullable',
                'required_without:holder_department_id',
                'prohibits:holder_department_id',
                'integer',
                Rule::exists('users', 'id'),
            ],
            // Required of itself as well, so that whichever side of the choice
            // the form is showing, the complaint lands on the box in front of
            // whoever is reading it rather than on the one they cannot see.
            'holder_department_id' => ['nullable', 'required_without:holder_user_id', 'integer', Rule::exists('departments', 'id')],
            'issued_at' => ['required', 'date', 'before_or_equal:today'],
            // In what state it went out, so a return has something to be
            // compared against. The form opens on what the card says now.
            'condition' => ['nullable', 'string', 'max:200'],
            ...$this->photoRules(),
        ], messages: [
            ...$this->photoMessages(),
            // Said as the question was asked, and the same either way round:
            // the framework's own wording would name the box the form is not
            // showing, which reads as a complaint about something invisible.
            'holder_user_id.required_without' => 'Укажите, кому выдаётся техника: сотруднику или отделу.',
            'holder_department_id.required_without' => 'Укажите, кому выдаётся техника: сотруднику или отделу.',
            'holder_user_id.prohibits' => 'У единицы один владелец: либо сотрудник, либо отдел.',
        ], attributes: [
            'holder_user_id' => 'сотрудник',
            'holder_department_id' => 'отдел',
            'issued_at' => 'дата выдачи',
            'condition' => 'состояние',
            'photos' => 'фотографии',
        ]);

        if ($notice = $this->stillInService($equipment)) {
            return $notice;
        }

        $before = (int) $equipment->events()->max('id');

        $equipment->update([
            'status' => 'issued',
            // One holder and the other empty, always both written: a unit
            // handed from a person to a department, or the other way about,
            // leaves nothing standing behind it — and the database would
            // refuse the row if it did.
            'holder_user_id' => $data['holder_user_id'] ?? null,
            'holder_department_id' => $data['holder_department_id'] ?? null,
            'issued_at' => $data['issued_at'],
            'condition' => $data['condition'] ?? $equipment->condition,
        ]);

        $this->keepPhotos($request, $equipment, $before, 'issued');

        return back();
    }

    /**
     * Back on the balance sheet: nobody holds it any more. Whoever brought it
     * back may say what state it is in, which goes to the card and the journal.
     */
    public function take(Request $request, Equipment $equipment): RedirectResponse
    {
        $data = $request->validate([
            'condition_on_return' => ['nullable', 'string', 'max:200'],
            // The day it was handed back, which is the day it was last seen.
            'returned_at' => ['required', 'date', 'before_or_equal:today'],
            ...$this->photoRules(),
        ], messages: $this->photoMessages(), attributes: [
            'condition_on_return' => 'состояние при возврате',
            'returned_at' => 'дата возврата',
            'photos' => 'фотографии',
        ]);

        if ($notice = $this->stillInService($equipment)) {
            return $notice;
        }

        $before = (int) $equipment->events()->max('id');

        // One save, so the journal reads the return as one act rather than as a
        // move followed by a correction. Somebody looked the thing over as it
        // came back, so that day is when it was last checked, whether or not
        // they had anything to say about its state.
        $equipment->putOnBalance([
            'checked_at' => $data['returned_at'],
            ...($data['condition_on_return'] ?? null) === null ? [] : ['condition' => $data['condition_on_return']],
        ]);

        $this->keepPhotos($request, $equipment, $before, 'stocked');

        return back();
    }

    /**
     * Out of the fleet for good.
     */
    public function writeOff(Request $request, Equipment $equipment): RedirectResponse
    {
        $data = $request->validate([
            'written_off_at' => ['required', 'date', 'before_or_equal:today'],
            // Why it is going: the form opens on what the card says now, and
            // whoever strikes it off says what state it is in at the end.
            'condition' => ['nullable', 'string', 'max:200'],
            ...$this->photoRules(),
        ], messages: $this->photoMessages(), attributes: [
            'written_off_at' => 'дата списания',
            'condition' => 'состояние',
            'photos' => 'фотографии',
        ]);

        if ($notice = $this->stillInService($equipment)) {
            return $notice;
        }

        $before = (int) $equipment->events()->max('id');

        $equipment->update([
            'status' => 'written_off',
            // Whoever held it lets go, person or department alike.
            'holder_user_id' => null,
            'holder_department_id' => null,
            'issued_at' => null,
            'written_off_at' => $data['written_off_at'],
            'condition' => $data['condition'] ?? $equipment->condition,
        ]);

        $this->keepPhotos($request, $equipment, $before, 'written_off');

        return back();
    }

    /**
     * A written-off unit is gone: it is not handed out and not taken back. The
     * answer is the way back with a sentence rather than a refusal, because what
     * asks is a dialog: it closes on a page of ours and stays open, silent, on
     * anything else. Nothing has been written by the time this is asked.
     */
    private function stillInService(Equipment $equipment): ?RedirectResponse
    {
        return $equipment->status === 'written_off'
            ? back()->with('notice', 'Списанное оборудование нельзя перемещать.')
            : null;
    }
}
