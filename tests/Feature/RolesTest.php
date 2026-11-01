<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Position;
use App\Models\User;
use App\Support\Access;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The four roles there are, and who may put which of them on a card.
 *
 * Roles stopped being a directory the company keeps with a table of ticks
 * behind it. They are «Системный администратор», «HR-специалист»,
 * «Руководитель» and «Специалист», they are named in App\Support\Access and
 * nowhere else, and what each may do is decided there rather than handed out on
 * a page: the first two pass every check through Gate::before while holding not
 * one right of their own, and the site is not open to the other two yet.
 *
 * A person holds exactly one of them, and the rest of the contract is about who
 * may hand which out. The system administrator is handed to nobody — there is
 * one and it is taken — personnel are appointed by the system administrator
 * alone, since personnel who could appoint personnel would be the whole access
 * system in one person's gift, and the two ordinary roles are not usually asked
 * for at all: a card that names no role takes the one its positions imply.
 */
class RolesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    /** The one account that passes every check without holding a single right. */
    private function sysadmin(): User
    {
        return User::factory()->create()->assignRole(Access::SOLE_ROLE);
    }

    /** Personnel, who pass every check in the same way. */
    private function personnel(): User
    {
        return User::factory()->create()->assignRole(Access::HR);
    }

    /**
     * A department with a leading job and an ordinary one in it, which is the
     * whole of what decides the role a card is given when it names none.
     *
     * @return array{Position, Position}
     */
    private function jobs(): array
    {
        $department = Department::create(['name' => 'Отдел Дизайна']);

        return [
            Position::create(['department_id' => $department->id, 'name' => 'Руководитель отдела дизайна', 'is_head' => true]),
            Position::create(['department_id' => $department->id, 'name' => 'Дизайнер']),
        ];
    }

    /**
     * «Основное» of a card as its own window sends it: the role, where the
     * person stands, and the three lines the form always carries. Every other
     * line of that form is «sometimes», so a window that shows none of them
     * sends none.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function card(User $employee, array $overrides = []): array
    {
        return [
            'surname' => $employee->surname,
            'name' => $employee->name,
            'sex' => $employee->sex,
            'roles' => $employee->roles->pluck('name')->all(),
            'placements' => [],
            ...$overrides,
        ];
    }

    /**
     * A whole new colleague, for the role they are given on the way in.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function newCard(array $overrides = []): array
    {
        return [
            'surname' => 'Азимов',
            'name' => 'Далер',
            'sex' => 'male',
            'email' => 'daler@evolet.tj',
            'roles' => [],
            'placements' => [],
            ...$overrides,
        ];
    }

    public function test_the_catalogue_is_the_four_roles_and_nothing_else()
    {
        // Written out rather than read back out of Access::ROLES, which would
        // only ask the code whether it agrees with itself. These are the names
        // the company reads on a card, and a seeder that quietly stopped making
        // one of them would leave somebody holding no role at all.
        $this->assertSame([
            'sysadmin' => 'Системный администратор',
            'hr' => 'HR-специалист',
            'head' => 'Руководитель',
            'specialist' => 'Специалист',
        ], Role::query()->pluck('title', 'name')->all());
    }

    public function test_personnel_pass_every_check_exactly_as_the_system_administrator_does()
    {
        // Two roles and one answer: every right in the catalogue — reading any
        // line of any card, moving any unit, keeping any list — passes for both
        // of them, and neither carries a single permission row to pass it with.
        foreach ([$this->sysadmin(), $this->personnel()] as $user) {
            $role = $user->roles->first()->name;

            $this->assertEmpty($user->getAllPermissions());

            foreach (Access::keys() as $key) {
                $this->assertTrue($user->can($key), "роль {$role} должна проходить {$key}");
            }
        }
    }

    public function test_personnel_are_offered_by_the_system_administrator_alone()
    {
        $sysadmin = $this->sysadmin();
        $personnel = $this->personnel();

        // The system administrator itself is in neither list: there is one of it
        // and no picker offers it.
        $this->assertSame([Access::HR, Access::HEAD, Access::SPECIALIST], Access::offeredTo($sysadmin));
        $this->assertSame([Access::HEAD, Access::SPECIALIST], Access::offeredTo($personnel));

        // The same answer as rows to show, in the order the catalogue names them
        // — widest first, which is not the order their titles sort in.
        $this->assertSame(['hr', 'head', 'specialist'], Access::offeredRoles($sysadmin)->pluck('name')->all());
        $this->assertSame(['head', 'specialist'], Access::offeredRoles($personnel)->pluck('name')->all());
    }

    public function test_the_system_administrator_is_handed_to_nobody()
    {
        $sysadmin = $this->sysadmin();
        $colleague = User::factory()->create();

        // Not even by the one who holds it, and on both of the doors a role
        // comes through: a card already on file, and a card being opened.
        $refusal = 'Роль системного администратора не выдаётся: она одна и уже занята.';

        $this->actingAs($sysadmin)
            ->put("/employees/{$colleague->id}/personal", $this->card($colleague, ['roles' => [Access::SOLE_ROLE]]))
            ->assertSessionHasErrors(['roles.0' => $refusal]);
        $this->assertFalse($colleague->refresh()->hasRole(Access::SOLE_ROLE));

        $this->actingAs($sysadmin)
            ->post('/employees', $this->newCard(['roles' => [Access::SOLE_ROLE]]))
            ->assertSessionHasErrors(['roles.0' => $refusal]);
        $this->assertNull(User::query()->where('email', 'daler@evolet.tj')->first());
    }

    public function test_only_the_system_administrator_appoints_personnel()
    {
        $colleague = User::factory()->create();
        $refusal = 'Роль HR-специалиста выдаёт только системный администратор.';

        // Personnel pass every check, so one of them appointing another would
        // put the whole of the access system in their gift.
        $this->actingAs($this->personnel())
            ->put("/employees/{$colleague->id}/personal", $this->card($colleague, ['roles' => [Access::HR]]))
            ->assertSessionHasErrors(['roles.0' => $refusal]);
        $this->assertFalse($colleague->refresh()->hasRole(Access::HR));

        $this->actingAs($this->personnel())
            ->post('/employees', $this->newCard(['roles' => [Access::HR]]))
            ->assertSessionHasErrors(['roles.0' => $refusal]);

        // The one account it is in the gift of does it without ceremony.
        $this->actingAs($this->sysadmin())
            ->put("/employees/{$colleague->id}/personal", $this->card($colleague, ['roles' => [Access::HR]]))
            ->assertSessionHasNoErrors();
        $this->assertTrue($colleague->refresh()->hasRole(Access::HR));
    }

    public function test_a_card_that_names_no_role_takes_the_one_its_positions_imply()
    {
        [$leading, $plain] = $this->jobs();
        $sysadmin = $this->sysadmin();

        // The role is not a question the page asks twice: the job answers it.
        // Leading a department makes a head, and every other job a specialist.
        $this->actingAs($sysadmin)
            ->post('/employees', $this->newCard([
                'placements' => [['position_id' => $leading->id, 'duty_ids' => []]],
            ]))
            ->assertSessionHasNoErrors();

        $head = User::query()->where('email', 'daler@evolet.tj')->sole();
        $this->assertSame([Access::HEAD], $head->roles->pluck('name')->all());

        $this->actingAs($sysadmin)
            ->post('/employees', $this->newCard([
                'email' => 'nilufar@evolet.tj',
                'placements' => [['position_id' => $plain->id, 'duty_ids' => []]],
            ]))
            ->assertSessionHasNoErrors();

        $specialist = User::query()->where('email', 'nilufar@evolet.tj')->sole();
        $this->assertSame([Access::SPECIALIST], $specialist->roles->pluck('name')->all());
    }

    public function test_moving_a_card_into_a_leading_position_makes_its_holder_a_head()
    {
        [$leading, $plain] = $this->jobs();
        $sysadmin = $this->sysadmin();
        $colleague = User::factory()->create();

        // The flag is on the job and not on the person, so the role follows the
        // positions a card is saved with — and the role is saved after them, or
        // it would be worked out from the jobs the person is about to leave.
        $this->actingAs($sysadmin)
            ->put("/employees/{$colleague->id}/personal", $this->card($colleague, [
                'roles' => [],
                'placements' => [['position_id' => $plain->id, 'duty_ids' => []]],
            ]))
            ->assertSessionHasNoErrors();
        $this->assertSame([Access::SPECIALIST], $colleague->refresh()->roles->pluck('name')->all());

        $this->actingAs($sysadmin)
            ->put("/employees/{$colleague->id}/personal", $this->card($colleague, [
                'roles' => [],
                'placements' => [['position_id' => $leading->id, 'duty_ids' => []]],
            ]))
            ->assertSessionHasNoErrors();
        $this->assertSame([Access::HEAD], $colleague->refresh()->roles->pluck('name')->all());
    }

    public function test_a_person_holds_one_role_and_not_two()
    {
        $colleague = User::factory()->create();

        // The four are an order rather than a set: somebody is personnel, or a
        // head, or neither, and two of them at once is not an answer.
        $this->actingAs($this->sysadmin())
            ->put("/employees/{$colleague->id}/personal", $this->card($colleague, [
                'roles' => [Access::HEAD, Access::SPECIALIST],
            ]))
            ->assertSessionHasErrors('roles');
        $this->assertEmpty($colleague->refresh()->roles);

        $this->actingAs($this->sysadmin())
            ->post('/employees', $this->newCard(['roles' => [Access::HEAD, Access::SPECIALIST]]))
            ->assertSessionHasErrors('roles');
    }

    public function test_the_system_administrators_own_role_is_nobodys_to_change()
    {
        $sysadmin = $this->sysadmin();
        $locked = 'Роль системного администратора не меняется.';

        // Including by the account that holds it: there would be nobody left who
        // can reach everything.
        $this->actingAs($sysadmin)
            ->put("/employees/{$sysadmin->id}/personal", $this->card($sysadmin, ['roles' => [Access::HR]]))
            ->assertSessionHasErrors(['roles' => $locked]);
        $this->assertSame([Access::SOLE_ROLE], $sysadmin->refresh()->roles->pluck('name')->all());

        // The card says so beside the field, rather than letting somebody find
        // out by saving.
        $this->assertSame(
            $locked,
            $this->actingAs($sysadmin)->get('/profile')->viewData('page')['props']['rolesLocked'],
        );

        // Nothing else on that card is locked, and no other card is.
        $this->actingAs($sysadmin)
            ->put("/employees/{$sysadmin->id}/personal", $this->card($sysadmin, ['surname' => 'Азимов']))
            ->assertSessionHasNoErrors();
        $this->assertSame('Азимов', $sysadmin->refresh()->surname);

        $colleague = User::factory()->create();
        $this->assertNull(
            $this->actingAs($sysadmin)->get("/employees/{$colleague->id}")->viewData('page')['props']['rolesLocked'],
        );
    }

    public function test_a_system_administrator_cannot_strike_out_their_own_card()
    {
        // The one thing the role does not open. Everything else on the card of
        // somebody else is theirs, and their own card is theirs to edit — but
        // there would be nobody left to hand access out.
        $sysadmin = $this->sysadmin();

        $this->actingAs($sysadmin)->delete("/employees/{$sysadmin->id}")->assertForbidden();
        $this->assertNotNull(User::find($sysadmin->id));

        // Leaving by the other doors is refused just the same.
        $this->actingAs($sysadmin)
            ->post("/employees/{$sysadmin->id}/fire", ['date' => '2026-09-01'])
            ->assertForbidden();
        $this->actingAs($sysadmin)
            ->post("/employees/{$sysadmin->id}/transfer", ['date' => '2026-09-01', 'note' => 'В другую компанию'])
            ->assertForbidden();
        $this->assertTrue($sysadmin->refresh()->isActive());

        // Somebody else's card is another matter.
        $colleague = User::factory()->create();
        $this->actingAs($sysadmin)->delete("/employees/{$colleague->id}")->assertRedirect();
        $this->assertNull(User::find($colleague->id));
    }

    public function test_the_system_administrator_is_offered_in_no_picker()
    {
        $sysadmin = $this->sysadmin();
        $colleague = User::factory()->create();

        $names = fn (string $url) => collect(
            $this->actingAs($sysadmin)->get($url)->viewData('page')['props']['options']['roles'],
        )->pluck('name')->all();

        $this->assertSame(['hr', 'head', 'specialist'], $names('/employees/create'));
        $this->assertSame(['hr', 'head', 'specialist'], $names("/employees/{$colleague->id}"));
        // The filter on the list is reading rather than handing out, so it
        // offers the three a person here can be found by — and not the fourth.
        $this->assertSame(['hr', 'head', 'specialist'], $names('/employees'));

        // Their own card is the exception: it must show the role it carries, or
        // the next save would quietly drop it.
        $this->assertContains(Access::SOLE_ROLE, $names('/profile'));

        // And search does not hand out a way in either.
        $this->assertSame([], $this->actingAs($sysadmin)->getJson('/search?q='.urlencode('Системный'))->json('roles'));
    }
}
