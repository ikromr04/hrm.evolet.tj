<?php

namespace App\Support;

use App\Models\Equipment;

/**
 * How a unit is named wherever it is named: a row of the fleet, a cell of the
 * table by colleague, a line of the journal, a result of the search, a tile of
 * the dashboard, a letter about a handover.
 *
 * What a unit is called and the number on its sticker are fields of its
 * category, and a category is free to keep neither — a box of patch cords has
 * no name of its own and nothing to put a number on. So the words are put
 * together here rather than at each of those places: a unit with no name is
 * said to have none, in one wording, from one constant, instead of leaving a
 * row blank or a sentence naming nothing at all.
 *
 * What a page is given is therefore ready to be read. The one exception is the
 * unit's own card: the forms of a card open on what the card was given, and a
 * form that opened on these words would save them as the unit's name.
 */
class EquipmentName
{
    /** What stands where a name would be when the unit has none. */
    public const NAMELESS = 'Без наименования';

    /** And where a number would be: the empty value of every table here. */
    public const NO_NUMBER = '—';

    /** What the unit is called, in as many words where it is called nothing. */
    public static function read(?string $name): string
    {
        return $name === null || $name === '' ? self::NAMELESS : $name;
    }

    /** The number on its sticker, where it has one to carry. */
    public static function readNumber(?string $number): string
    {
        return $number === null || $number === '' ? self::NO_NUMBER : $number;
    }

    /**
     * The unit as a sentence names it: what it is called and the number on its
     * sticker — and only what it is called where there is no number, because a
     * letter is a sentence and "инв. № —" names nothing.
     */
    public static function sentence(Equipment $unit): string
    {
        $parts = [self::read($unit->name)];

        $number = $unit->inventory_number;

        if ($number !== null && $number !== '') {
            $parts[] = "инв. № {$number}";
        }

        return implode(', ', $parts);
    }
}
