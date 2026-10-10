<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\Equipment;
use App\Models\EquipmentEvent;
use App\Models\User;
use App\Support\Access;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /** How far back "recently" reaches for hires and departures. */
    private const RECENT_DAYS = 30;

    /** How many journal entries the page shows; the rest are the journal's. */
    private const RECENT_EVENTS = 6;

    /**
     * The company at a glance — for the one account that sees the company.
     *
     * The figures here are everybody's numbers, and nobody is given them by a
     * right: until the modules behind them exist and can be opened line by line
     * like the rest, the page belongs to the system administrator. Everybody else
     * starts at their own card.
     *
     * Every figure is counted from what the system keeps. Leave, requests,
     * recruitment and payroll have no module yet, so the page says nothing
     * about them rather than make something up.
     */
    public function __invoke(Request $request): Response|RedirectResponse
    {
        if (! $request->user()->hasRole(Access::SOLE_ROLE)) {
            return to_route('profile');
        }

        $today = today();
        $since = $today->copy()->subDays(self::RECENT_DAYS);

        return Inertia::render('dashboard', [
            'since' => $since->toDateString(),
            'today' => $today->toDateString(),
            'staff' => [
                'active' => User::active()->count(),
                // Counted among the working, as the employee list filtered by
                // the date of hire counts them, so the tile and the list agree.
                'hired' => User::active()
                    ->whereHas('details', fn (Builder $q) => $q->whereDate('hired_at', '>=', $since)->whereDate('hired_at', '<=', $today))
                    ->count(),
                'fired' => User::query()
                    ->where('status', 'fired')
                    ->whereDate('status_changed_at', '>=', $since)
                    ->whereDate('status_changed_at', '<=', $today)
                    ->count(),
            ],
            'equipment' => [
                'issued' => Equipment::query()->where('status', 'issued')->count(),
                'stock' => Equipment::query()->where('status', 'stock')->count(),
                'service' => Equipment::query()->underService()->count(),
            ],
            'departments' => $this->departments(),
            'events' => $this->events(),
        ]);
    }

    /**
     * The top of the structure, each with everybody working below it — the
     * same totals the structure page shows — largest first.
     *
     * @return list<array{id: int, name: string, full_name: string, count: int}>
     */
    private function departments(): array
    {
        $departments = Department::query()
            ->with(['users' => fn ($q) => $q->active()->select('users.id')])
            ->get(['id', 'name', 'abbreviation', 'parent_id']);
        $totals = Department::staffTotals($departments);

        return $departments
            ->whereNull('parent_id')
            ->map(fn (Department $d) => ['id' => $d->id, 'name' => $d->display_name, 'full_name' => $d->name, 'count' => $totals[$d->id]])
            ->sortBy([['count', 'desc'], ['name', 'asc']])
            ->values()
            ->all();
    }

    /**
     * The latest operations from the equipment journal, newest first.
     *
     * @return list<array<string, mixed>>
     */
    private function events(): array
    {
        return EquipmentEvent::query()
            // What a unit is called and the number on its sticker are fields of
            // its category now, so the tile loads them with the unit rather
            // than reading two columns off the row.
            ->with(['user:id,name,surname', 'equipment' => fn ($q) => $q->select('equipment.id')->withIdentity()])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::RECENT_EVENTS)
            ->get()
            ->map(fn (EquipmentEvent $event) => [
                'id' => $event->id,
                'kind' => $event->kind,
                'at' => $event->created_at?->toIso8601String(),
                'unit' => $event->equipment === null ? null : [
                    'id' => $event->equipment->id,
                    'name' => $event->equipment->name,
                    'inventory_number' => $event->equipment->inventory_number,
                ],
                'actor' => $event->user === null ? null : "{$event->user->surname} {$event->user->name}",
            ])
            ->all();
    }
}
