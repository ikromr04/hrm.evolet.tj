<?php

namespace App\Http\Controllers;

use App\Models\Equipment;
use App\Models\EquipmentEvent;
use App\Models\EquipmentType;
use App\Models\User;
use App\Support\EmployeeFields;
use App\Support\EquipmentAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What happened to the fleet over a stretch of time: which units moved, who
 * moved them, what changed on them. The section's other pages answer "where
 * is this unit now"; this one answers "what went on last month".
 */
class EquipmentJournalController extends Controller
{
    public const PER_PAGE_OPTIONS = [50, 100, 200];

    public function __invoke(Request $request): Response
    {
        $input = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'kind' => ['nullable', 'array'],
            'kind.*' => [Rule::in(EquipmentEvent::KINDS)],
            'type' => ['nullable', 'array'],
            'type.*' => ['integer', Rule::exists('equipment_types', 'id')],
            'actor' => ['nullable', 'array'],
            'actor.*' => ['integer', Rule::exists('users', 'id')],
            'unit' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', Rule::in(self::PER_PAGE_OPTIONS)],
        ]);

        // No period until one is asked for: the journal opens on everything.
        $to = isset($input['to']) ? Carbon::parse($input['to'])->endOfDay() : null;
        $from = isset($input['from']) ? Carbon::parse($input['from'])->startOfDay() : null;

        $filters = [
            'from' => $from?->toDateString(),
            'to' => $to?->toDateString(),
            'kind' => array_values($input['kind'] ?? []),
            'type' => array_map('intval', $input['type'] ?? []),
            'actor' => array_map('intval', $input['actor'] ?? []),
            'unit' => trim($input['unit'] ?? ''),
        ];

        $perPage = (int) ($input['per_page'] ?? self::PER_PAGE_OPTIONS[0]);

        $query = EquipmentEvent::query()
            // Only what happened to units this person may see: a journal is a list
            // of units by another name.
            ->tap(fn (Builder $q) => EquipmentAccess::narrowJournal($q, $request->user()))
            // A unit is named by two of its category's fields now, so the page
            // of entries loads what names them in one go rather than asking
            // after every line.
            ->with([
                'user:id,name,surname,avatar',
                'equipment' => fn ($unit) => $unit->select(['id', 'equipment_type_id'])->withIdentity(),
                'equipment.type:id,name,icon',
                'photos',
            ])
            ->when($from, fn (Builder $q, Carbon $at) => $q->where('created_at', '>=', $at))
            ->when($to, fn (Builder $q, Carbon $at) => $q->where('created_at', '<=', $at))
            ->when($filters['kind'], fn (Builder $q, array $kinds) => $q->whereIn('kind', $kinds))
            ->when($filters['actor'], fn (Builder $q, array $ids) => $q->whereIn('user_id', $ids))
            ->when($filters['type'], fn (Builder $q, array $ids) => $q->whereHas(
                'equipment',
                fn (Builder $q) => $q->whereIn('equipment_type_id', $ids),
            ))
            // By what the unit is called or the number it carries, both of them
            // values of its category's fields now.
            ->when($filters['unit'], fn (Builder $q, string $term) => $q->whereHas(
                'equipment',
                fn (Builder $q) => $q->where(Equipment::roleValueQuery('title'), 'like', "%{$term}%")
                    ->orWhere(Equipment::roleValueQuery('inventory'), 'like', "%{$term}%"),
            ))
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        $page = $query->paginate($perPage)->withQueryString();
        // The ids an entry kept are read back as names here, once for the page.
        $names = EquipmentEvent::namesFor($page->getCollection());

        $events = $page->through(fn (EquipmentEvent $event) => [
            'id' => $event->id,
            'kind' => $event->kind,
            'changes' => $event->diff ?? [],
            'note' => $event->note,
            'at' => $event->created_at?->toIso8601String(),
            'unit' => $event->equipment === null ? null : [
                'id' => $event->equipment->id,
                'name' => $event->equipment->name,
                'inventory_number' => $event->equipment->inventory_number,
                'type' => $event->equipment->type?->name,
                'type_icon' => $event->equipment->type?->icon,
            ],
            'actor' => $event->user === null ? null : [
                'id' => $event->user->id,
                'name' => "{$event->user->surname} {$event->user->name}",
                // Who did it is the journal's business; their face is their card's.
                'avatar' => EmployeeFields::showsAvatar($request->user(), $event->user) ? $event->user->avatar : null,
            ],
            'photos' => $event->photos->map(fn ($photo) => [
                'id' => $photo->id,
                'url' => $photo->url,
                'preview' => $photo->preview_url,
            ]),
        ]);

        return Inertia::render('equipment/journal', [
            'events' => $events,
            'names' => $names,
            'filters' => $filters,
            'perPage' => $perPage,
            'perPageOptions' => self::PER_PAGE_OPTIONS,
            'options' => [
                'types' => EquipmentType::query()->orderBy('name')->get(['id', 'name']),
                // Whom the filter offers: only people who turn up in entries this
                // viewer may read. A name in the list is itself an answer about
                // units they cannot see.
                'actors' => User::query()
                    ->whereHas('equipmentEvents', fn (Builder $q) => EquipmentAccess::narrowJournal($q, $request->user()))
                    ->orderBy('surname')
                    ->orderBy('name')
                    ->get(['id', 'name', 'surname'])
                    ->map(fn (User $u) => ['id' => $u->id, 'name' => "{$u->surname} {$u->name}"]),
            ],
        ]);
    }
}
