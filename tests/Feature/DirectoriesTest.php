<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Position;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DirectoriesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('sysadmin');
    }

    public function test_only_admins_can_open_or_change_directories()
    {
        $this->get('/directories/roles')->assertRedirect('/login');

        $employee = $this->colleague();
        $this->actingAs($employee);

        $this->get('/directories/roles')->assertForbidden();
        $this->get('/directories/departments')->assertForbidden();
        // The job titles are a section of their own now, read and kept by the
        // very same two rights — which a colleague holds neither of.
        $this->get('/positions')->assertForbidden();
        $this->post('/positions', ['name' => 'Хакер'])->assertForbidden();
        $this->assertSame(0, Position::count());

        // The rights travel with every page, keyed the way they are named — asked
        // here of the one page everybody can open.
        $this->get('/profile')->assertInertia(fn (Assert $page) => $page->where('auth.can', fn ($can) => $can['directories.edit.positions'] === false));
        $this->actingAs($this->admin)->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('auth.can', fn ($can) => $can['directories.edit.positions'] === true));
    }

    public function test_admin_sees_each_directory_with_employee_counts()
    {
        $position = Position::create(['name' => 'Переводчик']);
        User::factory(2)->create()->each(fn (User $u) => $u->positions()->attach($position));
        $this->actingAs($this->admin);

        $this->get('/directories')->assertRedirect('/directories/roles');
        $this->get('/directories/roles')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('directories/roles')
            ->has('items', 25)
            // The one position the system relies on; the rest, «Администратор»
            // included, are ordinary entries of the list.
            ->where('items', fn ($items) => collect($items)->where('protected', true)->pluck('name')->all() === ['sysadmin'])
        );
        // The job titles are not a tab of the section any more; their own page
        // counts their people, and PositionPagesTest reads it.
        $this->get('/positions')->assertInertia(fn (Assert $page) => $page
            ->component('positions/index')
            ->where('positions.0.name', 'Переводчик')
            ->where('positions.0.employees_count', 2)
        );
        $this->get('/directories/departments')->assertInertia(fn (Assert $page) => $page->component('directories/departments'));
    }

    public function test_roles_get_a_generated_key_that_survives_renaming()
    {
        $this->actingAs($this->admin);

        $this->post('/directories/roles', ['title' => 'Главный бухгалтер'])->assertSessionHasNoErrors();
        $role = Role::firstWhere('title', 'Главный бухгалтер');
        $this->assertSame('glavnyy-buhgalter', $role->name);

        $this->put("/directories/roles/{$role->id}", ['title' => 'Главный бухгалтер компании'])->assertSessionHasNoErrors();
        $this->assertSame('glavnyy-buhgalter', $role->fresh()->name);
        $this->assertSame('Главный бухгалтер компании', $role->fresh()->title);

        $this->post('/directories/roles', ['title' => 'Главный бухгалтер компании'])->assertSessionHasErrors('title');
    }

    public function test_deleting_a_role_removes_it_from_employees_but_admin_is_protected()
    {
        $this->actingAs($this->admin);
        $intern = Role::findByName('intern');
        $employee = $this->colleague();
        $employee->assignRole($intern);

        $this->delete("/directories/roles/{$intern->id}")->assertSessionHasNoErrors();
        $this->assertNull(Role::find($intern->id));
        $this->assertCount(0, $employee->fresh()->roles);

        $this->delete('/directories/roles/'.Role::findByName('sysadmin')->id)->assertForbidden();
        $this->assertTrue($this->admin->fresh()->hasRole('sysadmin'));
    }

    // Adding a job title, renaming it, deleting it and writing down what it
    // answers for are read in PositionPagesTest: the list has a section of its
    // own now, and only the two rights behind it are still of this section.

    public function test_departments_can_be_nested_but_not_in_a_cycle()
    {
        $this->actingAs($this->admin);

        $this->post('/directories/departments', ['name' => 'Департамент', 'parent_id' => null])->assertSessionHasNoErrors();
        $parent = Department::firstWhere('name', 'Департамент');
        $this->post('/directories/departments', ['name' => 'Отдел', 'parent_id' => $parent->id])->assertSessionHasNoErrors();
        $child = Department::firstWhere('name', 'Отдел');
        $this->assertSame($parent->id, $child->parent_id);

        $this->put("/directories/departments/{$parent->id}", ['name' => 'Департамент', 'parent_id' => $child->id])->assertSessionHasErrors('parent_id');
        $this->put("/directories/departments/{$parent->id}", ['name' => 'Департамент', 'parent_id' => $parent->id])->assertSessionHasErrors('parent_id');
        $this->assertNull($parent->fresh()->parent_id);

        $this->put("/directories/departments/{$child->id}", ['name' => 'Отдел продаж', 'parent_id' => null])->assertSessionHasNoErrors();
        $this->assertNull($child->fresh()->parent_id);
        $this->assertSame('Отдел продаж', $child->fresh()->name);
    }

    public function test_department_heads_are_chosen_from_working_staff_and_join_the_department()
    {
        $this->actingAs($this->admin);
        $department = Department::create(['name' => 'Отдел Дизайна']);
        $first = User::factory()->create(['surname' => 'Азимова', 'name' => 'Нигина']);
        $second = User::factory()->create(['surname' => 'Бобоев', 'name' => 'Фаррух']);
        $fired = User::factory()->create(['status' => 'fired']);

        $this->put("/directories/departments/{$department->id}", ['name' => 'Отдел Дизайна', 'parent_id' => null, 'head_ids' => [$fired->id]])
            ->assertSessionHasErrors('head_ids.0');

        $this->put("/directories/departments/{$department->id}", ['name' => 'Отдел Дизайна', 'parent_id' => null, 'head_ids' => [$second->id, $first->id]])
            ->assertSessionHasNoErrors();
        $this->assertEqualsCanonicalizing([$first->id, $second->id], $department->heads()->pluck('users.id')->all());

        $this->get('/directories/departments')->assertInertia(fn (Assert $page) => $page
            ->where('items.0.heads', [['id' => $first->id, 'name' => 'Азимова Нигина'], ['id' => $second->id, 'name' => 'Бобоев Фаррух']])
            ->where('employees', fn ($people) => collect($people)->doesntContain('id', $fired->id))
        );

        $this->get('/employees')->assertInertia(fn (Assert $page) => $page
            ->where('employees.data', fn ($rows) => collect($rows)->firstWhere('id', $first->id)['departments'][0]['is_head'] === true)
        );

        // A former head stays in the department as an ordinary member.
        $this->put("/directories/departments/{$department->id}", ['name' => 'Отдел Дизайна', 'parent_id' => null, 'head_ids' => [$first->id]])
            ->assertSessionHasNoErrors();
        $this->assertSame([$first->id], $department->heads()->pluck('users.id')->all());
        $this->assertTrue($department->users()->whereKey($second->id)->exists());

        $this->put("/directories/departments/{$department->id}", ['name' => 'Отдел Дизайна', 'parent_id' => null, 'head_ids' => []])
            ->assertSessionHasNoErrors();
        $this->assertFalse($department->heads()->exists());
        $this->assertSame(2, $department->users()->count());
    }

    public function test_counts_show_working_staff_and_include_sub_departments()
    {
        $this->actingAs($this->admin);
        $parent = Department::create(['name' => 'А Департамент']);
        $child = Department::create(['name' => 'Б Отдел', 'parent_id' => $parent->id]);
        $position = Position::create(['name' => 'Юрист']);

        [$inBoth, $inChild, $inParent, $head] = User::factory(4)->create();
        $fired = User::factory()->create(['status' => 'fired']);
        $parent->users()->attach([$inBoth->id, $inParent->id, $fired->id]);
        $parent->users()->attach($head, ['is_head' => true]);
        $child->users()->attach([$inBoth->id, $inChild->id]);
        collect([$inBoth, $fired])->each(fn (User $u) => $u->positions()->attach($position));

        $this->get('/directories/departments')->assertInertia(fn (Assert $page) => $page
            // Working people only, heads included; someone in both units is counted once.
            ->where('items.0.name', 'А Департамент')
            ->where('items.0.users_count', 3)
            ->where('items.0.total_count', 4)
            ->where('items.1.users_count', 2)
            ->where('items.1.total_count', 2)
        );

        $this->get('/positions')->assertInertia(fn (Assert $page) => $page->where('positions.0.employees_count', 1));
    }

    public function test_department_members_are_edited_from_the_directory()
    {
        $this->actingAs($this->admin);
        $department = Department::create(['name' => 'Отдел']);
        [$head, $stays, $leaves, $joins] = User::factory(4)->create();
        $fired = User::factory()->create(['status' => 'fired']);
        $department->users()->attach([$head->id => ['is_head' => true], $stays->id => ['is_head' => false], $leaves->id => ['is_head' => false], $fired->id => ['is_head' => false]]);

        $this->get('/directories/departments')->assertInertia(fn (Assert $page) => $page
            ->where('items.0.member_ids', fn ($ids) => collect($ids)->sort()->values()->all() === [$stays->id, $leaves->id])
        );

        $this->put("/directories/departments/{$department->id}", ['name' => 'Отдел', 'parent_id' => null, 'member_ids' => [$fired->id]])
            ->assertSessionHasErrors('member_ids.0');

        $this->put("/directories/departments/{$department->id}", ['name' => 'Отдел', 'parent_id' => null, 'member_ids' => [$stays->id, $joins->id]])
            ->assertSessionHasNoErrors();

        // Heads stay when only members are sent; people who left stay on record.
        $this->assertEqualsCanonicalizing([$head->id, $stays->id, $joins->id, $fired->id], $department->users()->pluck('users.id')->all());
        $this->assertSame([$head->id], $department->heads()->pluck('users.id')->all());
    }

    public function test_heads_and_members_are_saved_together()
    {
        $this->actingAs($this->admin);
        $department = Department::create(['name' => 'Отдел']);
        [$former, $promoted, $member] = User::factory(3)->create();
        $department->users()->attach([$former->id => ['is_head' => true], $promoted->id => ['is_head' => false]]);

        // The form moves a former head to the members and a new head out of them.
        $this->put("/directories/departments/{$department->id}", [
            'name' => 'Отдел',
            'parent_id' => null,
            'head_ids' => [$promoted->id],
            'member_ids' => [$former->id, $member->id],
        ])->assertSessionHasNoErrors();

        $this->assertSame([$promoted->id], $department->heads()->pluck('users.id')->all());
        $this->assertEqualsCanonicalizing([$former->id, $promoted->id, $member->id], $department->users()->pluck('users.id')->all());

        // Left out of both lists: leaves the department.
        $this->put("/directories/departments/{$department->id}", [
            'name' => 'Отдел',
            'parent_id' => null,
            'head_ids' => [$promoted->id],
            'member_ids' => [],
        ])->assertSessionHasNoErrors();

        $this->assertSame([$promoted->id], $department->users()->pluck('users.id')->all());
    }

    public function test_a_new_department_can_start_with_members()
    {
        $this->actingAs($this->admin);
        [$head, $member] = User::factory(2)->create();

        $this->post('/directories/departments', ['name' => 'Новый', 'parent_id' => null, 'head_ids' => [$head->id], 'member_ids' => [$member->id]])
            ->assertSessionHasNoErrors();

        $department = Department::firstWhere('name', 'Новый');
        $this->assertSame([$head->id], $department->heads()->pluck('users.id')->all());
        $this->assertEqualsCanonicalizing([$head->id, $member->id], $department->users()->pluck('users.id')->all());
    }

    public function test_renaming_a_department_without_head_ids_keeps_its_heads()
    {
        $this->actingAs($this->admin);
        $head = User::factory()->create();
        $department = Department::create(['name' => 'Отдел']);
        $department->users()->attach($head, ['is_head' => true]);

        $this->put("/directories/departments/{$department->id}", ['name' => 'Новый отдел', 'parent_id' => null])
            ->assertSessionHasNoErrors();

        $this->assertSame([$head->id], $department->heads()->pluck('users.id')->all());
    }

    public function test_deleting_a_head_removes_them_from_the_heads()
    {
        $head = User::factory()->create();
        $department = Department::create(['name' => 'Отдел']);
        $department->users()->attach($head, ['is_head' => true]);

        $head->delete();

        $this->assertFalse($department->heads()->exists());
    }

    public function test_deleting_a_department_moves_its_sub_departments_up()
    {
        $this->actingAs($this->admin);
        $root = Department::create(['name' => 'Корень']);
        $middle = Department::create(['name' => 'Середина', 'parent_id' => $root->id]);
        $leaf = Department::create(['name' => 'Лист', 'parent_id' => $middle->id]);
        $employee = $this->colleague();
        $employee->departments()->attach($middle);

        $this->delete("/directories/departments/{$middle->id}")->assertSessionHasNoErrors();

        $this->assertNull(Department::find($middle->id));
        $this->assertSame($root->id, $leaf->fresh()->parent_id);
        $this->assertCount(0, $employee->fresh()->departments);
    }
}
