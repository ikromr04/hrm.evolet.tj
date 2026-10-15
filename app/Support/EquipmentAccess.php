<?php

namespace App\Support;

use App\Models\Department;
use App\Models\Equipment;
use App\Models\EquipmentEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * How much of the fleet a position sees.
 *
 * "Оборудование" is not one answer but three, because three different people
 * ask about it: a colleague wants to know what is on their own desk, a head of
 * department wants to know what their people hold, and whoever keeps the books
 * wants the lot. Each of the three is a right of its own, and each carries a
 * second question with it — whether the journal of what happened to those units
 * is open too, which is the difference between "что у меня сейчас" and "кто это
 * трогал за последний год".
 *
 * A scope narrows every list the section shows: the table, the card, the search
 * and the journal. Nothing is filtered in the interface alone.
 */
final class EquipmentAccess
{
    /**
     * scope => [title, what it opens, what its journal opens].
     *
     * @var array<string, array{string, string, string}>
     */
    public const SCOPES = [
        'own' => [
            'Своё оборудование',
            'Единицы, которые числятся за самим сотрудником.',
            'Что происходило с его собственными единицами.',
        ],
        'department' => [
            'Оборудование своего отдела',
            'Для руководителя: единицы у людей из отделов, которые он возглавляет, вместе с подотделами.',
            'Что происходило с единицами его отдела.',
        ],
        'all' => [
            'Всё оборудование',
            'Любая единица компании, у кого бы она ни была и на балансе ли она.',
            'Журнал операций по всей технике.',
        ],
    ];

    /**
     * The blocks of a card, each changed on its own.
     *
     * A unit's card is edited block by block, the way an employee's is edited
     * line by line: whoever keeps the inventory dates up to date has no business
     * renaming the unit, and the person who packs the box does neither.
     *
     * @var array<string, array{string, string}>
     */
    public const BLOCKS = [
        'specs' => [
            'Характеристики',
            'Наименование, категория, инвентарный номер и поля категории.',
        ],
        'accessories' => [
            'Комплектация',
            'Что идёт в комплекте с единицей: блок питания, кабели, сумка.',
        ],
        'state' => [
            'Состояние и инвентаризация',
            'Состояние единицы, дата проверки и срок следующей инвентаризации.',
        ],
    ];

    /**
     * What one does to a unit rather than to a line of its card.
     *
     * These are the moves the journal records: each one changes where the unit
     * is or whose it is, and each is a right of its own because they are handed
     * out to different people — a storekeeper hands units over, an accountant
     * writes them off, and only somebody correcting a mistake deletes a row.
     *
     * @var array<string, array{string, string}>
     */
    public const ACTIONS = [
        'create' => [
            'Добавление',
            'Завести новую единицу и, если нужно, сразу выдать её.',
        ],
        'issue' => [
            'Выдача',
            'Передать единицу сотруднику под его ответственность.',
        ],
        'take' => [
            'Возврат',
            'Принять единицу обратно на баланс.',
        ],
        'write_off' => [
            'Списание',
            'Вывести единицу из обращения: износ, поломка, утрата.',
        ],
        'service' => [
            'Обслуживание',
            'Записи о ремонте: отправить, завершить, исправить и удалить.',
        ],
        'delete' => [
            'Удаление',
            'Снять единицу с учёта вместе с её журналом — для дубля или ошибки.',
        ],
    ];

    /** The right to see the units of a scope. */
    public static function viewPermission(string $scope): string
    {
        return "equipment.view.{$scope}";
    }

    /** The right to change one block of a card. */
    public static function blockPermission(string $block): string
    {
        return "equipment.edit.{$block}";
    }

    /** The right to one move. */
    public static function actionPermission(string $action): string
    {
        return "equipment.{$action}";
    }

    /** The right to read the journal of those same units. */
    public static function journalPermission(string $scope): string
    {
        return "equipment.journal.{$scope}";
    }

