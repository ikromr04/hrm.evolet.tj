<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\UserEducation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserEducation>
 */
class UserEducationFactory extends Factory
{
    /** Institution => [faculty, specialties]. */
    private const SCHOOLS = [
        'Таджикский национальный университет' => ['Экономический факультет', ['Финансы и кредит', 'Бухгалтерский учёт', 'Менеджмент']],
        'Таджикский государственный медицинский университет им. Абуали ибни Сино' => ['Фармацевтический факультет', ['Фармация', 'Клиническая фармакология']],
        'Таджикский технический университет им. М. Осими' => ['Факультет информационных технологий', ['Программная инженерия', 'Автоматизация', 'Информационная безопасность']],
        'Российско-Таджикский (Славянский) университет' => ['Филологический факультет', ['Лингвистика', 'Перевод и переводоведение', 'Журналистика']],
        'Таджикский государственный университет коммерции' => ['Факультет маркетинга', ['Маркетинг', 'Международная торговля']],
        'Худжандский государственный университет им. Б. Гафурова' => ['Факультет иностранных языков', ['Английский язык', 'Немецкий язык']],
        'Технологический университет Таджикистана' => ['Факультет дизайна', ['Графический дизайн', 'Дизайн среды']],
    ];

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $institution = fake()->randomElement(array_keys(self::SCHOOLS));
        [$faculty, $specialties] = self::SCHOOLS[$institution];
        $started = fake()->numberBetween(1995, 2020);

        return [
            'user_id' => User::factory(),
            'institution' => $institution,
            'faculty' => $faculty,
            'specialty' => fake()->randomElement($specialties),
            'qualification' => fake()->randomElement(['бакалавр', 'бакалавр', 'магистр', 'специалист', 'инженер']),
            'started_year' => $started,
            'graduated_year' => $started + fake()->randomElement([4, 4, 5, 5, 6]),
        ];
    }

    /** Started at 17–19 given the person's birth date, and not before they could. */
    public function forBirthDate(?\DateTimeInterface $birthDate): static
    {
        return $this->state(function (array $attributes) use ($birthDate) {
            if (! $birthDate) {
                return [];
            }
            $started = (int) $birthDate->format('Y') + fake()->numberBetween(17, 19);
            $graduated = $started + fake()->randomElement([4, 4, 5, 5, 6]);

            return ['started_year' => $started, 'graduated_year' => $graduated <= (int) date('Y') ? $graduated : null];
        });
    }
}
