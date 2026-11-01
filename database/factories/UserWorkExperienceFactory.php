<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\UserWorkExperience;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserWorkExperience>
 */
class UserWorkExperienceFactory extends Factory
{
    private const ORGANIZATIONS = [
        'ОАО «Ориёнбанк»',
        'ЗАО «Бонки Эсхата»',
        'ООО «Тcell»',
        'ООО «Фарм Дистрибьюшн»',
        'ГУ «Республиканский центр лекарственных средств»',
        'ООО «Шифобахш»',
        'ООО «Nika Pharm»',
        'ООО «Фармстандарт»',
        'ТОО «Европа-Фарм»',
        'Представительство «Эволет» в Европе',
    ];

    /** Why such a job ended, in the words a labour book uses. */
    private const REASONS = [
        'По собственному желанию',
        'Перевод на другую работу',
        'Окончание срока договора',
        'Соглашение сторон',
        'Сокращение штата',
    ];

    private const POSITIONS = [
        'Менеджер по продажам', 'Бухгалтер', 'Фармацевт', 'Медицинский представитель', 'Специалист по регистрации',
        'Переводчик', 'Маркетолог', 'Дизайнер', 'Аналитик', 'Офис-менеджер', 'Программист', 'Специалист отдела кадров',
    ];

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $started = fake()->numberBetween(2008, 2020);

        return [
            'user_id' => User::factory(),
            'organization' => fake()->randomElement(self::ORGANIZATIONS),
            'position' => fake()->randomElement(self::POSITIONS),
            'started_month' => fake()->numberBetween(1, 12),
            'started_year' => $started,
            'ended_month' => fake()->numberBetween(1, 12),
            'ended_year' => $started + fake()->numberBetween(1, 4),
            // The job ended, so it says why: the card asks the two together.
            'reason' => fake()->randomElement(self::REASONS),
        ];
    }

    /**
     * A job that starts in the given month and lasts 1–4 years, ending no
     * later than a month before `$until` (the next job or joining Evolet).
     */
    public function between(int $fromYear, int $fromMonth, int $untilYear, int $untilMonth): static
    {
        return $this->state(function () use ($fromYear, $fromMonth, $untilYear, $untilMonth) {
            $start = $fromYear * 12 + $fromMonth - 1;
            $limit = $untilYear * 12 + $untilMonth - 2;
            $end = min($limit, $start + fake()->numberBetween(12, 48));

            return [
                'started_month' => $start % 12 + 1,
                'started_year' => intdiv($start, 12),
                'ended_month' => $end % 12 + 1,
                'ended_year' => intdiv($end, 12),
            ];
        });
    }
}
