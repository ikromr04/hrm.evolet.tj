<?php

namespace App\Notifications;

use App\Models\Equipment;

/**
 * The day a unit was to be counted again has come. Told to whoever keeps the
 * inventory dates of that unit, once per unit and date.
 */
class InventoryDue extends InAppNotification
{
    public function __construct(private readonly Equipment $unit)
    {
        // The reminder names the unit, and what a unit is called and the number
        // on its sticker are fields of its category: the command that walks the
        // due dates has no other reason to load them, so the letter does.
        $this->unit->loadMissing('fieldValues.field:id,role');
    }

    /**
     * What makes one reminder the same as another: the unit and the date it
     * was due. Moving the date on makes it a new reminder; running the command
     * again does not.
     */
    public static function reminderKey(Equipment $unit): string
    {
        return "{$unit->id}:{$unit->next_inventory_at->toDateString()}";
    }

    protected function kind(): string
    {
        return 'equipment.inventory';
    }

    protected function text(): string
    {
        return "Подошёл срок инвентаризации: {$this->unit->name}, инв. № {$this->unit->inventory_number}";
    }

    protected function target(): array
    {
        return ['type' => 'equipment', 'id' => $this->unit->id];
    }

    protected function extra(): array
    {
        return [
            'reminder' => self::reminderKey($this->unit),
            // The date itself rather than a word for it: the list writes dates
            // its own way, and a reminder read a week late should still say
            // which day it was about.
            'due' => $this->unit->next_inventory_at->toDateString(),
        ];
    }
}
