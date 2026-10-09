<?php

namespace Database\Factories;

use App\Models\Equipment;
use App\Models\EquipmentType;
use App\Observers\EquipmentObserver;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<Equipment>
 */
class EquipmentFactory extends Factory
{
    /**
     * Real models per category, each with the word the list puts in front:
     * "Ноутбук Dell Latitude 5440", "МФУ Kyocera M2540". The word belongs to
     * the model rather than the category — "Периферия" holds both a headset
     * and a keyboard.
     */
    private const MODELS = [
        'Ноутбуки' => [
            ['Ноутбук', 'HP', 'ProBook 450 G9'], ['Ноутбук', 'Lenovo', 'ThinkPad E14'],
            ['Ноутбук', 'Acer', 'Aspire 5'], ['Ноутбук', 'Dell', 'Latitude 5440'],
        ],
        'Мониторы' => [
            ['Монитор', 'Dell', 'P2422H'], ['Монитор', 'Samsung', 'S24R350'],
            ['Монитор', 'LG', '24MK430'], ['Монитор', 'AOC', '24B2XH'],
        ],
        'Телефоны' => [
            ['Телефон', 'Samsung', 'Galaxy A54'], ['Телефон', 'Xiaomi', 'Redmi 12'], ['Телефон', 'Grandstream', 'GXP1625'],
        ],
        'Печать' => [
            ['МФУ', 'Kyocera', 'M2540'], ['МФУ', 'Canon', 'i-SENSYS MF445dw'], ['Принтер', 'HP', 'LaserJet Pro M404dn'],
        ],
        'Периферия' => [
            ['Гарнитура', 'Jabra', 'Evolve 40'], ['Клавиатура', 'Logitech', 'K120'],
            ['Мышь', 'A4Tech', 'OP-720'], ['Мышь', 'Defender', 'MB-160'],
        ],
    ];

    /** @var list<string> */
    private const PROCESSORS = ['Intel Core i5-1335U', 'Intel Core i5-1235U', 'Intel Core i7-1255U', 'AMD Ryzen 5 5625U', 'AMD Ryzen 7 5825U'];

    /** @var list<string> */
    private const MEMORY = ['8 ГБ / SSD 256 ГБ', '16 ГБ / SSD 512 ГБ', '16 ГБ / SSD 1 ТБ', '32 ГБ / SSD 1 ТБ'];

    /** What comes in the box with each kind of hardware. */
    private const ACCESSORIES = [
        'Ноутбуки' => ['Блок питания 65 Вт', 'Сумка', 'Мышь Logitech M185'],
        'Мониторы' => ['Кабель HDMI', 'Кабель питания', 'Подставка'],
        'Телефоны' => ['Зарядное устройство', 'Чехол'],
        'Печать' => ['Кабель USB', 'Стартовый тонер'],
        'Периферия' => ['Кабель USB'],
    ];

