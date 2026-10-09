<?php

namespace App\Support;

use App\Models\EquipmentEvent;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * What passed through one colleague's hands, read out of the equipment journal.
 *
 * Nothing records a spell of use on its own: the journal says when a unit went
 * to somebody and when it left them, and everything in between happened while
 * they had it. So the spells are worked out from the entries that changed the
 * holder, and the rest of the story is whatever the same unit's journal holds
 * between those two entries — a service visit, an inventory check, a correction.
 */
class EquipmentHistory
{
    /**
     * @param  list<array{unit_id: int, from: int, to: int|null}>  $spells
     */
    private function __construct(private array $spells) {}

    /**
     * Reads the journal for the entries that handed a unit to this colleague or
     * took it from them, and pairs them up into spells of use.
     */
    public static function of(User $employee): self
    {
        $spells = [];
        $open = [];

        // Entries are walked in the order they happened, per unit, so a spell
        // opens on the handover and closes on whatever ended it.
        $boundaries = EquipmentEvent::query()
            ->whereJsonContains('diff->holder_user_id', $employee->id)
            ->orderBy('equipment_id')
            ->orderBy('id')
            ->get(['id', 'equipment_id', 'diff']);

        foreach ($boundaries as $event) {
            [$before, $after] = $event->diff['holder_user_id'] ?? [null, null];
            $unit = $event->equipment_id;

            if ((int) $after === $employee->id) {
                $open[$unit] = $event->id;
            }

            if ((int) $before === $employee->id && isset($open[$unit])) {
                $spells[] = ['unit_id' => $unit, 'from' => $open[$unit], 'to' => $event->id];
                unset($open[$unit]);
            }
        }

        // Whatever is still open is what the colleague holds now.
        foreach ($open as $unit => $from) {
            $spells[] = ['unit_id' => $unit, 'from' => $from, 'to' => null];
        }

        return new self($spells);
    }

    /**
     * Every entry that falls inside a spell, newest first — including the two
     * that bound it, since the handover and the return are part of the story.
     *
     * @return Collection<int, EquipmentEvent>
     */
    public function events(): Collection
    {
        if ($this->spells === []) {
            return collect();
        }

        $units = array_values(array_unique(array_column($this->spells, 'unit_id')));

        $all = EquipmentEvent::query()
            // What names a unit lives in two of its category's fields, loaded
            // here for the whole story rather than asked line by line.
            ->with([
                'user:id,name,surname,avatar',
                'equipment' => fn ($unit) => $unit->select(['id', 'equipment_type_id'])->withIdentity(),
                'equipment.type:id,name',
                'photos',
            ])
            ->whereIn('equipment_id', $units)
            ->orderBy('id')
            ->get();

        $kept = $all->filter(fn (EquipmentEvent $event) => $this->covers($event));

        // Where a unit went after this colleague, so the story says whose it is
        // now rather than ending on the day it was handed back.
        foreach ($this->spells as $spell) {
            if ($spell['to'] === null) {
                continue;
            }

            $next = $all->first(
                fn (EquipmentEvent $event) => $event->equipment_id === $spell['unit_id']
                    && $event->id > $spell['to']
                    && $event->kind === 'issued',
            );

            if ($next !== null) {
                $kept->push($next);
            }
        }

        // Read newest first by date, as the other two journals are: entry ids
        // follow the order things were written down, which is not the order
        // they happened in once anything has been recorded after the fact.
        return $kept->unique('id')
            ->sortByDesc(fn (EquipmentEvent $event) => [$event->created_at?->getTimestamp() ?? 0, $event->id])
            ->values();
    }

    /**
     * An entry belongs to a spell when it is the same unit's and stands between
     * the handover and whatever ended it. Entry ids are compared rather than
     * dates: two things can happen to a unit on one day, and their order is
     * the order they were written.
     */
    private function covers(EquipmentEvent $event): bool
    {
        foreach ($this->spells as $spell) {
            if ($spell['unit_id'] !== $event->equipment_id) {
                continue;
            }

            if ($event->id >= $spell['from'] && ($spell['to'] === null || $event->id <= $spell['to'])) {
                return true;
            }
        }

        return false;
    }
}
