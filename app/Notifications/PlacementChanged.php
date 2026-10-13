<?php

namespace App\Notifications;

use App\Models\User;

/**
 * Where somebody stands in the company was changed by somebody else: their
 * access roles, their job titles, their departments or the duties they carry.
 * One line per save, naming each of the four that moved and what it is now.
 *
 * Being transferred to another company or let go is not this: after either the
 * person no longer signs in, so there is nobody to read the line.
 */
class PlacementChanged extends InAppNotification
{
    /**
     * The lines of a card this is about: the relation that holds each, the
     * attribute it is read by, what it is called after «Вам изменили» and what
     * an empty one reads as.
     *
     * @var array<string, array{string, string, string}>
     */
    private const LINES = [
        'roles' => ['title', 'позицию', 'не указана'],
        // With the department in brackets, as the directory names a position
        // everywhere: «Администратор» exists in more than one department now,
        // and a line naming one of them would not say which.
        'positions' => ['display_name', 'должность', 'не указана'],
        // The line reads the way the card itself does: by the abbreviation.
        'departments' => ['display_name', 'отдел', 'не указан'],
        // What they answer for. Holding a position no longer means carrying its
        // whole list — the duties are picked out of it person by person — so a
        // duty handed over or taken away is a move nothing else here would
        // mention, and it is the one of the four a person acts on tomorrow
        // morning. Being given work without being told is exactly what this
        // letter exists to stop.
        'duties' => ['name', 'обязанности', 'не указаны'],
    ];

    /**
     * @param  array<string, list<string>>  $now  The lines that changed, each with what it holds now.
     */
    public function __construct(private readonly User $employee, private readonly array $now) {}

    /**
     * What the lines hold right now, asked of the database rather than of
     * whatever the model has loaded: this is taken before a save and again
     * after it, and the two must not be the same cached list.
     *
     * Kept by id, so renaming a department is not mistaken for moving the
     * people in it.
     *
     * @param  list<string>  $lines
     * @return array<string, array<int, string>>
     */
    public static function snapshot(User $employee, array $lines = ['roles', 'positions', 'departments', 'duties']): array
    {
        $snapshot = [];

        foreach ($lines as $line) {
            [$attribute] = self::LINES[$line];

            // Read off the models rather than straight out of a column: a
            // department's name on screen is decided in PHP, not in SQL.
            $snapshot[$line] = $employee->{$line}()->get()->pluck($attribute, 'id')->all();
        }

        return $snapshot;
    }

    /**
     * Tell the person what moved since the snapshot was taken, if anything did
     * and if it was not their own doing. Somebody who no longer works here is
     * not told: they cannot sign in to read it.
     *
     * @param  array<string, array<int, string>>  $before
     */
    public static function announce(User $employee, array $before, ?User $actor): void
    {
        if ($actor?->is($employee) || ! $employee->isActive()) {
            return;
        }

        $after = self::snapshot($employee, array_keys($before));
        $now = [];

        foreach ($after as $line => $held) {
            $ids = array_keys($held);

            if (array_diff($ids, array_keys($before[$line])) !== [] || array_diff(array_keys($before[$line]), $ids) !== []) {
                $now[$line] = array_values($held);
            }
        }

        if ($now !== []) {
            $employee->notify(new self($employee, $now));
        }
    }

    protected function kind(): string
    {
        return 'employee.placement';
    }

    protected function text(): string
    {
        $parts = [];

        foreach ($this->now as $line => $names) {
            [, $title, $empty] = self::LINES[$line];

            $parts[] = "{$title}: ".($names === [] ? $empty : implode(', ', $names));
        }

        return 'Вам изменили '.implode('; ', $parts);
    }

    /** Their own card, which is where all four lines are shown. */
    protected function target(): array
    {
        return ['type' => 'employee', 'id' => $this->employee->id];
    }
}
