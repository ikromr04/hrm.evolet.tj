<?php

namespace App\Models\Concerns;

/**
 * A short list a card points at with one entry: the kinds of work, the
 * branches. The card names the entry rather than numbering it, so one missing
 * from the list is written into it here — whoever fills a card in may also add
 * the line it needs, the way a country typed into the citizenships is added.
 */
trait KeptByName
{
    /**
     * The entry for this name, added to the list where it is missing. Matched
     * without regard to case, so "душанбе" finds "Душанбе" rather than putting
     * a second one on the list; nothing at all is no entry.
     */
    public static function idFor(?string $name): ?int
    {
        $name = trim((string) $name);

        if ($name === '') {
            return null;
        }

        $known = static::query()
            ->get(['id', 'name'])
            ->first(fn (self $entry) => mb_strtolower($entry->name) === mb_strtolower($name));

        return ($known ?? static::create(['name' => $name]))->id;
    }
}
