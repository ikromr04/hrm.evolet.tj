<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\Equipment;
use App\Models\EquipmentType;
use App\Models\Language;
use App\Models\Position;
use App\Models\User;
use App\Support\Access;
use App\Support\EmployeeFields;
use App\Support\EquipmentAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;

/**
 * The header search: a few quick matches per kind, for jumping straight to a
 * page. Only public data, and only people who still work here.
 */
class SearchController extends Controller
{
    private const LIMIT = 5;

    /** What comes back when there is nothing to look for. */
    private const NOTHING = [
        'employees' => [],
        'equipment' => [],
        'departments' => [],
        'positions' => [],
        'roles' => [],
        'equipmentTypes' => [],
        'languages' => [],
    ];

    public function __invoke(Request $request): JsonResponse
    {
        $request->validate(['q' => ['nullable', 'string', 'max:100']]);
        $query = $request->string('q')->trim()->value();
        $words = preg_split('/\s+/u', $query, -1, PREG_SPLIT_NO_EMPTY);

        if ($words === []) {
            return response()->json(self::NOTHING);
        }

        // A result is a door to a page, so only the doors the viewer may walk
        // through are offered. What each kind leads to decides the right it
        // takes: a position or a language opens the employee list filtered by
        // it, a category opens the equipment list.
        $user = $request->user();
        $people = $user->can('employees.view');
        $units = EquipmentAccess::sees($user);

        // A position, a job title or a language leads to the staff list narrowed by
        // that line of a card, and the list refuses to be narrowed by a line the
        // viewer may not read. So each of those doors asks for its own line too.
        $visible = EmployeeFields::visibleTo($user);
        $narrows = fn (string $field) => $people && in_array($field, $visible, true);

        return response()->json([
            'employees' => $people ? $this->employees($words) : [],
            'equipment' => $units ? $this->equipment($words, $user) : [],
            'departments' => Department::query()
                ->where(fn (Builder $q) => $this->everyWord($q, $words, ['name']))
                ->orderBy('name')
                ->limit(self::LIMIT)
                ->get(['id', 'name'])
                ->map(fn (Department $d) => ['id' => $d->id, 'name' => $d->name]),
            'positions' => $narrows('positions') ? Position::query()
                ->where(fn (Builder $q) => $this->everyWord($q, $words, ['name']))
                ->orderBy('name')
                ->limit(self::LIMIT)
                ->get(['id', 'name'])
                ->map(fn (Position $p) => ['id' => $p->id, 'name' => $p->name]) : [],
            'roles' => $narrows('roles') ? Access::offeredRoles()
                ->where(fn (Builder $q) => $this->everyWord($q, $words, ['title']))
                ->limit(self::LIMIT)
                ->get(['name', 'title'])
                ->map(fn (Role $r) => ['name' => $r->name, 'title' => $r->title]) : [],
            'equipmentTypes' => $units ? EquipmentType::query()
                ->where(fn (Builder $q) => $this->everyWord($q, $words, ['name']))
                ->orderBy('name')
                ->limit(self::LIMIT)
                ->get(['id', 'name', 'icon'])
                ->map(fn (EquipmentType $t) => ['id' => $t->id, 'name' => $t->name, 'icon' => $t->icon]) : [],
            'languages' => $narrows('languages') ? Language::query()
                ->where(fn (Builder $q) => $this->everyWord($q, $words, ['name']))
                ->orderBy('name')
                ->limit(self::LIMIT)
                ->get(['id', 'name'])
                ->map(fn (Language $l) => ['id' => $l->id, 'name' => $l->name]) : [],
        ]);
    }

    /**
     * Each word must match the name, email or a position, so "Назарова дизайнер" works.
     *
     * @param  list<string>  $words
     * @return list<array<string, mixed>>
     */
    private function employees(array $words): array
    {
        $query = User::active()->with('positions:id,name');

        foreach ($words as $word) {
            $like = "%{$word}%";
            $query->where(fn (Builder $q) => $q
                ->where('surname', 'like', $like)
                ->orWhere('name', 'like', $like)
                ->orWhere('patronymic', 'like', $like)
                ->orWhere('email', 'like', $like)
                ->orWhereHas('positions', fn (Builder $q) => $q->where('name', 'like', $like)));
        }

        return $query->orderBy('surname')->orderBy('name')->limit(self::LIMIT)->get(['id', 'name', 'surname', 'avatar', 'email'])
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => "{$u->surname} {$u->name}",
                'avatar' => EmployeeFields::showsAvatar(request()->user(), $u) ? $u->avatar : null,
                'email' => $u->email,
                'positions' => $u->positions->pluck('name')->sort()->values(),
            ])
            ->all();
    }

    /**
     * A unit by anything printed on it — its name, the sticker, the serial — or
     * by who has it, since "ноутбук Рахимов" is how somebody would ask.
     *
     * @param  list<string>  $words
     * @return list<array<string, mixed>>
     */
    private function equipment(array $words, User $user): array
    {
        $query = Equipment::query()->withIdentity()->with(['type:id,name,icon', 'holder:id,name,surname']);
        // Search is a way into the list, so it reaches no further than the list
        // does: a unit one may not open must not surface as a result either.
        EquipmentAccess::narrow($query, $user);

        foreach ($words as $word) {
            $like = "%{$word}%";
            $query->where(fn (Builder $q) => $q
                // What it is called, the number on its sticker, the serial, the
                // model, the maker and everything else a category asks about
                // all live in its fields now, so one question covers the lot.
                ->whereHas('fieldValues', fn (Builder $q) => $q->where('value', 'like', $like))
                ->orWhereHas('type', fn (Builder $q) => $q->where('name', 'like', $like))
                ->orWhereHas('holder', fn (Builder $q) => $q
                    ->where('surname', 'like', $like)
                    ->orWhere('name', 'like', $like)));
        }

        return $query->orderBy(Equipment::roleValueQuery('title'))->limit(self::LIMIT)->get()
            ->map(fn (Equipment $unit) => [
                'id' => $unit->id,
                'name' => $unit->name,
                'inventory_number' => $unit->inventory_number,
                'type' => $unit->type?->name,
                'icon' => $unit->type?->icon,
                'status' => $unit->status,
                'holder' => $unit->holder === null ? null : "{$unit->holder->surname} {$unit->holder->name}",
            ])
            ->all();
    }

    /**
     * @param  list<string>  $words
     * @param  list<string>  $columns
     */
    private function everyWord(Builder $query, array $words, array $columns): void
    {
        foreach ($words as $word) {
            $query->where(function (Builder $q) use ($word, $columns) {
                foreach ($columns as $column) {
                    $q->orWhere($column, 'like', "%{$word}%");
                }
            });
        }
    }
}
