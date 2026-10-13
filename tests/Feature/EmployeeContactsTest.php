<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserDetail;
use Database\Seeders\PositionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The "Контакты" card of the profile is edited from its own dialog.
 */
class EmployeeContactsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RoleSeeder::class, PositionSeeder::class]);
    }

    private function payload(array $overrides = []): array
    {
        return [
            'email' => 'n.azimova@evolet.tj',
            'phone' => '+992901234567',
            'sos_phone' => '+992935554433',
            'sos_contact' => 'Сестра — Мехринисо',
            ...$overrides,
        ];
    }

    public function test_a_phone_is_saved_by_somebody_who_cannot_see_the_email()
    {
        // The form sends no address it does not show; the save must not fail on it.
        $employee = User::factory()->has(UserDetail::factory(), 'details')->create();
        $clerk = User::factory()->create()->givePermissionTo([
            'employees.view', 'employees.field.phone', 'employees.edit.phone',
        ]);

        $this->actingAs($clerk)
            ->put("/employees/{$employee->id}/contacts", ['phone' => '900000000'])
            ->assertSessionHasNoErrors();

        $this->assertSame('+992900000000', $employee->details->fresh()->phone);
    }

    public function test_an_admin_edits_the_contacts()
    {
        $admin = User::factory()->create()->assignRole('sysadmin');
        $employee = User::factory()->has(UserDetail::factory(), 'details')->create();

        $this->actingAs($admin)
            ->put("/employees/{$employee->id}/contacts", $this->payload())
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $employee->refresh();
        // The email is the sign-in address, so it lives on the user itself.
        $this->assertSame('n.azimova@evolet.tj', $employee->email);
        $this->assertSame('+992901234567', $employee->details->phone);
        $this->assertSame('+992935554433', $employee->details->sos_phone);
        $this->assertSame('Сестра — Мехринисо', $employee->details->sos_contact);
    }

    public function test_the_email_is_lowercased_and_must_be_free()
    {
        $admin = User::factory()->create()->assignRole('sysadmin');
        $employee = User::factory()->has(UserDetail::factory(), 'details')->create();
        $colleague = User::factory()->create(['email' => 'taken@evolet.tj']);

        $this->actingAs($admin)
            ->put("/employees/{$employee->id}/contacts", $this->payload(['email' => '  N.Azimova@Evolet.TJ  ']))
            ->assertSessionHasNoErrors();
        $this->assertSame('n.azimova@evolet.tj', $employee->refresh()->email);

        // Someone else's address is a clash...
        $this->actingAs($admin)
            ->put("/employees/{$employee->id}/contacts", $this->payload(['email' => $colleague->email]))
            ->assertSessionHasErrors('email');

        // ...but keeping their own is not.
        $this->actingAs($admin)
            ->put("/employees/{$employee->id}/contacts", $this->payload(['email' => 'n.azimova@evolet.tj']))
            ->assertSessionHasNoErrors();
    }

    #[DataProvider('addressesFromAnotherAlphabet')]
    public function test_an_address_with_a_letter_from_another_alphabet_is_refused(string $typed)
    {
        $admin = User::factory()->create()->assignRole('sysadmin');
        $employee = User::factory()->has(UserDetail::factory(), 'details')->create(['email' => 'old@evolet.tj']);

        $this->actingAs($admin)
            ->put("/employees/{$employee->id}/contacts", $this->payload(['email' => $typed]))
            ->assertSessionHasErrors('email');

        // The card is where a mistyped address gets corrected, so it must not
        // be where another one is let in: the old address still stands.
        $this->assertSame('old@evolet.tj', $employee->refresh()->email);

        // The address that really is Latin reads the same and goes through.
        $this->actingAs($admin)
            ->put("/employees/{$employee->id}/contacts", $this->payload(['email' => 'n.azimova@evolet.tj']))
            ->assertSessionHasNoErrors();

        $this->assertSame('n.azimova@evolet.tj', $employee->refresh()->email);
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
            'a stray letter in the local part' => ['n.аzimova@evolet.tj'],
            'a stray letter in the domain' => ['n.azimova@evolеt.tj'],
            'the whole address' => ['нилуфар@почта.рф'],
        ];
    }

    #[DataProvider('phonesAsTyped')]
    public function test_a_phone_is_stored_in_e164_however_it_was_typed(string $typed)
    {
        $admin = User::factory()->create()->assignRole('sysadmin');
        $employee = User::factory()->has(UserDetail::factory(), 'details')->create();

        $this->actingAs($admin)
            ->put("/employees/{$employee->id}/contacts", $this->payload(['phone' => $typed]))
            ->assertSessionHasNoErrors();

        $this->assertSame('+992901234567', $employee->refresh()->details->phone);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function phonesAsTyped(): array
    {
        return [
            'local, spaced' => ['90 123 45 67'],
            'local, dashed' => ['90-123-45-67'],
            'full, spaced' => ['+992 90 123 45 67'],
            'full, plain' => ['992901234567'],
        ];
    }

    public function test_every_field_but_the_email_may_stay_empty()
    {
        $admin = User::factory()->create()->assignRole('sysadmin');
        $employee = User::factory()->has(UserDetail::factory(), 'details')->create();

        $this->actingAs($admin)
            ->put("/employees/{$employee->id}/contacts", $this->payload(['phone' => '', 'sos_phone' => '', 'sos_contact' => '']))
            ->assertSessionHasNoErrors();

        $this->assertNull($employee->refresh()->details->phone);
        $this->assertNull($employee->details->sos_contact);

        // Without an address the employee could not sign in.
        $this->actingAs($admin)
            ->put("/employees/{$employee->id}/contacts", $this->payload(['email' => '']))
            ->assertSessionHasErrors('email');
    }

    public function test_a_phone_that_is_too_short_is_rejected()
    {
        $admin = User::factory()->create()->assignRole('sysadmin');
        $employee = User::factory()->has(UserDetail::factory(), 'details')->create();

        $this->actingAs($admin)
            ->put("/employees/{$employee->id}/contacts", $this->payload([
                'phone' => '123',
                'sos_phone' => 'не телефон',
            ]))
            ->assertSessionHasErrors(['phone', 'sos_phone']);
    }

    public function test_an_employee_cannot_edit_anyones_contacts()
    {
        $employee = User::factory()->has(UserDetail::factory(), 'details')->create();

        // Not even their own: the card is managed by HR.
        $this->actingAs($employee)
            ->put("/employees/{$employee->id}/contacts", $this->payload())
            ->assertForbidden();

        $this->actingAs($this->colleague())
            ->put("/employees/{$employee->id}/contacts", $this->payload())
            ->assertForbidden();
    }
}
