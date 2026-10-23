<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Permission\Models\Role;

/**
 * Every right the system knows about.
 *
 * A right is a section plus what it lets a person do there, which is how the
 * access table reads: one column group per section, one column per action. The
 * list lives in code rather than in the database because the sections and the
 * actions are the application itself — positions come and go, but "Оборудование:
 * выдача" only exists as long as there is a page that hands equipment out.
 *
 * Administrators and system administrators are not on this list: they pass every
 * check through Gate::before, including rights added later.
 */
final class Access
{
    /**
     * The one position that is not decided by this list.
     *
     * Everything a person may do is a right ticked on the access page — with a
     * single exception, because somebody has to be able to reach that page and
     * everything behind it. The system administrator holds no rights of their own
     * and passes every check through Gate::before. There is exactly one: the
     * position is offered in no picker, cannot be handed to anybody, and cannot be
     * taken off its own card, since there would be nobody left to hand it back.
     *
     * Every other position, whatever it is called, is an ordinary one.
     */
    public const SOLE_ROLE = 'sysadmin';

    /** The sections, in the order the table shows them. */
    public const SECTIONS = [
        // Its own card comes first: everybody has one, and a position is read from
        // what it does for the person holding it before what it does to others.
        // No rights of the plain kind at all — what is on a card is decided line
        // by line.
        'profile' => 'Профиль',
        'employees' => 'Сотрудники',
        'equipment' => 'Оборудование',
        'directories' => 'Справочники',
    ];

    /**
     * key => [what it is called, what it actually opens up].
     *
     * The key is the section, a dot and the action, so the section a right
     * belongs to is read off the key itself.
     *
     * @var array<string, array{string, string}>
     */
    public const PERMISSIONS = [
        // Открывается ли раздел вообще. Ставится не галочкой, а самим выбором
        // полей: позиция, которая не читает ни одной строки карточки, в разделе
        // ничего и не найдёт.
        'employees.view' => ['Просмотр', 'Список сотрудников и их карточки. Что именно видно в карточке — выбирается по строкам.'],

        // A new colleague is put on the books whole, every line of the card at
        // once, so adding somebody is an action rather than a line: the rights to
        // single lines decide what may be changed afterwards, not whether anybody
        // may be added at all.
        'employees.create' => ['Добавление', 'Завести нового сотрудника и заполнить его карточку целиком.'],
        'employees.transfer' => ['Перевод', 'Перевести сотрудника в другой отдел или на другую должность.'],
        'employees.fire' => ['Увольнение', 'Уволить сотрудника и восстановить уволенного.'],
        'employees.delete' => ['Удаление', 'Удалить сотрудника вместе со всем, что на него записано.'],

        // У оборудования обычных прав нет вовсе: просмотр — это три области со
        // своими журналами, изменение — блоки карточки, действия — операции над
        // единицей. Всё это живёт в App\Support\EquipmentAccess.

        // У справочников обычных прав тоже нет: это пять отдельных списков, и
        // каждый открывается и меняется сам по себе (App\Support\Directories).
    ];

    /**
     * What a position gets when it is first created: everybody who works here
     * may look around. Anything beyond looking is handed out deliberately, on
     * the access page.
     *
     * @var list<string>
     */
    public const DEFAULTS = ['employees.view', 'equipment.view.own'];

    /**
     * The same, with the fields a card used to show any colleague: a position is
     * created able to see what was never private in the first place.
     *
     * @return list<string>
     */
    public static function defaults(): array
    {
        return [...self::DEFAULTS, ...EmployeeFields::defaultPermissions()];
    }

    /**
     * Every right there is: the sections, and then one per field of an employee
     * card — those are rights like any other, so positions, personal exceptions
     * and Gate::before apply to them unchanged.
     *
     * @return list<string>
     */
    public static function keys(): array
    {
        return [
            ...array_keys(self::PERMISSIONS),
            ...EmployeeFields::permissions(),
            ...EquipmentAccess::permissions(),
            ...Directories::permissions(),
        ];
    }

    /**
     * The rights a table of positions against rights shows as columns: the
     * sections only. The lines of a card are four dozen rights and belong in a
     * dialog behind a counter, not in a column each.
     *
     * @return list<string>
     */
    public static function sectionKeys(): array
    {
        return array_keys(self::PERMISSIONS);
    }

    /**
     * What one does to a colleague rather than to a line of their card, which is
     * what the "Действия" column of the table holds. Adding somebody is among
     * them: it is not a line of anybody's card but the card itself.
     *
     * @var list<string>
     */
    public const ACTIONS = ['employees.create', 'employees.transfer', 'employees.fire', 'employees.delete'];

    /**
     * The roles a picker may offer: everything but the one there is only ever one
     * of. The directory still lists it — it exists, and its holder is countable —
     * but nowhere is it offered as a choice.
     *
     * @return Builder<Role>
     */
    public static function offeredRoles(): Builder
    {
        return Role::query()->whereNot('name', self::SOLE_ROLE)->orderBy('title');
    }

    /**
     * Why this person may not change that person's positions, or null when they
     * may.
     *
     * Two rules, both for people who could otherwise strip each other of what
     * they may do. A person who may change the access table must not have their
     * positions rearranged from outside: take their positions away and you have
     * taken away what they may do. And two people who may both change positions
     * do not change each other's. In either case the positions are the person's
     * own and the system administrator's — nobody else's, however much they were
     * granted.
     *
     * Everybody else's positions are an ordinary line of a card, opened by the
     * ordinary right to that line. One's own card is the same: if the line is open
     * on the access page, it is open.
     *
     * The reason is a sentence rather than a flag, because the card shows it
     * beside the locked field and the form repeats it if a request comes anyway.
     */
    public static function rolesLockedReason(User $actor, User $employee): ?string
    {
        if ($actor->hasRole(self::SOLE_ROLE) || $actor->is($employee)) {
            return null;
        }

        if (Directories::canEdit($employee, 'access')) {
            return 'Роли сотрудника, который сам распоряжается доступами, меняет только он или системный администратор.';
        }

        // Two people who may both rearrange positions would otherwise settle
        // a quarrel by taking each other's away, so neither may touch the
        // other's: that is left to the person themselves and the one account.
        if (self::changesPositions($actor) && self::changesPositions($employee)) {
            return 'Роли сотрудника, который сам может менять роли, меняет только он или системный администратор.';
        }

        return null;
    }

    /** Whether somebody may change the «Роль» line of other people's cards. */
    private static function changesPositions(User $user): bool
    {
        return in_array('roles', EmployeeFields::editableBy($user), true);
    }

    public static function has(string $key): bool
    {
        return isset(self::PERMISSIONS[$key]);
    }

    /** The section a right belongs to: "employees.view" => "employees". */
    public static function section(string $key): string
    {
        return explode('.', $key)[0];
    }

    /**
     * The catalogue as the access page reads it: the sections in order, each
     * with its rights.
     *
     * @return list<array{key: string, title: string, rights: list<array{key: string, title: string, hint: string}>}>
     */
    public static function tree(): array
    {
        $tree = [];

        foreach (self::SECTIONS as $section => $title) {
            $rights = [];

            foreach (self::PERMISSIONS as $key => [$action, $hint]) {
                if (self::section($key) === $section) {
                    $rights[] = ['key' => $key, 'title' => $action, 'hint' => $hint];
                }
            }

            $tree[] = ['key' => $section, 'title' => $title, 'rights' => $rights];
        }

        return $tree;
    }
}
