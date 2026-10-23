<?php

namespace App\Http\Controllers\Directories;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Position;
use App\Models\User;
use App\Support\Directories;
use App\Support\Membership;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Отделы", the list the company structure is written down in.
 *
 * It is read under "Структура компании" now, as the "Подразделения" tab beside
 * the chart it draws, rather than as a tab of "Справочники" — but it is a list
 * of that catalogue still, kept by the same pair of rights, which is why it
 * stays here among the controllers that serve them. The chart itself is
 * App\Http\Controllers\DepartmentController: open to everybody, and read-only.
 */
class DepartmentController extends Controller
{
    public function index(Request $request): Response
    {
        $departments = Department::query()
            ->with([
                'heads:id,name,surname',
                'users' => fn ($q) => $q->active()->select('users.id'),
                'positions:id,department_id,name',
            ])
            ->withCount('positions')
            ->orderBy('name')
            ->get(['id', 'name', 'abbreviation', 'parent_id']);

        $totals = Department::staffTotals($departments);
        $opensPositions = Gate::allows(Directories::viewPermission('positions'));

        return Inertia::render('directories/departments', [
            // Reading a list and keeping it are two rights, so the page says
            // which one it is looking at.
            'canEdit' => Directories::canEdit($request->user(), 'departments'),
            'items' => $departments
                ->map(fn (Department $d) => [
                    'id' => $d->id,
                    // The one list that spells a department out: everywhere
                    // else it is named by its abbreviation alone, and this is
                    // where that abbreviation is written.
                    'name' => $d->name,
                    'abbreviation' => $d->abbreviation,
                    'parent_id' => $d->parent_id,
                    // The jobs filed in this department, which is what the list
                    // counts: a department is made of positions, and the people
                    // follow from who holds them.
                    'positions_count' => $d->positions_count,
                    // Named rather than counted. The number travels with each
                    // only for whoever may open a position, so a name stays a
                    // word for everybody else rather than a link into a refusal.
                    'positions' => $d->positions
                        ->map(fn (Position $p) => [
                            'id' => $opensPositions ? $p->id : null,
                            'name' => $p->name,
                            // The department it belongs to, named the way every
                            // badge of a job names one: short, full within reach.
                            'unit' => $p->unit(),
                        ])
                        ->values(),
                    // Working members of this department itself, heads included.
                    'users_count' => $d->users->count(),
                    'total_count' => $totals[$d->id],
                    // Read for the column and nothing else: who leads a
                    // department is worked out from the positions below it.
                    'heads' => $d->heads
                        ->map(fn (User $u) => ['id' => $u->id, 'name' => "{$u->surname} {$u->name}"])
                        ->sortBy('name')
                        ->values(),
                ]),
        ]);
    }

    /**
     * A department that has just been written down has no positions yet, so
     * there is nothing to mark and nobody in it: its people arrive when its
     * positions do.
     */
    public function store(Request $request): RedirectResponse
    {
        [$data] = $this->validated($request);

        Department::create($data);

        return back();
    }

    public function update(Request $request, Department $department): RedirectResponse
    {
        [$data, $leading] = $this->validated($request, $department);

        DB::transaction(function () use ($department, $data, $leading) {
            $department->update($data);
            $this->markLeadingPositions($department, $leading);
        });

        return back();
    }

    /**
     * A department goes and its sub-departments stay, each on the level above
     * the one it was on — which, in a structure two levels deep, is the top:
     * only a top-level department can have units under it, so what it headed
     * now stands on its own. Deleting is not refused over them, because the
     * units are real departments with their own people; losing the box they
     * were grouped in is not a reason to lose them. Employees simply lose this
     * membership.
     */
    public function destroy(Department $department): RedirectResponse
    {
        DB::transaction(function () use ($department) {
            Department::where('parent_id', $department->id)->update(['parent_id' => $department->parent_id]);
            $department->delete();
        });

        return back();
    }

