<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Equipment;
use App\Models\EquipmentType;
use App\Models\Position;
use App\Models\User;
use App\Models\UserDetail;
use App\Support\Directories;
use Database\Seeders\EquipmentTypeSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Inertia;
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
    /**
     * The third tab of a department's page: what the department answers for
     * and what its people hold, read in one place.
     *
     * The list is left out of an ordinary visit and asked for by name when the
     * tab is opened, because this page is opened by everybody signed in and
     * mostly to read the regulation.
     */
    public function test_the_equipment_tab_is_fetched_when_it_is_opened_and_reads_both_halves()
    {
        $this->seed([RoleSeeder::class, PermissionSeeder::class, EquipmentTypeSeeder::class]);
        $department = Department::create(['name' => 'Департамент анализа', 'abbreviation' => 'ДАС']);
        $unit = Department::create(['name' => 'Отдел аналитики', 'abbreviation' => 'ОА', 'parent_id' => $department->id]);

        $member = User::factory()->create(['surname' => 'Бобоев', 'name' => 'Сухроб']);
        $member->departments()->attach($unit->id);
        $laptops = EquipmentType::firstWhere('name', 'Ноутбуки');

        Equipment::factory()->ofType($laptops)->heldBy($unit->id)->create(['name' => 'Принтер отдела']);
        Equipment::factory()->ofType($laptops)->issuedTo($member->id)->create(['name' => 'Ноутбук Бобоева']);
        // Nobody's: on the balance sheet, so no department's either.
        Equipment::factory()->ofType($laptops)->create(['name' => 'Ноутбук на складе']);
        // And the equipment of the department above this one, which has a page
        // of its own: this tab is about this department and not the branch.
        Equipment::factory()->ofType($laptops)->heldBy($department->id)->create(['name' => 'Принтер департамента']);

        $admin = User::factory()->create()->assignRole('sysadmin');

        // An ordinary visit: the tab knows it may read, and carries no units.
        $this->actingAs($admin)->get("/departments/{$unit->id}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('equipment.can_read', true)
                ->missing('equipmentUnits')
            );

        // And the visit the tab makes when it is opened, which asks for that
        // one prop and is given nothing else. A partial visit answers with the
        // page object as JSON rather than as a page, so it is read as JSON.
        $props = $this->tabOpened($admin, $unit);

        $this->assertSame(['Принтер отдела'], array_column($props['equipmentUnits']['own'], 'name'));
        // Each under the name of whoever holds it.
        $this->assertSame(['Ноутбук Бобоева'], array_column($props['equipmentUnits']['people'], 'name'));
        $this->assertSame('Бобоев Сухроб', $props['equipmentUnits']['people'][0]['holder']['name']);
        // And nothing else was read to answer a question about equipment.
        $this->assertSame(['equipmentUnits'], array_keys(array_diff_key($props, array_flip(['auth', 'ziggy', 'notifications', 'flash', 'errors']))));
    }

    /**
     * The visit the equipment tab makes when it is opened: that one prop by
     * name, which is what `router.reload({ only: [...] })` sends.
     *
     * @return array<string, mixed>
     */
    private function tabOpened(User $viewer, Department $department): array
    {
        return $this->actingAs($viewer)
            ->get("/departments/{$department->id}", [
                'X-Inertia' => true,
                'X-Inertia-Partial-Data' => 'equipmentUnits',
                'X-Inertia-Partial-Component' => 'departments/show',
                'X-Inertia-Version' => Inertia::getVersion(),
            ])
            ->assertOk()
            ->json('props');
    }

    /**
     * Who may not read equipment is told that, and not that the department has
     * none: the fleet is a section with rights of its own, and a department's
     * page is open to everybody.
     */
    public function test_the_equipment_tab_says_whether_this_viewer_may_read_it_at_all()
    {
        $this->seed([RoleSeeder::class, PermissionSeeder::class, EquipmentTypeSeeder::class]);
        $unit = Department::create(['name' => 'Отдел аналитики']);
        $mine = User::factory()->create();
        $mine->departments()->attach($unit->id);
        $stranger = User::factory()->create();
        $stranger->departments()->attach($unit->id);

        Equipment::factory()->ofType(EquipmentType::firstWhere('name', 'Ноутбуки'))->issuedTo($mine->id)->create(['name' => 'Мой ноутбук']);
        Equipment::factory()->ofType(EquipmentType::firstWhere('name', 'Ноутбуки'))->issuedTo($stranger->id)->create(['name' => 'Чужой ноутбук']);

        // No right to the fleet at all — which is a position that was given
        // none, since every position is created able to see its own — and the
        // tab says so of the viewer rather than of the department.
        $this->actingAs(User::factory()->create())->get("/departments/{$unit->id}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('equipment.can_read', false));

        // One scope of it: the tab opens, and shows exactly that scope — the
        // same narrowing the equipment section itself does.
        $own = $this->mayLookAround($mine)->givePermissionTo('equipment.view.own');

        $this->actingAs($own)->get("/departments/{$unit->id}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('equipment.can_read', true));

        $units = $this->tabOpened($own, $unit)['equipmentUnits'];

        $this->assertSame(['Мой ноутбук'], array_column($units['people'], 'name'));
        $this->assertSame([], $units['own']);
    }

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
