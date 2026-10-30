<?php

namespace App\Support;

use App\Models\Position;
use App\Models\PositionDuty;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Where a colleague stands in the company: the jobs they hold and the duties
 * picked out of each.
 *
 * A placement is a position and nothing else — the department comes with it,
 * because a position belongs to one and belonging to a department follows from
 * holding one of its jobs. The form used to ask for the department first and
 * the position under it, which asked twice for one answer and let somebody be
 * filed in a department with no job in it; that is not a placement any more.
 *
 * Somebody may hold several: a job in one department and another in a second,
 * or two jobs in the same one.
 *
 * Holding a job hands nobody its whole list of duties — one or several are
 * picked out of it — and a duty that is not of its position is refused rather
 * than quietly filed: the form filters for the person's sake, the server
 * decides.
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
            // The job is the placement, so there is no placement without one.
            'placements.*.position_id' => ['required', 'integer', Rule::exists('positions', 'id')],
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
            'placements' => 'должности',
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
            $positionId = $placement['position_id'];
            $position = $positionId === null ? null : $positions->get($positionId);

            // The same job written out twice is one job: the second row would
            // vanish on the save rather than mean anything.
            if ($positionId !== null && in_array($positionId, $seen, true)) {
                $validator->errors()->add("placements.{$index}.position_id", 'Эта должность уже есть в списке.');
            }

            $seen[] = $positionId;

            if ($positionId === null && $placement['duty_ids'] !== []) {
                // Changing the job empties the field below it, so a form that
                // sent duties without one is a form out of step with itself.
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
     * The placements as the form sent them, each reduced to the ids it is: what
     * is not a number is read as nothing, so the rules above get to be the ones
     * that complain about it.
     *
     * @param  array<array-key, mixed>  $sent
     * @return Collection<int|string, array{position_id: int|null, duty_ids: list<int>}>
     */
    public static function read(array $sent): Collection
    {
        return collect($sent)
            ->filter(fn (mixed $placement) => is_array($placement))
            ->map(fn (array $placement) => [
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
     * are the whole of it, so the tables behind them are replaced rather than
     * added to.
     *
     * The departments are not written here. Belonging to one follows from
     * holding one of its positions, and Membership works that out from the jobs
     * this save has just filed — who leads what among them, which is the crown
     * on the job rather than a fact of its own.
     *
     * @param  array<array-key, mixed>  $sent
     */
    public static function save(User $employee, array $sent): void
    {
        $placements = self::read($sent)->filter(fn (array $placement) => $placement['position_id'] !== null);

        $employee->positions()->sync($placements->pluck('position_id')->unique()->values()->all());

        Membership::refresh($employee);

        // The duties of a position that is no longer held go with it: they were
        // picked out of its list and there is no list to pick from any more.
        $employee->duties()->sync($placements
            ->pluck('duty_ids')
            ->flatten()
            ->unique()
            ->values()
            ->all());

        // The card is drawn from these three right after the save.
        $employee->unsetRelation('departments')->unsetRelation('positions')->unsetRelation('duties');
    }

    /**
     * What the card's dialog opens on: the jobs on file, each with the duties
     * picked out of it, named the way the picker names them so the list reads in
     * the order somebody will look for.
     *
     * @return list<array{position_id: int, duty_ids: list<int>}>
     */
    public static function of(User $employee): array
    {
        $employee->loadMissing(['positions', 'duties']);

        $carried = $employee->duties->groupBy(fn (PositionDuty $duty) => (int) $duty->position_id);

        return $employee->positions
            ->sortBy('display_name')
            ->map(fn (Position $position) => [
                'position_id' => $position->id,
                'duty_ids' => $carried->get($position->id, collect())->pluck('id')->all(),
            ])
            ->values()
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
                    // The department itself as well, so a list of jobs can name
                    // it in full under the job rather than only in brackets.
                    'unit' => $position->unit(),
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
