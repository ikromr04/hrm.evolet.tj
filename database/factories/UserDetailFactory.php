<?php

namespace Database\Factories;

use App\Models\Citizenship;
use App\Models\User;
use App\Models\UserDetail;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserDetail>
 */
class UserDetailFactory extends Factory
{
    private const CITIES = ['г. Душанбе', 'г. Худжанд', 'г. Бохтар', 'г. Куляб', 'г. Истаравшан', 'г. Турсунзаде', 'г. Вахдат', 'г. Гиссар'];

    /** [male, female], weighted towards Tajik. */
    private const NATIONALITIES = [
        ['таджик', 'таджичка'], ['таджик', 'таджичка'], ['таджик', 'таджичка'], ['таджик', 'таджичка'],
        ['таджик', 'таджичка'], ['таджик', 'таджичка'], ['узбек', 'узбечка'], ['русский', 'русская'],
    ];

    private const FEMALE_NAMES = ['Дилором', 'Мехринисо', 'Зарина', 'Малика', 'Нигина', 'Фарзона', 'Мадина', 'Гулнора'];

    private const MALE_NAMES = ['Рустам', 'Фаррух', 'Далер', 'Умед', 'Джамшед', 'Азиз', 'Сухроб', 'Бахтиёр'];

    private const STREETS = ['пр. Рудаки', 'ул. Айни', 'ул. А. Навои', 'ул. Бохтар', 'ул. Шотемур', 'пр. И. Сомони', 'ул. Лахути', 'ул. Фирдавси'];

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'hired_at' => fake()->dateTimeBetween('-10 years', '-1 month'),
            'birth_date' => fake()->dateTimeBetween('-60 years', '-21 years'),
            'birth_place' => fake()->randomElement(self::CITIES),
            'nationality' => null, // picked in configure() to match the employee's sex
            'passport_series' => 'А',
            'passport_number' => fake()->numerify('0#######'),
            'passport_issued_at' => fake()->dateTimeBetween('-9 years', '-1 month'),
            'passport_issued_by' => 'МВД Республики Таджикистан',
            'marital_status' => fake()->randomElement(['single', 'married']),
            'home_address' => sprintf('г. Душанбе, %s %d, кв. %d', fake()->randomElement(self::STREETS), fake()->numberBetween(1, 120), fake()->numberBetween(1, 180)),
            'phone' => $this->phone(),
            'sos_phone' => $this->phone(),
            'sos_contact' => null, // picked in configure(): a relative, named to match the sex
        ];
    }

    /**
     * Nationality agrees with the employee's sex: "таджик" / "таджичка".
     */
    public function configure(): static
    {
        return $this->afterMaking(function (UserDetail $details) {
            if ($details->nationality === null && $details->user) {
                [$male, $female] = fake()->randomElement(self::NATIONALITIES);
                $details->nationality = $details->user->sex === 'female' ? $female : $male;
            }

            // The spouse is of the other sex and shares the surname, which in
            // Russian gains or drops its feminine "а" accordingly.
            if ($details->spouse_name === null && $details->marital_status === 'married' && $details->user) {
                $wife = $details->user->sex === 'male';
                $surname = $wife
                    ? $details->user->surname.'а'
                    : rtrim($details->user->surname, 'а');

                $details->spouse_name = $surname.' '.fake()->randomElement($wife ? self::FEMALE_NAMES : self::MALE_NAMES);
                $details->spouse_birth_date = fake()->dateTimeBetween('-58 years', '-22 years');
            }

            if ($details->sos_contact === null && $details->user) {
                $married = $details->marital_status === 'married';
                $relation = fake()->randomElement($married
                    ? ($details->user->sex === 'female' ? ['Муж', 'Мама', 'Сестра'] : ['Жена', 'Мама', 'Брат'])
                    : ['Мама', 'Папа', 'Сестра', 'Брат']);
                $female = in_array($relation, ['Жена', 'Мама', 'Сестра'], true);
                $details->sos_contact = "{$relation} — ".fake()->randomElement($female ? self::FEMALE_NAMES : self::MALE_NAMES);
            }
        })->afterCreating(function (UserDetail $details) {
            // Citizenship lives in its own directory, linked to the person; a
            // card filled in from scratch says Tajikistan unless told otherwise.
            if ($details->user && $details->user->citizenships()->doesntExist()) {
                $details->user->citizenships()->attach(Citizenship::idsFor(['Таджикистан']));
            }
        });
    }

    /** Tajik mobile number in E.164, e.g. +992901234567. */
    private function phone(): string
    {
        return '+992'.fake()->randomElement(['90', '91', '92', '93', '98', '88', '55', '50', '77']).fake()->numerify('#######');
    }
}
