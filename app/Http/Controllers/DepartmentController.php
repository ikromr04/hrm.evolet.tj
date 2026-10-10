<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\User;
use App\Support\EmployeeFields;
use Illuminate\Database\Eloquent\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The company structure as every employee sees it: only public data and only
 * people who still work here. Editing lives in the directories.
 */
class DepartmentController extends Controller
{
    public function index(): Response
    {
        $departments = $this->tree();
        $totals = Department::staffTotals($departments);

        return Inertia::render('departments/index', [
            'departments' => $departments->map(fn (Department $d) => $this->chartNode($d, $totals))->values(),
            'employees_count' => User::active()->count(),
        ]);
    }

    public function show(Department $department): Response
    {
        $departments = $this->tree();
        $totals = Department::staffTotals($departments);
        $current = $departments->firstWhere('id', $department->id);

        $members = $current->users()
            ->active()
            ->wherePivot('is_head', false)
            ->with('positions:id,name')
            ->orderBy('surname')
            ->orderBy('name')
            ->get(['users.id', 'name', 'surname', 'patronymic', 'avatar', 'email']);

        $parents = [];
        for ($parent = $departments->firstWhere('id', $current->parent_id); $parent && ! isset($parents[$parent->id]); $parent = $departments->firstWhere('id', $parent->parent_id)) {
            $parents[$parent->id] = ['id' => $parent->id, 'name' => $parent->display_name, 'full_name' => $parent->name];
        }

        // This department and everything below it, for its own chart.
        $branch = [$current->id];
        for ($level = [$current->id]; $level !== [];) {
            $level = $departments->whereIn('parent_id', $level)->pluck('id')->diff($branch)->values()->all();
            $branch = [...$branch, ...$level];
        }

        return Inertia::render('departments/show', [
            'chart' => $departments->whereIn('id', $branch)->map(fn (Department $d) => $this->chartNode($d, $totals))->values(),
            'department' => [
                'id' => $current->id,
                // Both names: a department's own page has room to say what its
                // abbreviation stands for, and everywhere it is short the full
                // one is what the hover and the screen reader are given.
                'name' => $current->display_name,
                'full_name' => $current->name,
                'parents' => array_reverse(array_values($parents)),
                'total_count' => $totals[$current->id],
                'heads' => $this->heads($current)->load('positions:id,name')->map(fn (User $u) => $this->person($u, details: true))->values(),
                'children' => $departments->where('parent_id', $current->id)->map(fn (Department $d) => [
                    'id' => $d->id,
                    'name' => $d->display_name,
                    'full_name' => $d->name,
                    'total_count' => $totals[$d->id],
                    'heads' => $this->heads($d)->map(fn (User $u) => $this->person($u))->values(),
                ])->values(),
                'members' => $members->map(fn (User $u) => $this->person($u, details: true)),
            ],
        ]);
    }

    /**
     * One box of a chart: public data of working people only.
     *
     * @param  array<int, int>  $totals
     * @return array<string, mixed>
     */
    private function chartNode(Department $department, array $totals): array
    {
        return [
            'id' => $department->id,
            'name' => $department->display_name,
            'full_name' => $department->name,
            'parent_id' => $department->parent_id,
            'total_count' => $totals[$department->id],
            'heads' => $this->heads($department)->map(fn (User $u) => $this->person($u))->values(),
            // Everyone else in this department itself, by surname; sub-departments list their own.
            'members' => $department->users->reject(fn (User $u) => $u->pivot->is_head)->map(fn (User $u) => $this->person($u))->values(),
        ];
    }

    /**
     * Every department in name order, with its working members and their
     * head flag; the tree is small enough to handle in memory.
     *
     * @return Collection<int, Department>
     */
    private function tree(): Collection
    {
        return Department::query()
            ->with(['users' => fn ($q) => $q->active()->select('users.id', 'users.name', 'users.surname', 'users.avatar', 'users.email')->orderBy('surname')->orderBy('name')])
            ->orderBy('name')
            ->get(['id', 'name', 'abbreviation', 'parent_id']);
    }

    /**
     * @return Collection<int, User>
     */
    private function heads(Department $department): Collection
    {
        return $department->users->filter(fn (User $u) => $u->pivot->is_head)->values();
    }

    /**
     * @return array<string, mixed>
     */
    private function person(User $user, bool $details = false): array
    {
        return [
            'id' => $user->id,
            'name' => "{$user->surname} {$user->name}",
            // A name is nobody's secret; a face is a line of a card like any other.
            'avatar' => EmployeeFields::showsAvatar(request()->user(), $user) ? $user->avatar : null,
            ...($details ? [
                'email' => $user->email,
                'positions' => $user->positions->pluck('name')->sort()->values(),
            ] : []),
        ];
    }
}
