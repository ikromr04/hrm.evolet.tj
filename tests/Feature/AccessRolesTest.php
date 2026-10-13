<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Access;
use Database\Seeders\PositionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The one position that is not decided by the access list.
 *
 * Everything anybody may do is a right ticked on the access page, with a single
 * exception: the system administrator passes every check while holding no rights
 * at all, because somebody has to be able to reach the page where rights are
 * handed out. There is exactly one of them — the position is offered nowhere,
 * cannot be given to anybody and cannot be given up.
 *
 * Every other position is ordinary, «Администратор» included. The only rule about
 * positions besides that one protects whoever hands rights out: the positions of
 * somebody who may change the access table are theirs alone and the system
 * administrator's, because taking their positions away takes away what they may do.
 */
class AccessRolesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RoleSeeder::class, PositionSeeder::class]);
    }

    /** The one account that passes every check without holding a single right. */
    private function sysadmin(): User
    {
        return User::factory()->create()->assignRole('sysadmin');
    }

    /**
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

    public function test_only_the_system_administrator_passes_every_check_without_rights()
    {
        $sysadmin = $this->sysadmin();

        // Every right in the catalogue, not a page or two of them: reading any
        // line of any card, moving any unit, keeping any list. «Администратор» is
        // an ordinary position and reaches the same places by holding the rights.
        $this->assertEmpty($sysadmin->getAllPermissions());

        foreach (Access::keys() as $key) {
            $this->assertTrue($sysadmin->can($key), "системный администратор должен проходить {$key}");
        }

        $this->actingAs($sysadmin);
        $this->get('/directories')->assertRedirect('/directories/roles');
        $this->get('/directories/access')->assertOk();
        $this->get('/employees')->assertOk();
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

    public function test_a_system_administrator_settles_anybodys_positions()
    {
        // Nobody is out of reach for the one account, and no position is special
        // to it: «Администратор» is handed out and taken back like any other.
        $colleague = User::factory()->create()->assignRole('analyst');
        $sysadmin = $this->sysadmin();

        $this->actingAs($sysadmin)
            ->put("/employees/{$colleague->id}/personal", $this->card($colleague, ['roles' => ['analyst', 'admin']]))
            ->assertSessionHasNoErrors();

        $this->assertTrue($colleague->refresh()->hasRole('admin'));

        $this->actingAs($sysadmin)
            ->put("/employees/{$colleague->id}/personal", $this->card($colleague, ['roles' => ['analyst']]))
            ->assertSessionHasNoErrors();

        $this->assertFalse($colleague->refresh()->hasRole('admin'));
    }

    public function test_a_system_administrator_keeps_that_role_and_may_drop_the_others()
    {
        $sysadmin = $this->sysadmin();
        $sysadmin->assignRole('admin');

        // The one role there is no second of does not come off its own card.
        $this->actingAs($sysadmin)
            ->put("/employees/{$sysadmin->id}/personal", $this->card($sysadmin, ['roles' => ['admin']]))
            ->assertSessionHasErrors('roles');
        $this->assertTrue($sysadmin->refresh()->hasRole('sysadmin'));

        // Anything else does: a system administrator can appoint an administrator
        // again, so stepping down from that one costs nothing.
        $this->actingAs($sysadmin)
            ->put("/employees/{$sysadmin->id}/personal", $this->card($sysadmin, ['roles' => ['sysadmin']]))
            ->assertSessionHasNoErrors();
        $this->assertFalse($sysadmin->refresh()->hasRole('admin'));
        $this->assertTrue($sysadmin->refresh()->hasRole('sysadmin'));
    }

    public function test_ones_own_positions_are_decided_by_the_rights_one_holds()
    {
        // Nothing beyond the access page: if the line of one's own profile is open
        // to somebody, it is open, and if it is not, the request changes nothing.
        $open = $this->mayLookAround(User::factory()->create())->assignRole('analyst');
        $open->givePermissionTo(['profile.field.roles', 'profile.edit.roles']);

        $this->actingAs($open->fresh())
            ->put("/employees/{$open->id}/personal", $this->card($open, ['roles' => ['analyst', 'translator']]))
            ->assertSessionHasNoErrors();
        $this->assertTrue($open->refresh()->hasRole('translator'));

        $shut = $this->mayLookAround(User::factory()->create())->assignRole('analyst');

        $this->actingAs($shut->fresh())
            ->put("/employees/{$shut->id}/personal", $this->card($shut, ['roles' => ['analyst', 'translator']]));
        $this->assertFalse($shut->refresh()->hasRole('translator'));
    }

    /** Somebody who may change the access table, and so decides what others open. */
    private function keeperOfAccess(): User
    {
        return $this->mayLookAround(User::factory()->create())
            ->givePermissionTo(['directories.view.access', 'directories.edit.access']);
    }

    /** Somebody allowed to change the «Позиция» line of other people's cards. */
    private function editorOfPositions(): User
    {
        return $this->mayLookAround(User::factory()->create())->givePermissionTo('employees.edit.roles');
    }

    public function test_whoever_hands_rights_out_keeps_their_own_positions()
    {
        // Take their positions away and you have taken away what they may do, so
        // that is not something anybody else does with a right.
        $keeper = $this->keeperOfAccess()->assignRole('analyst');
        $editor = $this->editorOfPositions();

        $this->actingAs($editor->fresh())
            ->put("/employees/{$keeper->id}/personal", $this->card($keeper, ['roles' => []]))
            ->assertSessionHasErrors('roles');
        $this->assertTrue($keeper->refresh()->hasRole('analyst'));

        // Anybody else is ordinary work for that right.
        $colleague = User::factory()->create()->assignRole('intern');
        $this->actingAs($editor->fresh())
            ->put("/employees/{$colleague->id}/personal", $this->card($colleague, ['roles' => ['translator']]))
            ->assertSessionHasNoErrors();
        $this->assertTrue($colleague->refresh()->hasRole('translator'));
        $this->assertFalse($colleague->refresh()->hasRole('intern'));
    }

    public function test_the_two_who_may_change_them_are_the_person_themselves_and_the_one_account()
    {
        $keeper = $this->keeperOfAccess()->assignRole('analyst');
        $keeper->givePermissionTo(['profile.field.roles', 'profile.edit.roles']);

        $this->actingAs($keeper->fresh())
            ->put("/employees/{$keeper->id}/personal", $this->card($keeper, ['roles' => ['analyst', 'translator']]))
            ->assertSessionHasNoErrors();
        $this->assertTrue($keeper->refresh()->hasRole('translator'));

        $this->actingAs($this->sysadmin())
            ->put("/employees/{$keeper->id}/personal", $this->card($keeper, ['roles' => ['analyst']]))
            ->assertSessionHasNoErrors();
        $this->assertFalse($keeper->refresh()->hasRole('translator'));
    }

    public function test_two_who_may_change_positions_do_not_change_each_others()
    {
        $first = $this->editorOfPositions()->assignRole('analyst');
        $second = $this->editorOfPositions()->assignRole('translator');

        $this->actingAs($first->fresh())
            ->put("/employees/{$second->id}/personal", $this->card($second, ['roles' => []]))
            ->assertSessionHasErrors('roles');
        $this->assertTrue($second->refresh()->hasRole('translator'));

        $this->actingAs($second->fresh())
            ->put("/employees/{$first->id}/personal", $this->card($first, ['roles' => []]))
            ->assertSessionHasErrors('roles');
        $this->assertTrue($first->refresh()->hasRole('analyst'));

        // The card says so beside the field.
        $this->assertNotNull($this->actingAs($first->fresh())->get("/employees/{$second->id}")->viewData('page')['props']['rolesLocked']);

        // The system administrator stands outside the rule.
        $this->actingAs($this->sysadmin())
            ->put("/employees/{$second->id}/personal", $this->card($second, ['roles' => ['intern']]))
            ->assertSessionHasNoErrors();
        $this->assertTrue($second->refresh()->hasRole('intern'));
        $this->assertFalse($second->refresh()->hasRole('translator'));
    }

    public function test_the_card_says_why_the_positions_are_locked()
    {
        $keeper = $this->keeperOfAccess();
        $editor = $this->editorOfPositions();
        $colleague = User::factory()->create()->assignRole('intern');

        // Their own card answers at its own address; anybody else's by id.
        $locked = fn (User $viewer, User $employee) => $this->actingAs($viewer->fresh())
            ->get($viewer->is($employee) ? '/profile' : "/employees/{$employee->id}")
            ->viewData('page')['props']['rolesLocked'];

        $this->assertNotNull($locked($editor, $keeper));
        // Ordinary cards, and their own, are decided by the rights they hold.
        $this->assertNull($locked($editor, $colleague));
        $this->assertNull($locked($editor, $editor));
        $this->assertNull($locked($keeper, $keeper));
        // And nothing is locked for the one account.
        $this->assertNull($locked($this->sysadmin(), $keeper));
    }

    public function test_there_is_no_second_system_administrator()
    {
        $sysadmin = $this->sysadmin();
        $colleague = User::factory()->create();

        // Not even by the one who holds it: the role is offered nowhere and
        // refused here, so no request can make another.
        $this->actingAs($sysadmin)
            ->put("/employees/{$colleague->id}/personal", $this->card($colleague, ['roles' => ['sysadmin']]))
            ->assertSessionHasErrors('roles.0');
        $this->assertFalse($colleague->refresh()->hasRole('sysadmin'));

        $this->actingAs($sysadmin)
            ->post('/employees', [
                'surname' => 'Азимов',
                'name' => 'Далер',
                'sex' => 'male',
                'roles' => ['sysadmin'],
                'placements' => [],
            ])
            ->assertSessionHasErrors('roles.0');
    }

    public function test_the_role_is_offered_in_no_picker()
    {
        $sysadmin = $this->sysadmin();
        $colleague = User::factory()->create();

        $names = fn (string $url) => collect(
            $this->actingAs($sysadmin)->get($url)->viewData('page')['props']['options']['roles'],
        )->pluck('name')->all();

        $this->assertNotContains('sysadmin', $names('/employees'));
        $this->assertNotContains('sysadmin', $names('/employees/create'));
        $this->assertNotContains('sysadmin', $names("/employees/{$colleague->id}"));
        // Administrators are still offered — there may be any number of those.
        $this->assertContains('admin', $names('/employees/create'));

        // Their own card is the exception: it must show the role it carries, or
        // the next save would quietly drop it.
        $this->assertContains('sysadmin', $names('/profile'));

        // And search does not hand out a way in either.
        $this->assertSame([], $this->actingAs($sysadmin)->getJson('/search?q='.urlencode('Системный'))->json('roles'));
    }

    public function test_only_the_one_position_cannot_be_deleted_from_the_directory()
    {
        $this->actingAs($this->sysadmin());

        // The system relies on this one; nothing relies on the rest.
        $sole = Role::findByName('sysadmin');
        $this->delete("/directories/roles/{$sole->id}")->assertForbidden();
        $this->assertNotNull(Role::find($sole->id));

        $ordinary = Role::findByName('admin');
        $this->delete("/directories/roles/{$ordinary->id}")->assertRedirect();
        $this->assertNull(Role::find($ordinary->id));
    }
}