    /**
     * Every scope as a pair of rights, for the catalogue the seeder reads.
     *
     * @return list<string>
     */
    public static function permissions(): array
    {
        $rights = [];

        foreach (array_keys(self::SCOPES) as $scope) {
            $rights[] = self::viewPermission($scope);
            $rights[] = self::journalPermission($scope);
        }

        foreach (array_keys(self::BLOCKS) as $block) {
            $rights[] = self::blockPermission($block);
        }

        foreach (array_keys(self::ACTIONS) as $action) {
            $rights[] = self::actionPermission($action);
        }

        return $rights;
    }

    /**
     * The blocks as a dialog reads them, in the order the card shows them.
     *
     * @return list<array{key: string, title: string, hint: string}>
     */
    public static function blockTree(): array
    {
        return self::listing(self::BLOCKS, fn (string $key) => self::blockPermission($key));
    }

    /**
     * The moves as a dialog reads them.
     *
     * @return list<array{key: string, title: string, hint: string}>
     */
    public static function actionTree(): array
    {
        return self::listing(self::ACTIONS, fn (string $key) => self::actionPermission($key));
    }

    /**
     * One of the two lists above, in the shape every plain list of rights takes:
     * the right itself is the key, so the same dialog serves these as serves the
     * three things one does to a colleague.
     *
     * @param  array<string, array{string, string}>  $items
     * @param  callable(string): string  $permission
     * @return list<array{key: string, title: string, hint: string}>
     */
    private static function listing(array $items, callable $permission): array
    {
        $listing = [];

        foreach ($items as $key => [$title, $hint]) {
            $listing[] = ['key' => $permission($key), 'title' => $title, 'hint' => $hint];
        }

        return $listing;
    }

    /**
     * The catalogue as a dialog reads it: the three scopes, each with the right
     * to see them and the right to read their journal.
     *
     * @return list<array{key: string, title: string, hint: string, permission: string, journal: array{title: string, hint: string, permission: string}}>
     */
    public static function tree(): array
    {
        $tree = [];

        foreach (self::SCOPES as $scope => [$title, $hint, $journalHint]) {
            $tree[] = [
                'key' => $scope,
                'title' => $title,
                'hint' => $hint,
                'permission' => self::viewPermission($scope),
                'journal' => [
                    'title' => 'Журнал операций',
                    'hint' => $journalHint,
                    'permission' => self::journalPermission($scope),
                ],
            ];
        }

        return $tree;
    }

    /**
     * Which parts of the fleet this person may see.
     *
     * @return list<string>
     */
    public static function viewScopes(User $user): array
    {
        return array_values(array_filter(
            array_keys(self::SCOPES),
            fn (string $scope) => $user->can(self::viewPermission($scope)),
        ));
    }

    /**
     * Which parts of it they may read the journal of. Seeing is a condition of
     * reading the journal: the journal is about units, and a unit one may not
     * see is not one whose history should be readable.
     *
     * @return list<string>
     */
    public static function journalScopes(User $user): array
    {
        return array_values(array_filter(
            self::viewScopes($user),
            fn (string $scope) => $user->can(self::journalPermission($scope)),
        ));
    }

    /** Whether the section is open to them at all. */
    public static function sees(User $user): bool
    {
        return self::viewScopes($user) !== [];
    }

    /** Whether any journal is. */
    public static function readsJournal(User $user): bool
    {
        return self::journalScopes($user) !== [];
    }

