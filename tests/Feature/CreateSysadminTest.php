<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Access;
use Database\Seeders\ProductionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * `php artisan hrm:sysadmin` — the first account of a new installation.
 *
 * Nobody can be added through the application until somebody may add people, so
 * the one account that may do everything is made from the server.
 */
class CreateSysadminTest extends TestCase
{
    use RefreshDatabase;

    private const OPTIONS = [
        '--surname' => 'Абдуллоев',
        '--name' => 'Некруз',
        '--sex' => 'male',
        '--email' => 'admin@evolet.tj',
        '--password' => 'correct-horse',
        // The way cron runs it: whatever is not given is not asked for.
        '--no-interaction' => true,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ProductionSeeder::class);
    }

    public function test_it_asks_for_everything_and_creates_the_system_administrator()
    {
        $this->artisan('hrm:sysadmin')
            ->expectsQuestion('Фамилия', 'Абдуллоев')
            ->expectsQuestion('Имя', 'Некруз')
            ->expectsQuestion('Отчество (можно оставить пустым)', null)
            ->expectsQuestion('Пол', 'male')
            ->expectsQuestion('E-mail', 'Admin@Evolet.tj')
            ->expectsQuestion('Пароль (ввод не отображается)', 'correct-horse')
            ->expectsQuestion('Пароль ещё раз', 'correct-horse')
            ->assertSuccessful();

        $admin = User::sole();

        $this->assertSame(['Абдуллоев', 'Некруз', null, 'male'], [$admin->surname, $admin->name, $admin->patronymic, $admin->sex]);
        // The address is kept the way the application keeps every address.
        $this->assertSame('admin@evolet.tj', $admin->email);
        $this->assertSame([Access::SOLE_ROLE], $admin->getRoleNames()->all());
        $this->assertTrue($admin->isActive());
        // Typed in by whoever installed the system, so there is nothing to confirm.
        $this->assertNotNull($admin->email_verified_at);
        // The private half of the card exists, as it does for anybody else.
        $this->assertNotNull($admin->details);

        $this->assertTrue(Auth::validate(['email' => 'admin@evolet.tj', 'password' => 'correct-horse']));
        // Any right at all, including ones nobody has thought of yet.
        $this->assertTrue(Gate::forUser($admin)->allows('employees.delete'));
        $this->assertTrue(Gate::forUser($admin)->allows('a-right-added-later'));
    }

    public function test_it_takes_its_answers_as_options_when_there_is_nobody_to_ask()
    {
        Notification::fake();

        $this->artisan('hrm:sysadmin', [...self::OPTIONS, '--patronymic' => 'Саидович'])
            ->assertSuccessful();

        $admin = User::sole();

        $this->assertSame('Саидович', $admin->patronymic);
        $this->assertTrue($admin->hasRole(Access::SOLE_ROLE));
        $this->assertTrue(Auth::validate(['email' => 'admin@evolet.tj', 'password' => 'correct-horse']));
        // Nothing is mailed: the password is known to whoever typed it.
        Notification::assertNothingSent();
    }

    public function test_a_second_system_administrator_is_refused_by_name()
    {
        $this->artisan('hrm:sysadmin', self::OPTIONS)->assertSuccessful();

        $this->artisan('hrm:sysadmin', [...self::OPTIONS, '--email' => 'second@evolet.tj'])
            ->expectsOutputToContain('Абдуллоев Некруз <admin@evolet.tj>')
            ->assertFailed();

        $this->assertSame(1, User::count());
    }

    public function test_a_wrong_answer_is_asked_again()
    {
        User::factory()->create(['email' => 'taken@evolet.tj']);

        $this->artisan('hrm:sysadmin')
            ->expectsQuestion('Фамилия', '')
            ->expectsQuestion('Фамилия', 'Абдуллоев')
            ->expectsQuestion('Имя', 'Некруз')
            ->expectsQuestion('Отчество (можно оставить пустым)', '')
            ->expectsQuestion('Пол', 'male')
            ->expectsQuestion('E-mail', 'not-an-address')
            // One address is one person, here as in the "new employee" form.
            ->expectsQuestion('E-mail', 'taken@evolet.tj')
            ->expectsQuestion('E-mail', 'admin@evolet.tj')
            // Too short, then mistyped the second time.
            ->expectsQuestion('Пароль (ввод не отображается)', 'short')
            ->expectsQuestion('Пароль (ввод не отображается)', 'correct-horse')
            ->expectsQuestion('Пароль ещё раз', 'correct-hose')
            ->expectsQuestion('Пароль (ввод не отображается)', 'correct-horse')
            ->expectsQuestion('Пароль ещё раз', 'correct-horse')
            ->assertSuccessful();

        $this->assertSame('admin@evolet.tj', User::role(Access::SOLE_ROLE)->sole()->email);
    }

    public function test_a_wrong_option_ends_the_command_and_creates_nobody()
    {
        $this->artisan('hrm:sysadmin', [...self::OPTIONS, '--sex' => 'other'])->assertFailed();
        $this->artisan('hrm:sysadmin', [...self::OPTIONS, '--password' => 'short'])->assertFailed();
        $this->artisan('hrm:sysadmin', [...self::OPTIONS, '--email' => 'not-an-address'])->assertFailed();

        $this->assertSame(0, User::count());
    }

    public function test_without_a_terminal_a_missing_answer_fails_rather_than_waits()
    {
        $options = self::OPTIONS;
        unset($options['--password']);

        $this->artisan('hrm:sysadmin', $options)->assertFailed();

        $this->assertSame(0, User::count());
    }

    #[DataProvider('addressesFromAnotherAlphabet')]
    public function test_an_address_with_a_letter_from_another_alphabet_is_refused(string $typed)
    {
        // Nobody would ever find the stray letter afterwards: this is the one
        // account the password page cannot help, since the letter it would send
        // goes to the address itself.
        $this->artisan('hrm:sysadmin', [...self::OPTIONS, '--email' => $typed])->assertFailed();

        $this->assertSame(0, User::count());

        // The address that really is Latin reads the same and goes through.
        $this->artisan('hrm:sysadmin', self::OPTIONS)->assertSuccessful();

        $this->assertSame('admin@evolet.tj', User::role(Access::SOLE_ROLE)->sole()->email);
    }

    /**
     * Addresses nobody can tell from a Latin one by looking at them: the «а»
     * and the «е» below are Cyrillic.
     *
     * @return array<string, array{string}>
     */
    public static function addressesFromAnotherAlphabet(): array
    {
        return [
            'a stray letter in the local part' => ['аdmin@evolet.tj'],
            'a stray letter in the domain' => ['admin@evolеt.tj'],
            'the whole address' => ['некруз@почта.рф'],
        ];
    }

    public function test_it_needs_the_position_to_exist_and_says_how_to_get_it()
    {
        // A database that was migrated and never seeded.
        Role::query()->delete();

        $this->artisan('hrm:sysadmin', self::OPTIONS)
            ->expectsOutputToContain('ProductionSeeder')
            ->assertFailed();

        $this->assertSame(0, User::count());
    }
}