    /**
     * Which of the department's positions lead it, as the dialog's crowns say.
     *
     * Nobody is put into the department or taken out of it here, and nobody is
     * made a head directly. The flag goes onto the positions and the
     * memberships follow from it: whoever holds a marked position heads the
     * department, whoever holds any of its positions belongs to it, and
     * Membership is the one place that writes that down.
     *
     * Only the positions of this department are touched. The form is given its
     * own list and the save is not a chance to reach into another department's
     * — a position of somebody else's, had one been sent, is refused by the
     * rules rather than quietly moved.
     *
     * Transferred and fired people are not touched, which is Membership's rule
     * and used to be this method's: their old membership stays on record.
     *
     * @param  list<int>|null  $leading  null leaves every flag as it is
     */
    private function markLeadingPositions(Department $department, ?array $leading): void
    {
        if ($leading === null) {
            return;
        }

        [$lead, $rest] = $department->positions()
            ->get(['id', 'department_id', 'is_head'])
            ->partition(fn (Position $position) => in_array($position->id, $leading, true));

        // A save that moved no crown has no memberships to work out again, and
        // most saves from this window are a rename.
        $moved = $lead->contains(fn (Position $p) => ! $p->is_head) || $rest->contains(fn (Position $p) => $p->is_head);

        if (! $moved) {
            return;
        }

        Position::whereKey($lead->modelKeys())->update(['is_head' => true, 'updated_at' => now()]);
        Position::whereKey($rest->modelKeys())->update(['is_head' => false, 'updated_at' => now()]);

        Membership::refresh(Membership::within($department));
    }

    /**
     * @return array{0: array{name: string, abbreviation?: string|null, parent_id?: int|null}, 1: list<int>|null}
     */
    private function validated(Request $request, ?Department $department = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150', Rule::unique('departments', 'name')->ignore($department)],
            // Not required: the departments already on file have none, and one
            // without it goes on reading by its full name.
            'abbreviation' => ['nullable', 'string', 'max:50'],
            'parent_id' => [
                'nullable',
                'integer',
                Rule::exists('departments', 'id'),
                // The model refuses these placements outright, and an exception
                // from a save is a 500 the dialog cannot show, so the form says
                // the same thing where the person can read it.
                function (string $attribute, mixed $value, Closure $fail) use ($department) {
                    if ($value === null) {
                        return;
                    }

                    if ($department && $department->descendantIds(true)->contains((int) $value)) {
                        $fail('Отдел нельзя вложить в самого себя или в своё подразделение.');

                        return;
                    }

                    // Two levels, and which of them a department is on follows
                    // from what it has: one that heads units of its own stays on
                    // top, and one that is itself a sub-department takes none.
                    if ($department && Department::where('parent_id', $department->id)->exists()) {
                        $fail('Отдел нельзя вложить в другой: у него есть свои подразделения.');

                        return;
                    }

                    if (Department::whereKey($value)->whereNotNull('parent_id')->exists()) {
                        $fail('Отдел нельзя вложить в подразделение: в структуре отделов только два уровня.');
                    }
                },
            ],
            // Which of this department's positions lead it. Several are
            // allowed: two departments are led by two people today, so the
            // crowns are toggles and not a choice of one.
            'leading_position_ids' => ['sometimes', 'array'],
            'leading_position_ids.*' => [
                'integer',
                'distinct',
                // Of this department and no other. A position belongs to one
                // department, and marking somebody else's from here would be
                // filing a job out of their list without their form ever
                // saying so.
                Rule::exists('positions', 'id')->where('department_id', $department?->id ?? 0),
            ],
        ], messages: [
            'leading_position_ids.*.exists' => 'Эта должность не относится к этому отделу.',
        ], attributes: [
            'name' => 'название',
            'abbreviation' => 'аббревиатура',
            'parent_id' => 'родительский отдел',
            'leading_position_ids' => 'руководящие должности',
            'leading_position_ids.*' => 'руководящая должность',
        ]);

        // An abbreviation somebody cleared is a department that has none again,
        // not one called by an empty string. A request that does not carry the
        // field at all is changing something else and leaves it alone, the way
        // the two lists of people below do: left to fall through, a save of
        // anything else would wipe a short name somebody had written.
        if ($request->has('abbreviation')) {
            $data['abbreviation'] = trim((string) ($data['abbreviation'] ?? '')) ?: null;
        } else {
            unset($data['abbreviation']);
        }

        // A request that does not carry the field is changing something else —
        // a rename, a placement in the tree — and leaves the crowns alone. An
        // empty list is an answer: nothing leads this department.
        $leading = $request->has('leading_position_ids') ? array_map('intval', $data['leading_position_ids'] ?? []) : null;
        unset($data['leading_position_ids']);

        return [$data, $leading];
    }
}
