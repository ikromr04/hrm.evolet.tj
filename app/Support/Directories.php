<?php

namespace App\Support;

use App\Models\User;

/**
 * Which of the reference lists a position may open, and which it may change.
 *
 * "Справочники" is not one section but several lists that happen to share a
 * page: the positions a company has, the job titles people hold, the tree of
 * departments, the languages they speak, the countries they are citizens of and
 * the categories of hardware. They are
 * kept by different people — an HR officer renames job titles, whoever looks
 * after the fleet adds a category of monitors — so each list is a right of its
 * own, and changing one takes being able to read it first.
 */
final class Directories
{
    /**
     * key => [what the tab is called, what the list holds].
     *
     * The order is the order of the tabs, because the page lands on the first
     * list a person may open.
     *
     * @var array<string, array{string, string}>
     */
    public const LISTS = [
        'roles' => [
            'Позиции',
            'Позиции и то, что каждая из них открывает в системе.',
        ],
        'positions' => [
            'Должности',
            'Названия должностей, которые носят сотрудники.',
        ],
        'departments' => [
            'Отделы',
            'Дерево отделов и департаментов компании.',
        ],
        'languages' => [
            'Языки',
            'Языки, владение которыми указывают в карточке.',
        ],
        'citizenships' => [
            'Гражданства',
            'Страны, гражданство которых указывают в карточке.',
        ],
        'equipment' => [
            'Категории техники',
            'Категории и поля, которыми описываются их единицы.',
        ],
        // A list like the others, and the heaviest one: whoever may change it
        // decides what every position opens, this one included.
        'access' => [
            'Доступы',
            'Таблица позиций и прав, вместе с личными исключениями на карточках.',
        ],
    ];

    /** The right to read one list. */
    public static function viewPermission(string $list): string
    {
        return "directories.view.{$list}";
    }

    /** The right to add to it, rename in it and delete from it. */
    public static function editPermission(string $list): string
    {
        return "directories.edit.{$list}";
    }

    /**
     * Every right the catalogue holds for the section.
     *
     * @return list<string>
     */
    public static function permissions(): array
    {
        $rights = [];

        foreach (array_keys(self::LISTS) as $list) {
            $rights[] = self::viewPermission($list);
            $rights[] = self::editPermission($list);
        }

        return $rights;
    }

    /**
     * The lists as a dialog of plain rights reads them: the right itself is the
     * key, so the same window serves these as serves any other short list.
     *
     * @return list<array{key: string, title: string, hint: string}>
     */
    public static function viewTree(): array
    {
        $tree = [];

        foreach (self::LISTS as $list => [$title, $hint]) {
            $tree[] = ['key' => self::viewPermission($list), 'title' => $title, 'hint' => $hint];
        }

        return $tree;
    }

    /**
     * The same for changing them. Each one names the right it depends on, so the
     * dialog can grey out a list nobody may even read rather than letting a
     * right be ticked that would never answer yes.
     *
     * @return list<array{key: string, title: string, hint: string, requires: array{key: string, hint: string}}>
     */
    public static function editTree(): array
    {
        $tree = [];

        foreach (self::LISTS as $list => [$title, $hint]) {
            $tree[] = [
                'key' => self::editPermission($list),
                'title' => $title,
                'hint' => "Добавление, переименование и удаление: {$hint}",
                'requires' => [
                    'key' => self::viewPermission($list),
                    'hint' => "Сначала откройте «{$title}» в «Просмотре»: менять можно только то, что видно.",
                ],
            ];
        }

        return $tree;
    }

    /**
     * Which lists this person may open.
     *
     * @return list<string>
     */
    public static function visibleTo(User $user): array
    {
        return array_values(array_filter(
            array_keys(self::LISTS),
            fn (string $list) => $user->can(self::viewPermission($list)),
        ));
    }

    /**
     * Which of them they may change. Reading is a condition: a list one cannot
     * open is not a list one renames entries in.
     *
     * @return list<string>
     */
    public static function editableBy(User $user): array
    {
        return array_values(array_filter(
            self::visibleTo($user),
            fn (string $list) => $user->can(self::editPermission($list)),
        ));
    }

    /** Whether the section is open at all. */
    public static function sees(User $user): bool
    {
        return self::visibleTo($user) !== [];
    }

    /** Whether one list in particular is theirs to change. */
    public static function canEdit(User $user, string $list): bool
    {
        return $user->can(self::editPermission($list)) && $user->can(self::viewPermission($list));
    }

    /**
     * Which list the section opens on: the first one this person may read, so
     * "Справочники" never lands on a page they would be refused.
     */
    public static function firstFor(User $user): ?string
    {
        return self::visibleTo($user)[0] ?? null;
    }
}