    /**
     * What a caller named a unit by, kept aside until the row exists: both are
     * fields of the unit's category now rather than columns of its own, and a
     * field is only written once there is something to write it against.
     *
     * @var array<int, array{?string, ?string}>
     */
    private static array $identity = [];

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'equipment_type_id' => EquipmentType::factory(),
            'name' => fake()->words(2, true),
            'inventory_number' => 'EV-'.fake()->unique()->numerify('####'),
            'condition' => fake()->randomElement(['Рабочее, без повреждений', 'Рабочее, следы эксплуатации', 'Новое, в упаковке']),
            'checked_at' => fake()->dateTimeBetween('-8 months', 'now'),
            'next_inventory_at' => fake()->dateTimeBetween('+2 months', '+14 months'),
            'status' => 'stock',
        ];
    }

    /**
     * The name and the number travel with the unit rather than into it: they
     * are taken off the attributes here and written into the category's fields
     * once it has been created, so every caller goes on naming a unit the way
     * it always has.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function newModel(array $attributes = []): Model
    {
        $title = $attributes['name'] ?? null;
        $number = $attributes['inventory_number'] ?? null;
        unset($attributes['name'], $attributes['inventory_number']);

        $model = parent::newModel($attributes);
        self::$identity[spl_object_id($model)] = [$title, $number];

        return $model;
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Equipment $unit) {
            [$title, $number] = self::$identity[spl_object_id($unit)] ?? [null, null];
            unset(self::$identity[spl_object_id($unit)]);

            $unit->writeIdentity($title, $number);
            // The arrival was written down before the unit had a number, the
            // number being a field value and a field value needing a row to
            // belong to. Now that it has one, the entry says so.
            EquipmentObserver::named($unit);
        });
    }

    /**
     * A real model from the given category, named the way the list shows it:
     * "Ноутбук Dell Latitude 5440".
     */
    public function ofType(EquipmentType $type): static
    {
        return $this->state(function () use ($type) {
            $models = self::MODELS[$type->name] ?? [[$type->name, null, fake()->word()]];
            [$kind, $maker, $model] = fake()->randomElement($models);

            return [
                'equipment_type_id' => $type->id,
                'name' => trim("{$kind} {$maker} {$model}"),
                // Kept for afterCreating, which writes them where they belong now.
                'accessories' => ($type->has_accessories ?? true) ? self::ACCESSORIES[$type->name] ?? [] : null,
            ];
        })->afterCreating(function (Equipment $unit) use ($type) {
            // Runs after configure() has written the name, so the unit knows it.
            [$maker, $model] = self::modelOf($type, (string) $unit->name);

            $this->fillFields($unit, ['Производитель' => $maker, 'Модель' => $model]);
        });
    }

    /**
     * The maker and the model behind a name the factory made up, so the fields
     * agree with what the unit is called.
     *
     * @return array{?string, ?string}
     */
    private static function modelOf(EquipmentType $type, string $name): array
    {
        foreach (self::MODELS[$type->name] ?? [] as [$kind, $maker, $model]) {
            if (trim("{$kind} {$maker} {$model}") === $name) {
                return [$maker, $model];
            }
        }

        return [null, null];
    }

    /**
     * Something plausible in each of the category's fields, so a demo card is
     * not a page of blanks. A list picks one of its own choices; everything else
     * is made up in the shape the field asks for.
     *
     * @param  array<string, string|null>  $known  Values the caller already has.
     */
    private function fillFields(Equipment $unit, array $known = []): void
    {
        foreach ($unit->type?->fields ?? [] as $field) {
            $value = match (true) {
                array_key_exists($field->name, $known) => $known[$field->name],
                $field->name === 'Серийный номер' => strtoupper(fake()->bothify('#?#?#?#')),
                $field->type === 'select' => fake()->randomElement($field->choices()),
                $field->type === 'boolean' => fake()->boolean() ? '1' : '0',
                $field->type === 'date' => fake()->dateTimeBetween('+2 months', '+3 years')->format('Y-m-d'),
                $field->name === 'Процессор' => fake()->randomElement(self::PROCESSORS),
                $field->name === 'Память / диск' => fake()->randomElement(self::MEMORY),
                $field->name === 'IMEI' => (string) fake()->numerify('###############'),
                $field->name === 'Номер' => '+992 '.fake()->numerify('## ### ####'),
                // A number field is a number field, but a year and a diagonal
                // look silly in each other's place.
                $field->type === 'number' => (string) match (true) {
                    str_contains($field->name, 'Год') => fake()->numberBetween(2019, 2026),
                    str_contains($field->name, 'Диагональ') => fake()->randomElement([21.5, 23.8, 24, 27, 32]),
                    default => fake()->numberBetween(1, 100),
                },
                default => null,
            };

            if ($value === null) {
                continue;
            }

            $unit->fieldValues()->updateOrCreate(['equipment_field_id' => $field->id], ['value' => $value]);
        }
    }

    /** Handed to someone, on a date since they joined. */
    public function issuedTo(int $userId, ?string $since = null): static
    {
        return $this->state(fn () => [
            'status' => 'issued',
            'holder_user_id' => $userId,
            // Up to today: a fleet always has a handover from last week in it,
            // and the journal is asked about recent weeks more than about old ones.
            'issued_at' => fake()->dateTimeBetween($since ?? '-5 years', 'now'),
        ]);
    }

    public function writtenOff(): static
    {
        return $this->state(fn () => [
            'status' => 'written_off',
            'written_off_at' => fake()->dateTimeBetween('-2 years', '-1 month'),
        ]);
    }
}
