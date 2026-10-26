<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * What a card says about a person, block by block and line by line, and who may
 * read or change which.
 *
 * A position is given the lines it needs rather than "personal data" as a lump:
 * the head of a department has business knowing a telephone number and none at
 * all knowing a passport. Reading and changing are asked separately — plenty of
 * people should see a passport and nobody but HR should retype one.
 *
 * Each line is two rights, so the whole machinery around rights applies to them
 * unchanged: a position carries them, a personal exception overrules them, and
 * an administrator passes every check.
 *
 * The same lines are asked about twice over, because the two questions are not
 * the same one: what a position may read on a colleague's card, and what it may
 * read on its own. Somebody who has no business in anybody else's passport still
 * has a passport of their own, and a company may well let nobody correct their
 * own hire date. So each line is four rights — read and change, somebody else's
 * card and one's own — and the scope says which pair is meant.
 *
 * The surname and the name are not on the list. A list of colleagues whose names
 * are hidden is a list of blank rows, and a card with no name on it answers
 * nothing; so those two are always readable and the rest is decided.
 */
final class EmployeeFields
{
    /**
     * The blocks a card is read in, in the order it shows them.
     *
     * key => [title, whether the block is chosen line by line]. A block that is
     * not — a passport, a family — is one answer: either it is open or it is not,
     * because half a passport is no use to anybody.
     *
     * @var array<string, array{string, bool}>
     */
    public const GROUPS = [
        'main' => ['Основные данные', true],
        'passport' => ['Паспорт', false],
        'contacts' => ['Контакты', true],
        'family' => ['Семья', false],
        'languages' => ['Знание языков', false],
        'employment' => ['Начало и стаж работы', false],
        'education' => ['Образование', false],
        'experience' => ['Трудовая деятельность', false],
        'equipment' => ['Оборудование', false],
    ];

    /**
     * key => [group, what it is called on the card].
     *
     * The key names the thing a person reads, not the column behind it: the
     * passport block shows "Серия и номер" as one line, so it is one field.
     *
     * @var array<string, array{string, string}>
     */
    public const FIELDS = [
        // A card with no name on it answers nothing, so these two are never closed
        // — but changing them is a right like any other.
        'surname' => ['main', 'Фамилия'],
        'name' => ['main', 'Имя'],
        'patronymic' => ['main', 'Отчество'],
        // The photograph is a line of the card like any other: it can be closed to
        // a position, and then the card shows the initials instead.
        'avatar' => ['main', 'Фотография'],
        'sex' => ['main', 'Пол'],
        'birth_date' => ['main', 'Дата рождения'],
        'birth_place' => ['main', 'Место рождения'],
        'citizenship' => ['main', 'Гражданство'],
        'nationality' => ['main', 'Национальность'],
        'home_address' => ['main', 'Домашний адрес'],
        'roles' => ['main', 'Роль'],
        'positions' => ['main', 'Должность'],
        'departments' => ['main', 'Отдел'],
        // The company's own number for a person, the kind of work they were
        // taken on for and the place they work from. All three sit on the
        // account, so a card reads them without opening its private side.
        'employee_number' => ['main', 'Номер сотрудника'],
        'employment_type' => ['main', 'Тип занятости'],
        'branch' => ['main', 'Филиал'],

        'passport_number' => ['passport', 'Серия и номер'],
        'passport_issued_at' => ['passport', 'Дата выдачи'],
        'passport_issued_by' => ['passport', 'Кем выдан'],

        'email' => ['contacts', 'Электронная почта'],
        'phone' => ['contacts', 'Телефон'],
        'sos_phone' => ['contacts', 'Телефон SOS'],

        'marital_status' => ['family', 'Семейное положение'],
        'spouse' => ['family', 'Супруг и его дата рождения'],
        'children' => ['family', 'Дети'],

        'languages' => ['languages', 'Языки и уровень'],

        'hired_at' => ['employment', 'Начало работы и стаж'],

        'educations' => ['education', 'Учебные заведения'],

        'work_experiences' => ['experience', 'Места работы'],

        'equipment' => ['equipment', 'Оборудование на руках'],
    ];

    /**
     * What a position may read the moment it is created: everything that used to
     * be on a card for any colleague, and nothing that used to be private.
     *
     * @var list<string>
     */
    public const PUBLIC_FIELDS = ['avatar', 'patronymic', 'sex', 'roles', 'positions', 'departments', 'email', 'languages'];

