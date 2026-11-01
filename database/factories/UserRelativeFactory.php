<?php

namespace Database\Factories;

use App\Models\UserRelative;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserRelative>
 */
class UserRelativeFactory extends Factory
{
    /** Who a card usually names, in the words it names them with. */
    private const RELATIONS = ['Мать', 'Отец', 'Брат', 'Сестра', 'Супруга', 'Супруг'];

    /** And what they do, as one line of the sheet. */
    private const OCCUPATIONS = [
        'Пенсионерка',
        'Пенсионер',
        'Учитель, СОШ № 12',
        'Студент, ТТУ им. Осими',
        'Врач, городская поликлиника № 3',
        'Не работает',
    ];

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'relation' => fake()->randomElement(self::RELATIONS),
            'full_name' => fake('ru_RU')->name(),
            'birth_year' => fake()->numberBetween(1940, 2010),
            'occupation' => fake()->randomElement(self::OCCUPATIONS),
        ];
    }
}
