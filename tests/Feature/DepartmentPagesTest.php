<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Position;
use App\Models\User;
use App\Models\UserDetail;
use App\Support\Directories;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * "Структура компании": the chart everybody signed in may read, the departments
 * themselves, and — since the list left "Справочники" — the tab that writes that
 * structure down, at /departments/units.
 */
class DepartmentPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_sent_to_login()
    {
        $this->get('/departments')->assertRedirect('/login');
    }

    public function test_every_employee_sees_the_structure_with_heads_and_counts()
    {
        $top = Department::create(['name' => 'Департамент']);
        $unit = Department::create(['name' => 'Отдел', 'parent_id' => $top->id]);
        $head = User::factory()->create(['surname' => 'Азимова', 'name' => 'Нигина']);
        [$a, $b] = User::factory(2)->create();
        $fired = User::factory()->create(['status' => 'fired']);
        $top->users()->attach($head, ['is_head' => true]);
        $unit->users()->attach([$a->id => ['is_head' => false], $b->id => ['is_head' => false], $fired->id => ['is_head' => false]]);

        $this->actingAs($this->mayLookAround($a))->get('/departments')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('departments/index')
            ->where('employees_count', 3)
            ->where('departments.0.name', 'Департамент')
            ->where('departments.0.total_count', 3)
            ->where('departments.0.heads', [['id' => $head->id, 'name' => 'Азимова Нигина', 'avatar' => null]])
            ->where('departments.0.members', [])
            ->where('departments.1.parent_id', $top->id)
            ->where('departments.1.total_count', 2)
            // Working people only.
            ->where('departments.1.members', fn ($members) => collect($members)->pluck('id')->sort()->values()->all() === [$a->id, $b->id])
        );
    }

    public function test_a_department_page_shows_public_data_of_working_people()
    {
        $unit = Department::create(['name' => 'Отдел']);
        $sub = Department::create(['name' => 'Группа', 'parent_id' => $unit->id]);
        $head = User::factory()->create();
        $member = User::factory()->create(['surname' => 'Бобоев', 'name' => 'Фаррух']);
        $member->positions()->attach(Position::create(['name' => 'Дизайнер']));
        UserDetail::factory()->for($member)->create(['phone' => '+992901234567']);
        $fired = User::factory()->create(['status' => 'fired']);
        $unit->users()->attach($head, ['is_head' => true]);
        $unit->users()->attach([$member->id => ['is_head' => false], $fired->id => ['is_head' => false]]);

        $this->actingAs($this->colleague())->get("/departments/{$unit->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('departments/show')
            ->where('department.name', 'Отдел')
            // A department with units under it is on the top level: nothing above it.
            ->where('department.parents', [])
            ->where('department.total_count', 2)
            ->where('department.heads.0.id', $head->id)
            ->where('department.children', [['id' => $sub->id, 'name' => 'Группа', 'full_name' => 'Группа', 'total_count' => 0, 'heads' => []]])
            ->has('department.members', 1)
            ->where('department.members.0', [
                'id' => $member->id,
                'name' => 'Бобоев Фаррух',
                'avatar' => null,
                'email' => $member->email,
                'positions' => ['Дизайнер'],
            ])
        );
    }

    public function test_the_structure_names_a_department_by_its_short_name()
    {
        $top = Department::create(['name' => 'Департамент маркетинга', 'abbreviation' => 'ДМ']);
        $unit = Department::create(['name' => 'Отдел Дизайна', 'abbreviation' => 'ОД', 'parent_id' => $top->id]);
        // Nothing short to call this one by yet, so it reads by its full name.
        $sub = Department::create(['name' => 'Группа вёрстки', 'parent_id' => $top->id]);
        $named = fn ($chart, int $id) => collect($chart)->firstWhere('id', $id)['name'];

        $this->actingAs($this->colleague());

        $this->get('/departments')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('departments/index')
            ->where('departments', fn ($chart) => $named($chart, $top->id) === 'ДМ')
            ->where('departments', fn ($chart) => $named($chart, $unit->id) === 'ОД')
            ->where('departments', fn ($chart) => $named($chart, $sub->id) === 'Группа вёрстки')
        );

        // Its own page and the department above it: every box of the structure
        // is named the one way.
        $this->get("/departments/{$unit->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('departments/show')
            ->where('department.name', 'ОД')
            // Short on screen, spelled out for the hover and the screen reader.
            ->where('department.parents', [['id' => $top->id, 'name' => 'ДМ', 'full_name' => 'Департамент маркетинга']])
            ->where('department.full_name', 'Отдел Дизайна')
        );

        // And the units under a department, read from the page of the one they
        // are under, since that is the only place they are listed.
        $this->get("/departments/{$top->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('departments/show')
            ->where('department.name', 'ДМ')
            ->where('department.children', [
                ['id' => $sub->id, 'name' => 'Группа вёрстки', 'full_name' => 'Группа вёрстки', 'total_count' => 0, 'heads' => []],
                ['id' => $unit->id, 'name' => 'ОД', 'full_name' => 'Отдел Дизайна', 'total_count' => 0, 'heads' => []],
            ])
        );
    }

    /**
     * The chart is one tab of the section and the list of departments another,
     * and the two are not open to the same people: everybody signed in reads the
     * structure, and only whoever may read the list reads the list.
     */
    public function test_the_chart_is_open_to_everybody_and_the_list_is_not()
    {
        $this->seed(PermissionSeeder::class);
        $department = Department::create(['name' => 'Отдел Дизайна']);

        $colleague = $this->colleague();
        $this->actingAs($colleague)->get('/departments')->assertOk();
        $this->actingAs($colleague)->get("/departments/{$department->id}")->assertOk();
        $this->actingAs($colleague)->get('/departments/units')->assertForbidden();
        $this->actingAs($colleague)->post('/departments/units', ['name' => 'Отдел продаж'])->assertForbidden();
        $this->assertNull(Department::firstWhere('name', 'Отдел продаж'));

        // Reading the list and keeping it are two rights still, and the page says
        // which of them it is looking at.
        $reader = User::factory()->create()->givePermissionTo(Directories::viewPermission('departments'));
        $this->actingAs($reader)->get('/departments')->assertOk();
        $this->assertFalse($this->actingAs($reader)->get('/departments/units')->assertOk()->viewData('page')['props']['canEdit']);
        $this->actingAs($reader)->put("/departments/units/{$department->id}", ['name' => 'Отдел сбыта'])->assertForbidden();

        $keeper = User::factory()->create()->givePermissionTo([
            Directories::viewPermission('departments'),
            Directories::editPermission('departments'),
        ]);
        $this->assertTrue($this->actingAs($keeper)->get('/departments/units')->assertOk()->viewData('page')['props']['canEdit']);
        $this->actingAs($keeper)->put("/departments/units/{$department->id}", ['name' => 'Отдел сбыта'])->assertRedirect();
        $this->assertSame('Отдел сбыта', $department->fresh()->name);
        $this->actingAs($keeper)->delete("/departments/units/{$department->id}")->assertRedirect();
        $this->assertNull(Department::find($department->id));
    }

    /**
     * "Отделы" left "Справочники" the way "Должности" did: the tab is gone and
     * the old address answers nothing, while the two rights behind the list stay
     * exactly as the production access table has them keyed.
     */
    public function test_the_reference_lists_no_longer_offer_the_departments_but_the_rights_stay()
    {
        $this->seed([RoleSeeder::class, PermissionSeeder::class]);
        $sysadmin = User::factory()->create()->assignRole('sysadmin');

        // Not a tab any more, and not an address of the section either.
        $this->assertNotContains('departments', Directories::TABS);
        $this->assertNotContains('departments', Directories::visibleTo($sysadmin));
        $this->assertNotContains('departments', Directories::editableBy($sysadmin));
        $this->actingAs($sysadmin)->get('/directories/departments')->assertNotFound();
        $this->actingAs($sysadmin)->get('/directories')->assertRedirect('/directories/roles');

        // The section is shut for somebody who may read nothing but the
        // departments — that list is elsewhere now and answers for itself.
        $reader = User::factory()->create()->givePermissionTo(Directories::viewPermission('departments'));
        $this->actingAs($reader)->get('/directories')->assertForbidden();
        $this->actingAs($reader)->get('/departments/units')->assertOk();

        // The two rights are still the two rights, and the access table still
        // offers both: the production table is keyed by these very strings.
        $this->assertContains('directories.view.departments', Directories::permissions());
        $this->assertContains('directories.edit.departments', Directories::permissions());
        $this->assertArrayHasKey('departments', Directories::LISTS);

        $props = $this->actingAs($sysadmin)->get('/directories/access')->assertOk()->viewData('page')['props'];

        $this->assertContains('directories.view.departments', array_column($props['directoryLists'], 'key'));
        $this->assertContains('directories.edit.departments', array_column($props['directoryEdits'], 'key'));
    }
}
