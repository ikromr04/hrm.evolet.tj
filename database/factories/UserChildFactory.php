<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\UserChild;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<UserChild>
 */
class UserChildFactory extends Factory
{
    private const BOYS = ['Амир', 'Юсуф', 'Сомон', 'Мухаммад', 'Идрис', 'Одил', 'Сино', 'Далер', 'Азиз', 'Бахтовар'];

    private const GIRLS = ['Сумая', 'Асал', 'Мехрона', 'Рухшона', 'Самира', 'Мадина', 'Нигора', 'Зарина', 'Малика', 'Ойша'];

    /**
     * Where a child spends their days, in the words a card uses for it.
     *
     * The line is the one the rest of the family answers, so it reads the same
     * way; null among them because it is the honest answer about a toddler, and
     * because half the children on any card have nothing written there.
     */
    private const OCCUPATIONS = [null, null, 'Школьник', 'Школьница', '3 класс, СОШ № 12', 'Студент, ТНУ', 'Не учится'];

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'full_name' => fake()->randomElement([...self::BOYS, ...self::GIRLS]),
            // A month and a year, kept as the first of the month, as every
            // date in «Состав семьи» is.
            'birth_date' => Carbon::parse(fake()->dateTimeBetween('-25 years', '-1 month'))->startOfMonth()->toDateString(),
            'occupation' => fake()->randomElement(self::OCCUPATIONS),
        ];
    }

    /**
     * A child carrying the family surname, in the form that matches the child's sex:
     * a son of "Каримова" is "Каримов Амир".
     */
    public function ofFamily(string $surname): static
    {
        return $this->state(function () use ($surname) {
            $girl = fake()->boolean();
            $base = preg_replace('/а$/u', '', $surname);

            return ['full_name' => ($girl ? $base.'а' : $base).' '.fake()->randomElement($girl ? self::GIRLS : self::BOYS)];
        });
    }
}
