<?php

namespace App\Notifications;

use App\Models\Equipment;
use App\Support\EquipmentName;

/**
 * A unit came to somebody or left them, by somebody else's hand: handed over,
 * taken back, or written off while it was still on them. The person answers
 * for the unit by name, so they are told when that starts and when it ends.
 */
class EquipmentMoved extends InAppNotification
{
    /** What each move is called from the side of the person it happened to. */
    private const SENTENCES = [
        'issued' => 'Вам выдано',
        'taken' => 'У вас принято',
        'written_off' => 'У вас списано',
    ];

    /**
     * @param  'issued'|'taken'|'written_off'  $move
     */
    public function __construct(private readonly Equipment $unit, private readonly string $move)
    {
        // The sentence names the unit, and what a unit is called and the number
        // on its sticker are fields of its category: whoever hands a unit over
        // may not have had a reason to load them, so the letter makes sure of
        // it before it writes the line. A category that keeps neither leaves
        // nothing to load, and the line is written without them.
        $this->unit->loadMissing('fieldValues.field:id,role');
    }

    protected function kind(): string
    {
        return "equipment.{$this->move}";
    }

    protected function text(): string
    {
        return self::SENTENCES[$this->move].': '.EquipmentName::sentence($this->unit);
    }

    protected function target(): array
    {
        return ['type' => 'equipment', 'id' => $this->unit->id];
    }
}
