<?php

namespace App\Support;

use App\Models\Department;
use App\Models\Position;
use App\Models\PositionDuty;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Where a colleague stands in the company, as one answer rather than three
 * lists.
 *
 * A placement is a department, a position of that department and the duties
 * picked out of that position's list. The three used to be filed separately —
 * a bag of departments beside a bag of positions — and the pair they really
 * form was nowhere on record: two «Аналитик» in two departments were one row,
 * and nothing said which of somebody's departments a position of theirs
 * belonged to. So the form asks for them together, in that order, and
 * somebody may have several placements: a person can hold a position in one
 * department and another in a second.
 *
 * A placement without a position is allowed. Belonging to a department without
 * holding a position in it is an ordinary thing — a few people are filed that
 * way — and it is not the same as not belonging to it.
 *
 * The form narrows each field by the one above it; this is where the narrowing
 * is enforced. A position that is not of its department, or a duty that is not
 * of its position, is refused rather than quietly filed: the form filters for
 * the person's sake, the server decides.
 */
final class Placements
{
    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            // Nobody is placed anywhere until somebody says so, so an empty list
            // is an answer — but it has to be sent, or a form that forgot the
            // field would read as "take them out of everywhere".
            'placements' => ['present', 'array'],
            'placements.*' => ['array'],
            'placements.*.department_id' => ['required', 'integer', Rule::exists('departments', 'id')],
            // The position is picked after the department and may be left alone.
            'placements.*.position_id' => ['nullable', 'integer', Rule::exists('positions', 'id')],
            // Holding a position does not hand somebody its whole list: the
            // duties are picked out of it, one or several, or none yet.
            'placements.*.duty_ids' => ['nullable', 'array'],
            'placements.*.duty_ids.*' => ['integer', 'distinct', Rule::exists('position_duties', 'id')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function attributes(): array
    {
        return [
            'placements' => 'отдел и должность',
            'placements.*.department_id' => 'отдел',
            'placements.*.position_id' => 'должность',
            'placements.*.duty_ids' => 'обязанности',
            'placements.*.duty_ids.*' => 'обязанность',
        ];
    }

    /**
     * The part no rule can see one value at a time: whether each placement hangs
     * together, and whether the same one was sent twice.
     *
     * Everything is read in two queries rather than one per row, and a value the
     * rules have already failed is left alone — saying a position is not of its
     * department on top of saying there is no such position reads as two faults
     * where there is one.
     */
    public static function check(Validator $validator): void
    {
        $sent = $validator->getData()['placements'] ?? null;

        if (! is_array($sent)) {
            return;
        }

        $placements = self::read($sent);
        $positions = Position::query()
            ->findMany($placements->pluck('position_id')->filter()->unique())
            ->keyBy('id');
        $duties = PositionDuty::query()
            ->findMany($placements->pluck('duty_ids')->flatten()->unique())
            ->keyBy('id');

        $seen = [];

        foreach ($placements as $index => $placement) {
            ['department_id' => $department, 'position_id' => $positionId] = $placement;
            $position = $positionId === null ? null : $positions->get($positionId);

            // A department sent twice with the same position — or twice with no
            // position at all — is the same placement written out twice, and the
            // second one would vanish on the save rather than mean anything.
            $pair = "{$department}:".($positionId ?? '-');

            if ($department !== null && in_array($pair, $seen, true)) {
                $validator->errors()->add("placements.{$index}.department_id", 'Этот отдел уже есть в списке с этой же должностью.');
            }

            $seen[] = $pair;

            if ($position !== null && $department !== null && $position->department_id !== $department) {
                $validator->errors()->add("placements.{$index}.position_id", 'Эта должность не относится к выбранному отделу.');
            }

            if ($positionId === null && $placement['duty_ids'] !== []) {
                // Changing the department empties the two fields below it, so a
                // form that sent duties without a position is a form out of step
                // with itself.
                $validator->errors()->add("placements.{$index}.duty_ids", 'Обязанности выбираются из должности, а должность не выбрана.');

                continue;
            }

            foreach ($placement['duty_ids'] as $at => $dutyId) {
                $duty = $duties->get($dutyId);

                if ($duty !== null && $position !== null && $duty->position_id !== $positionId) {
                    $validator->errors()->add("placements.{$index}.duty_ids.{$at}", 'Эта обязанность не относится к выбранной должности.');
                }
            }
        }
    }

    /**
     * The placements as the form sent them, each reduced to the three ids it is:
     * what is not a number is read as nothing, so the rules above get to be the
     * ones that complain about it.
     *
     * @param  array<array-key, mixed>  $sent
     * @return Collection<int|string, array{department_id: int|null, position_id: int|null, duty_ids: list<int>}>
     */
    public static function read(array $sent): Collection
    {
        return collect($sent)
            ->filter(fn (mixed $placement) => is_array($placement))
            ->map(fn (array $placement) => [
                'department_id' => self::id($placement['department_id'] ?? null),
                'position_id' => self::id($placement['position_id'] ?? null),
                'duty_ids' => collect(is_array($placement['duty_ids'] ?? null) ? $placement['duty_ids'] : [])
                    ->map(fn (mixed $id) => self::id($id))
                    ->filter()
                    ->values()
                    ->all(),
            ]);
    }

    private static function id(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * File the colleague where the form says, and nowhere else: the placements
     * are the whole of it, so the three tables behind them are replaced rather
     * than added to.
     *
     * The departments are not written here any more. Belonging to one follows
     * from holding one of its positions, and Membership works that out from the
     * positions this save has just filed — including who leads what, which used
     * to be a flag on the membership row this method had to carry by hand to
     * keep from demoting every head in the company.
     *
     * What the form still says on its own is a department named with no
     * position in it. Nothing accounts for such a membership, and it is handed
     * over as one written by hand: the form may file somebody in a department
     * without giving them a job there, as it always could, and Membership keeps
     * those rows apart from the ones it derives.
     *
     * @param  array<array-key, mixed>  $sent
     */
    public static function save(User $employee, array $sent): void
    {
        $placements = self::read($sent)->filter(fn (array $placement) => $placement['department_id'] !== null);

        $employee->positions()->sync($placements->pluck('position_id')->filter()->unique()->values()->all());

        $named = $placements->pluck('department_id')->unique();
        $withAPosition = $placements
            ->filter(fn (array $placement) => $placement['position_id'] !== null)
            ->pluck('department_id')
            ->unique();

        Membership::refresh($employee, byHand: $named->diff($withAPosition)->values()->all());

        // The duties of a position that is no longer held go with it: they were
        // picked out of its list and there is no list to pick from any more.
        $employee->duties()->sync($placements
            ->filter(fn (array $placement) => $placement['position_id'] !== null)
            ->pluck('duty_ids')
            ->flatten()
            ->unique()
            ->values()
            ->all());

        // The card is drawn from these three right after the save.
        $employee->unsetRelation('departments')->unsetRelation('positions')->unsetRelation('duties');
    }

    /**
     * What the card's dialog opens on: the placements on file, department by
     * department in the order the card lists them, and inside a department one
     * per position held there.
     *
     * A department with nothing held in it is a placement of its own with no
     * position — that is how somebody belongs to a department without holding a
     * position there, and it has to come back as it went in.
     *
     * A department reached only through a position is listed too. The pairs were
     * nobody's to record before this form existed, so the data still holds a
     * position whose department the employee is not filed under; reading it short
     * would drop the position on the next save.
     *
     * @return list<array{department_id: int, position_id: int|null, duty_ids: list<int>}>
     */
    public static function of(User $employee): array
    {
        $employee->loadMissing(['departments', 'positions', 'duties']);

        $held = $employee->positions->groupBy(fn (Position $position) => (int) $position->department_id);
        $carried = $employee->duties->groupBy(fn (PositionDuty $duty) => (int) $duty->position_id);

        return $employee->departments
            ->map(fn (Department $department) => $department->id)
            ->merge($held->keys()->filter())
            ->unique()
            ->values()
            ->flatMap(fn (int $id) => $held->get($id, collect())
                ->map(fn (Position $position) => [
                    'department_id' => $id,
                    'position_id' => $position->id,
                    'duty_ids' => $carried->get($position->id, collect())->pluck('id')->all(),
                ])
                ->whenEmpty(fn () => collect([[
                    'department_id' => $id,
                    'position_id' => null,
                    'duty_ids' => [],
                ]]))
                ->all())
            ->all();
    }

    /**
     * Every position with the department behind it, and every duty with the
     * position behind it: the form narrows the one by the other as somebody
     * fills it in, so it is given the lot rather than asking again per choice.
     * There are a few hundred of each.
     *
     * @return array{positions: list<array{id: int, name: string, display_name: string, department_id: int|null}>, duties: list<array{id: int, name: string, position_id: int}>}
     */
    public static function options(): array
    {
        return [
            'positions' => Position::query()
                ->with('department:id,name,abbreviation')
                ->orderBy('name')
                ->get(['id', 'name', 'department_id'])
                ->map(fn (Position $position) => [
                    'id' => $position->id,
                    'name' => $position->name,
                    // «Администратор (ОАП)»: the name alone no longer says which
                    // position it is, since the same one may exist in two
                    // departments.
                    'display_name' => $position->display_name,
                    'department_id' => $position->department_id,
                ])
                ->all(),
            'duties' => PositionDuty::query()
                ->orderBy('position_id')
                ->orderBy('order')
                ->orderBy('id')
                ->get(['id', 'name', 'position_id'])
                ->map(fn (PositionDuty $duty) => [
                    'id' => $duty->id,
                    'name' => $duty->name,
                    'position_id' => $duty->position_id,
                ])
                ->all(),
        ];
    }
}
