<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Rules\LatinEmail;
use App\Support\Access;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The first account of a new installation: the system administrator.
 *
 * Everybody else is added through the application by somebody who may add
 * people — and on the first day there is nobody. The position cannot be handed
 * out from a page either (GuardsPrivilegedRoles), so the one account that holds
 * it is made here, from the server, by whoever installs the system.
 *
 * It asks its questions when run by hand and takes the same answers as options
 * when there is no terminal to ask in: a host without SSH runs it once from
 * cron.
 */
class CreateSysadmin extends Command
{
    protected $signature = 'hrm:sysadmin
        {--surname= : Фамилия}
        {--name= : Имя}
        {--patronymic= : Отчество (необязательно)}
        {--sex= : Пол: male или female}
        {--email= : E-mail, с которым администратор входит в систему}
        {--password= : Пароль; без этой опции команда спросит его скрытым вводом}';

    protected $description = 'Создать системного администратора — единственную учётную запись, которой доступно всё';

    public function handle(): int
    {
        // The position is a row the seeder makes. Without it there is nothing to
        // assign, and making it here would hide that the rest of the seeding —
        // the rights, the directories — has not been done either.
        if (Role::query()->where('name', Access::SOLE_ROLE)->doesntExist()) {
            $this->components->error('Роли «Системный администратор» ещё нет. Сначала выполните: php artisan db:seed --class=ProductionSeeder --force');

            return self::FAILURE;
        }

        // There is exactly one, and no option to override that: a second one is
        // what the application itself refuses to appoint.
        if ($holder = User::role(Access::SOLE_ROLE)->first()) {
            $this->components->error("Системный администратор уже есть: {$holder->surname} {$holder->name} <{$holder->email}>. Второго создать нельзя.");
            $this->line('  Забытый пароль восстанавливается на странице входа — «Забыли пароль?».');

            return self::FAILURE;
        }

        $rules = self::rules();

        $questions = [
            'surname' => ['Фамилия', null],
            'name' => ['Имя', null],
            'patronymic' => ['Отчество (можно оставить пустым)', null],
            'sex' => ['Пол', ['male' => 'мужской', 'female' => 'женский']],
            'email' => ['E-mail', null],
        ];

        $answers = [];

        foreach ($questions as $field => [$question, $choices]) {
            $answers[$field] = $this->answer($field, $question, $rules[$field], $choices);

            if ($answers[$field] === false) {
                return self::FAILURE;
            }
        }

        $password = $this->password($rules['password']);

        if ($password === false) {
            return self::FAILURE;
        }

        $user = DB::transaction(function () use ($answers, $password) {
            $user = User::create([
                ...$answers,
                // Hashed by the model's cast.
                'password' => $password,
                'status' => 'active',
            ]);

            // Whoever installs the system typed the address in themselves, so there
            // is nothing left to confirm by letter.
            $user->forceFill(['email_verified_at' => now()])->save();

            // Every card has its private half, empty until somebody fills it in —
            // the same as for a colleague added through the application.
            $user->details()->create();

            $user->assignRole(Access::SOLE_ROLE);

            return $user;
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->components->info("Системный администратор создан: {$user->surname} {$user->name} <{$user->email}>.");
        $this->line('  Войти: '.route('login'));

        if ($this->option('password') !== null) {
            // An option stays behind in the shell history or in the cron line it
            // was run from, which is no place for the key to everything.
            $this->components->warn('Пароль был передан опцией. Смените его после первого входа: Настройки → Пароль.');
        }

        return self::SUCCESS;
    }

    /**
     * The same rules the "new employee" form applies to these fields
     * (StoreEmployeeRequest), and the password rule every password form uses.
     *
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'surname' => ['required', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:100'],
            'patronymic' => ['nullable', 'string', 'max:100'],
            'sex' => ['required', Rule::in(['male', 'female'])],
            'email' => ['required', 'string', 'lowercase', 'email', new LatinEmail, 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', Password::defaults()],
        ];
    }

    /**
     * One answer: from the option when it was given, otherwise asked for. A
     * question is repeated until its answer is acceptable; an option is not
     * somebody to ask again, so a wrong one ends the command.
     *
     * @param  list<mixed>  $rules
     * @param  array<string, string>|null  $choices
     * @return string|false|null false when no acceptable answer could be had
     */
    private function answer(string $field, string $question, array $rules, ?array $choices = null): string|false|null
    {
        $given = $this->option($field);

        while (true) {
            $value = $given ?? match (true) {
                ! $this->input->isInteractive() => null,
                $choices !== null => $this->choice($question, $choices),
                default => $this->ask($question),
            };

            $value = $value === null ? null : trim((string) $value);
            $value = $value === '' ? null : $value;

            // An address is one address however it was typed, and the application
            // keeps them in lower case.
            if ($field === 'email' && $value !== null) {
                $value = Str::lower($value);
            }

            $error = $this->firstError($field, $value, $rules);

            if ($error === null) {
                return $value;
            }

            $this->components->error($error);

            if ($given !== null || ! $this->input->isInteractive()) {
                return false;
            }
        }
    }

    /**
     * The password: typed blind and typed twice, since nobody sees what they got
     * wrong. Given as an option it is taken as it is.
     *
     * @param  list<mixed>  $rules
     */
    private function password(array $rules): string|false
    {
        $given = $this->option('password');

        while (true) {
            $value = $given ?? ($this->input->isInteractive() ? $this->secret('Пароль (ввод не отображается)') : null);

            $error = $this->firstError('password', $value, $rules);

            if ($error === null && $given === null && $value !== $this->secret('Пароль ещё раз')) {
                $error = 'Пароли не совпали.';
            }

            if ($error === null) {
                return (string) $value;
            }

            $this->components->error($error);

            if ($given !== null || ! $this->input->isInteractive()) {
                return false;
            }
        }
    }

    /**
     * @param  list<mixed>  $rules
     */
    private function firstError(string $field, ?string $value, array $rules): ?string
    {
        $validator = Validator::make([$field => $value], [$field => $rules], attributes: [
            'surname' => 'фамилия',
            'name' => 'имя',
            'patronymic' => 'отчество',
            'sex' => 'пол',
            'email' => 'e-mail',
            'password' => 'пароль',
        ]);

        return $validator->errors()->first($field) ?: null;
    }
}
