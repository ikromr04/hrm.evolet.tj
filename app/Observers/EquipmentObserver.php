<?php

namespace App\Observers;

use App\Models\Equipment;
use App\Models\EquipmentEvent;
use App\Models\User;
use App\Notifications\EquipmentMoved;
use Illuminate\Support\Facades\Auth;

/**
 * Writes a unit's life into the journal as it happens. Every controller that
 * moves a unit does it by saving the row, so listening to the row catches all
 * of them — and catches whatever is added later without being told about it.
 */
class EquipmentObserver
{
    /** Changes worth nobody's attention: they follow from the rest. */
    private const IGNORED = ['updated_at', 'created_at'];

    /** The "Состояние" block of the card, edited together and named together. */
    private const STATE = ['condition', 'checked_at', 'next_inventory_at'];

    /**
     * The "Сейчас у сотрудника" block. A change of holder here is a handover
     * like any other, whatever form it was made in, and not the plain
     * correction the journal would otherwise call it.
     */
    private const HANDOVER = ['holder_user_id', 'issued_at'];

    /**
     * A unit joining the fleet. It lands on the balance sheet like a unit coming
     * back does, but the journal keeps the two apart: one is the day the company
     * bought the thing, the other is a Tuesday when somebody returned it.
     */
    public function created(Equipment $equipment): void
    {
        $equipment->events()->create([
            'user_id' => Auth::id(),
            'kind' => 'created',
            'note' => self::arrival($equipment),
        ]);
    }

    /**
     * Tell the arrival entry the number the unit was filed under. The number is
     * a field of the unit's category now, and a field cannot be written before
     * the row it hangs on exists — so whoever puts a unit on the books writes
     * the two it is named by and then says so here, and the line itself stays
     * where every other line is written.
     */
    public static function named(Equipment $equipment): void
    {
        $equipment->events()
            ->where('kind', 'created')
            ->latest('id')
            ->first()
            ?->update(['note' => self::arrival($equipment)]);
    }

    /** What the arrival is remembered by: the number on the unit's sticker. */
    private static function arrival(Equipment $equipment): ?string
    {
        $number = $equipment->inventory_number;

        return $number === null || $number === '' ? null : "Инв. № {$number}";
    }

    public function updated(Equipment $equipment): void
    {
        $this->announce($equipment);

        // Both sides are read through the casts, so a date is a date and a
        // list is a list on either side of the arrow. What `getChanges` holds
        // is on its way to the database — for the accessories that is raw
        // JSON, which nobody wants to read.
        $own = EquipmentEvent::diffOf($equipment, self::IGNORED);
        // What the category's own fields did travels with the same entry.
        $changes = [...$own, ...$equipment->journalExtra];

        if ($changes === []) {
            return;
        }

        // A move is named by where it went. A correction is named by the block
        // of the card it was made in, so the journal reads as the card does;
        // a save that spans blocks is just a change. Only the row's own fields
        // name an operation: a category field moving is a plain change.
        $fields = array_keys($own);

        $kind = match (true) {
            array_key_exists('status', $changes) => match ($equipment->status) {
                'issued' => 'issued',
                'stock' => 'stocked',
                default => 'written_off',
            },
            $fields !== [] && array_diff($fields, self::STATE) === [] => 'condition',
            // Handing a unit to somebody else is a handover like any other, so
            // it is recorded as one. A date on its own is not: that is a
            // correction to the date, and it stays a plain change.
            array_key_exists('holder_user_id', $changes) && array_diff($fields, self::HANDOVER) === [] => 'issued',
            $fields === ['accessories'] => 'accessories',
            default => 'updated',
        };

        $equipment->events()->create([
            'user_id' => Auth::id(),
            'kind' => $kind,
            'diff' => $changes,
            'note' => $equipment->journalNote,
        ]);

        // Said once, for the save that asked for it.
        $equipment->journalNote = null;
        $equipment->journalExtra = [];
    }

    /**
     * Tell the people a unit moved between: whoever it left and whoever it came
     * to. It is done here for the reason the journal is written here — every
     * way of moving a unit saves the row, so none of them can forget to.
     *
     * Nobody is told about what they did themselves, and nothing is said when
     * there is no person behind the move: a seeder filling the fleet in is not
     * news to the people it hands laptops to.
     */
    private function announce(Equipment $equipment): void
    {
        $quiet = $equipment->unannounced;
        // Asked for one save, like the note.
        $equipment->unannounced = false;

        $actor = Auth::user();

        if ($quiet || $actor === null || ! $equipment->wasChanged('holder_user_id')) {
            return;
        }

        // The unit still remembers who had it: the original is only brought up
        // to date once the save is over.
        $former = User::find($equipment->getOriginal('holder_user_id'));
        $holder = User::find($equipment->holder_user_id);

        // Somebody who no longer works here cannot sign in to read it.
        if ($former?->isActive() && ! $former->is($actor)) {
            $former->notify(new EquipmentMoved($equipment, $equipment->status === 'written_off' ? 'written_off' : 'taken'));
        }

        if ($holder?->isActive() && ! $holder->is($actor)) {
            $holder->notify(new EquipmentMoved($equipment, 'issued'));
        }
    }
}