    /**
     * The fields kept beside the account rather than on it: the row a card loads
     * separately, and the lists hanging off it. Nothing here is read unless one
     * of these is open, which saves loading it at all.
     *
     * @var list<string>
     */
    public const PRIVATE_FIELDS = [
        'birth_date', 'birth_place', 'citizenship', 'nationality', 'home_address',
        'passport_number', 'passport_issued_at', 'passport_issued_by',
        'phone', 'sos_phone',
        'marital_status', 'spouse', 'children',
        'educations', 'work_experiences', 'hired_at', 'equipment',
    ];

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::FIELDS);
    }

    /**
     * Whose card is being asked about: anybody else's, or the person's own.
     */
    public const OTHERS = 'employees';

    public const OWN = 'profile';

    /** @var list<string> */
    public const SCOPES = [self::OTHERS, self::OWN];

    /** The right to read a line: "birth_date" => "employees.field.birth_date". */
    public static function permission(string $field, string $scope = self::OTHERS): string
    {
        return "{$scope}.field.{$field}";
    }

    /** The right to change it: "birth_date" => "employees.edit.birth_date". */
    public static function editPermission(string $field, string $scope = self::OTHERS): string
    {
        return "{$scope}.edit.{$field}";
    }

    /**
     * The gate behind a block, which is what a form that saves the whole block
     * is guarded by: "passport" => "employees.edit.block.passport".
     */
    public static function blockGate(string $group): string
    {
        return "employees.edit.block.{$group}";
    }

    /**
     * Every line as a right: read and change, for somebody else's card and for
     * one's own. What the seeder creates and the pages count.
     *
     * @return list<string>
     */
    /**
     * The lines that are never closed to anybody.
     *
     * A list of blank rows would be no list, and a card with no name on it answers
     * nothing — so these are read without a right. Changing them is another matter
     * and has one.
     *
     * @var list<string>
     */
    public const ALWAYS_VISIBLE = ['surname', 'name'];

    public static function permissions(): array
    {
        $rights = [];

        foreach (self::SCOPES as $scope) {
            foreach (self::keys() as $field) {
                // No right to read what everybody reads: the box beside those lines
                // is ticked and cannot be untucked, so a right behind it would only
                // be something to get wrong.
                if (! in_array($field, self::ALWAYS_VISIBLE, true)) {
                    $rights[] = self::permission($field, $scope);
                }

                $rights[] = self::editPermission($field, $scope);
            }
        }

        return $rights;
    }

    /**
     * The fields of a position's default set, as rights. Reading only: what a
     * position may change is decided deliberately.
     *
     * On a colleague's card that is what was never private. On one's own it is
     * the whole card — a person has always been able to read their own, and
     * taking that away is a decision somebody should make on purpose.
     *
     * @return list<string>
     */
    public static function defaultPermissions(): array
    {
        // The lines nobody can close have no right to read them, so there is
        // nothing to hand out for those.
        $chosen = array_diff(self::keys(), self::ALWAYS_VISIBLE);

        return [
            ...array_map(fn (string $field) => self::permission($field), array_diff(self::PUBLIC_FIELDS, self::ALWAYS_VISIBLE)),
            ...array_map(fn (string $field) => self::permission($field, self::OWN), $chosen),
        ];
    }

    /** The fields of one block, in the order the card shows them. */
    public static function ofGroup(string $group): array
    {
        return array_keys(array_filter(self::FIELDS, fn (array $field) => $field[0] === $group));
    }

    /**
     * The catalogue as a dialog reads it: the blocks in order, each with its
     * lines and the two rights behind them. Built per scope, so a dialog need
     * never know whose card it is choosing for.
     *
     * @return list<array{key: string, title: string, expandable: bool, fields: list<array{key: string, title: string, permission: string, editPermission: string}>}>
     */
    public static function tree(string $scope = self::OTHERS): array
    {
        $tree = [];

        foreach (self::GROUPS as $group => [$title, $expandable]) {
            $fields = [];

            foreach (self::FIELDS as $key => [$in, $label]) {
                if ($in === $group) {
                    $fields[] = [
                        'key' => $key,
                        'title' => $label,
                        // Never closed: the dialog shows the box ticked and untouchable
                        // rather than pretending there is a choice.
                        'always' => in_array($key, self::ALWAYS_VISIBLE, true),
                        'permission' => self::permission($key, $scope),
                        'editPermission' => self::editPermission($key, $scope),
                    ];
                }
            }

            $tree[] = ['key' => $group, 'title' => $title, 'expandable' => $expandable, 'fields' => $fields];
        }

        return $tree;
    }

    /** Whose card this is, from the point of view of whoever is looking at it. */
    public static function scopeFor(User $viewer, ?User $employee): string
    {
        return $employee !== null && $viewer->is($employee) ? self::OWN : self::OTHERS;
    }

    /**
     * Which lines this viewer may read on that person's card — their own or
     * anybody else's, which are two different sets of rights.
     *
     * @return list<string>
     */
    /**
     * Whether a photograph of this person is theirs to look at.
     *
     * Faces turn up far from the card — beside a laptop's holder, in the tree of
     * the company, in the journal of who moved what — and all of them are the same
     * line of the same card, so all of them ask the same question here.
     */
    public static function showsAvatar(User $viewer, ?User $employee = null): bool
    {
        return in_array('avatar', self::visibleTo($viewer, $employee), true);
    }

    public static function visibleTo(User $viewer, ?User $employee = null): array
    {
        $scope = self::scopeFor($viewer, $employee);

        return array_values(array_filter(
            self::keys(),
            fn (string $field) => in_array($field, self::ALWAYS_VISIBLE, true) || $viewer->can(self::permission($field, $scope)),
        ));
    }

    /**
     * Which lines this viewer may change. Reading is a condition of changing —
     * nobody retypes what they cannot see — so this is never wider than what
     * visibleTo() gives, own card included.
     *
     * @return list<string>
     */
    public static function editableBy(User $viewer, ?User $employee = null): array
    {
        // Whoever is putting a colleague on the books fills the whole card in,
        // whatever lines they may change on anybody else's: the right to add
        // somebody is the right to their entire card, for as long as the adding
        // lasts. After that the card is edited like any other.
        if (self::isBeingCreatedBy($viewer, $employee)) {
            return self::keys();
        }

        $scope = self::scopeFor($viewer, $employee);
        $visible = self::visibleTo($viewer, $employee);

        return array_values(array_filter(
            self::keys(),
            fn (string $field) => in_array($field, $visible, true) && $viewer->can(self::editPermission($field, $scope)),
        ));
    }

    /**
     * Where the colleagues somebody is adding right now are remembered: the ids
     * in their own session, so the window belongs to that person and that sign-in
     * and needs nothing in the database.
     */
    public const CREATING = 'employees.creating';

    /**
     * The colleague just put on the books by whoever is signed in: the wizard
     * goes on to fill the rest of the card in, step by step, through the same
     * forms the card uses, and each of those asks editableBy().
     *
     * One at a time: «Сохранить и добавить ещё» never opens the card it leaves
     * behind, so starting the next colleague is what closes the last one.
     */
    public static function startCreating(User $employee): void
    {
        session()->put(self::CREATING, [$employee->id]);
    }

    /**
     * The adding is over: the card is opened as a card, and from now on it is
     * edited under the ordinary rights to its lines.
     */
    public static function finishCreating(User $employee): void
    {
        $left = array_values(array_diff((array) session()->get(self::CREATING, []), [$employee->id]));

        $left === [] ? session()->forget(self::CREATING) : session()->put(self::CREATING, $left);
    }

    /**
     * Whether this viewer is in the middle of adding that colleague. Asked only of
     * whoever is signed in — the session is theirs, and somebody else's rights are
     * not read from it — and only while they still hold the right to add anybody,
     * so taking the right away closes the window too.
     */
    public static function isBeingCreatedBy(User $viewer, ?User $employee): bool
    {
        if ($employee === null || $viewer->is($employee) || Auth::id() !== $viewer->getKey()) {
            return false;
        }

        return in_array($employee->id, (array) session()->get(self::CREATING, []), true)
            && $viewer->can('employees.create');
    }

    /**
     * Whether anything kept beside the account is readable at all, which decides
     * whether the row behind it is worth loading.
     */
    public static function anyPrivateVisibleTo(User $viewer, ?User $employee = null): bool
    {
        $scope = self::scopeFor($viewer, $employee);

        foreach (self::PRIVATE_FIELDS as $field) {
            if ($viewer->can(self::permission($field, $scope))) {
                return true;
            }
        }

        return false;
    }
}
