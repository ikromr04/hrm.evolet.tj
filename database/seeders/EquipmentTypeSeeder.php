<?php

namespace Database\Seeders;

use App\Models\EquipmentField;
use App\Models\EquipmentType;
use Illuminate\Database\Seeder;

class EquipmentTypeSeeder extends Seeder
{
    /**
     * Categories, exactly as the design's "Категории техники" names them, each
     * with the drawing it is shown by. A category added later picks its own in
     * the directory.
     */
    public const TYPES = [
        'Ноутбуки' => 'laptop',
        'Мониторы' => 'monitor',
        'Телефоны' => 'smartphone',
        'Печать' => 'printer',
        'Периферия' => 'headphones',
    ];

    /**
     * What is printed on any piece of hardware, whatever kind it is. Kept as
     * fields rather than as columns of the units table, because "no processor"
     * and "no serial number" are both things a category is entitled to say.
     *
     * @var array<string, string>
     */
    private const PRINTED_ON_IT = [
        'Производитель' => 'text',
        'Модель' => 'text',
        'Серийный номер' => 'text',
    ];

    /**
     * What units of each category are described by. A monitor has a diagonal and
     * no processor; a phone has an IMEI. A starting point rather than a rule —
     * the directory is where these are added, renamed and dropped.
     *
     * name => [type, options] for a list, or name => type for the rest.
     *
     * @var array<string, array<string, mixed>>
     */
    public const FIELDS = [
        'Ноутбуки' => [
            ...self::PRINTED_ON_IT,
            'Процессор' => 'text',
            'Память / диск' => 'text',
            'Год выпуска' => 'number',
            'Гарантия до' => 'date',
        ],
        'Мониторы' => [
            ...self::PRINTED_ON_IT,
            'Диагональ, дюймы' => 'number',
            'Разрешение' => ['select', ['1920×1080', '2560×1440', '3440×1440', '3840×2160']],
            'Тип матрицы' => ['select', ['IPS', 'VA', 'TN', 'OLED']],
        ],
        'Телефоны' => [
            ...self::PRINTED_ON_IT,
            'IMEI' => 'text',
            'Номер' => 'text',
            'Корпоративная SIM' => 'boolean',
        ],
        'Печать' => [
            ...self::PRINTED_ON_IT,
            'Цветная печать' => 'boolean',
            'Формат' => ['select', ['A4', 'A3']],
            'Ресурс картриджа' => 'text',
        ],
        'Периферия' => [
            ...self::PRINTED_ON_IT,
            'Подключение' => ['select', ['USB', 'Bluetooth', 'Wi-Fi', 'Jack 3.5']],
        ],
    ];

    public function run(): void
    {
        foreach (self::TYPES as $name => $icon) {
            $type = EquipmentType::firstOrCreate(['name' => $name], ['icon' => $icon]);

            // What a unit is called and the number on its sticker are fields of
            // the category like the rest, and the two it cannot be without, so
            // they head the list. Matched by role rather than by name: a
            // category is free to call them by its own words.
            foreach (array_keys(EquipmentField::ROLES) as $position => $role) {
                $type->fields()->firstOrCreate(
                    ['role' => $role],
                    ['name' => EquipmentField::ROLES[$role], 'type' => 'text', 'required' => true, 'position' => $position],
                );
            }

            foreach (array_values(self::FIELDS[$name] ?? []) as $position => $field) {
                [$kind, $options] = is_array($field) ? $field : [$field, null];
                $label = array_keys(self::FIELDS[$name])[$position];

                // Left alone once it exists: the directory is where these are
                // decided, and a re-seed should not undo somebody's work.
                $type->fields()->firstOrCreate(
                    ['name' => $label],
                    ['type' => $kind, 'options' => $options, 'required' => false, 'position' => $position + count(EquipmentField::ROLES)],
                );
            }
        }
    }
}