    /**
     * Narrow a query over units to what this person may see. "Все" narrows
     * nothing; no scope at all leaves nothing, which is the honest answer to a
     * list somebody may not read.
     *
     * @param  Builder<Equipment>  $query
     * @param  list<string>|null  $scopes  Which scopes to apply; their view scopes by default.
     */
    public static function narrow(Builder $query, User $user, ?array $scopes = null): void
    {
        $scopes ??= self::viewScopes($user);

        if (in_array('all', $scopes, true)) {
            return;
        }

        if ($scopes === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $departments = in_array('department', $scopes, true) ? self::departmentIds($user) : [];

        $query->where(function (Builder $q) use ($scopes, $user, $departments) {
            if (in_array('own', $scopes, true)) {
                $q->orWhere('holder_user_id', $user->id);
            }

            if ($departments !== []) {
                $q->orWhereHas('holder.departments', fn (Builder $q) => $q->whereIn('departments.id', $departments));
                // And what the department itself holds: a printer signed out
                // to the отдел аналитики is the отдел аналитики's equipment in
                // the only sense anybody means by the question, and without
                // this line the head of a department would be shown their
                // people's laptops and not their own department's printer.
                $q->orWhereIn('holder_department_id', $departments);
            }

            // A scope that comes to nothing — a head of no department — must not
            // widen the list by leaving the group empty.
            if (! in_array('own', $scopes, true) && $departments === []) {
                $q->whereRaw('1 = 0');
            }
        });
    }

    /**
     * The same for the journal, over the entries rather than the units.
     *
     * @param  Builder<EquipmentEvent>  $query
     */
    public static function narrowJournal(Builder $query, User $user): void
    {
        $scopes = self::journalScopes($user);

        if (in_array('all', $scopes, true)) {
            return;
        }

        $query->whereHas('equipment', fn (Builder $q) => self::narrow($q, $user, $scopes));
    }

    /** Whether this very unit is one they may see. */
    public static function canSee(User $user, Equipment $unit): bool
    {
        return Equipment::query()->whereKey($unit->id)->tap(fn (Builder $q) => self::narrow($q, $user))->exists();
    }

    /**
     * Whether one block of this unit's card is theirs to change. Seeing the unit
     * is a condition: a block one cannot read is not a block one edits.
     */
    public static function canEditBlock(User $user, Equipment $unit, string $block): bool
    {
        return $user->can(self::blockPermission($block)) && self::canSee($user, $unit);
    }

    /**
     * Whether a move is theirs to make. Putting a unit on the books names no
     * unit yet, so that one is asked without it.
     */
    public static function canDo(User $user, string $action, ?Equipment $unit = null): bool
    {
        return $user->can(self::actionPermission($action)) && ($unit === null || self::canSee($user, $unit));
    }

    /**
     * Which blocks and moves are open, as the pages of the section ask it: the
     * unit is one they already see, so only the rights are left to check.
     *
     * @return array<string, bool>
     */
    public static function allowed(User $user): array
    {
        $allowed = [];

        foreach (array_keys(self::BLOCKS) as $block) {
            $allowed[$block] = $user->can(self::blockPermission($block));
        }

        foreach (array_keys(self::ACTIONS) as $action) {
            $allowed[$action] = $user->can(self::actionPermission($action));
        }

        return $allowed;
    }

    /** Whether anything on a card may be changed at all. */
    public static function edits(User $user): bool
    {
        foreach (array_keys(self::BLOCKS) as $block) {
            if ($user->can(self::blockPermission($block))) {
                return true;
            }
        }

        return false;
    }

    /** Whether its journal is theirs to read. */
    public static function canReadJournalOf(User $user, Equipment $unit): bool
    {
        $scopes = self::journalScopes($user);

        return $scopes !== []
            && Equipment::query()->whereKey($unit->id)->tap(fn (Builder $q) => self::narrow($q, $user, $scopes))->exists();
    }

    /**
     * The departments this person heads, with everything under them: a
     * department head answers for the divisions inside it too.
     *
     * @return list<int>
     */
    public static function departmentIds(User $user): array
    {
        $headed = $user->departments()->wherePivot('is_head', true)->pluck('departments.id');

        if ($headed->isEmpty()) {
            return [];
        }

        return Department::query()
            ->whereIn('id', $headed)
            ->get()
            ->flatMap(fn (Department $department) => $department->descendantIds(true))
            ->unique()
            ->values()
            ->all();
    }
}
