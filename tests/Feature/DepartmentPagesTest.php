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
 * structure down, at /departments.
 */
class DepartmentPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_sent_to_login()
    {
        $this->get('/org-chart')->assertRedirect('/login');
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

        $this->actingAs($this->mayLookAround($a))->get('/org-chart')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('departments/index')
            ->where('employees_count', 3)
            ->where('departments.0.name', 'Департамент')
            ->where('departments.0.total_count', 3)
            ->where('departments.0.heads', [['id' => $head->id, 'name' => 'Азимова Нигина', 'avatar' => null, 'avatar_original' => null]])
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
            // Everybody who works here, whoever leads the place first and the
            // rest by name; the one who no longer works here is in neither list.
            ->has('department.members', 2)
            ->where('department.members.0.id', $head->id)
            ->where('department.members.1', [
                'id' => $member->id,
                'name' => 'Бобоев Фаррух',
                'avatar' => null,
                'avatar_original' => null,
                'email' => $member->email,
                // A job title is named to everybody and numbered only for
                // whoever may open the job: this colleague may not, so the
                // badge stays a word. The department it belongs to is named
                // beside it, and this one belongs to none.
                'positions' => [['id' => null, 'name' => 'Дизайнер', 'unit' => null]],
                // Which unit under this department the person works in, and
                // whether they lead it: both read by the column at the side.
                'unit' => null,
                'is_head' => false,
            ])
        );
    }

    public function test_a_job_title_in_a_card_is_a_way_to_the_job_for_whoever_may_read_the_jobs()
    {
        $unit = Department::create(['name' => 'Отдел']);
        $member = User::factory()->create();
        $position = Position::create(['name' => 'Дизайнер']);
        $member->positions()->attach($position);
        $unit->users()->attach($member, ['is_head' => false]);

        $viewer = $this->colleague();
        $viewer->givePermissionTo(Directories::viewPermission('positions'));

        $this->actingAs($viewer)->get("/departments/{$unit->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('department.members.0.positions', [['id' => $position->id, 'name' => 'Дизайнер', 'unit' => null]])
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

        $this->get('/org-chart')->assertOk()->assertInertia(fn (Assert $page) => $page
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
        $this->assertSame(['equipmentUnits'], array_keys(array_diff_key($props, array_flip(['auth', 'ziggy', 'flash', 'errors']))));
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

    /**
     * The way into «Изменить отдел» from the department's own page: offered to
     * whoever keeps the structure, and not drawn at all for everybody else.
     *
     * What the window chooses from — every department and every working
     * employee — is left out of an ordinary visit and asked for by name when
     * the control is pressed, the way the equipment is: this page is opened by
     * everybody signed in, mostly to read a document.
     */
    public function test_the_page_offers_the_editing_window_to_whoever_keeps_the_structure()
    {
        $this->seed(PermissionSeeder::class);
        $top = Department::create(['name' => 'Департамент маркетинга', 'abbreviation' => 'ДМ']);
        $unit = Department::create(['name' => 'Отдел Дизайна', 'abbreviation' => 'ОД', 'parent_id' => $top->id]);
        $head = User::factory()->create(['surname' => 'Азимова', 'name' => 'Нигина']);
        $member = User::factory()->create(['surname' => 'Бобоев', 'name' => 'Фаррух']);
        $fired = User::factory()->create(['status' => 'fired']);
        $leading = Position::create(['department_id' => $unit->id, 'name' => 'Руководитель отдела дизайна', 'is_head' => true]);
        $plain = Position::create(['department_id' => $unit->id, 'name' => 'Дизайнер']);
        $head->positions()->attach($leading);
        $member->positions()->attach($plain);
        $fired->positions()->attach($plain);
        $unit->users()->attach([$head->id => ['is_head' => true], $member->id => ['is_head' => false], $fired->id => ['is_head' => false]]);

        $colleague = $this->colleague();
        $keeper = $this->keeper();

        // Open to everybody as it was, and with no way in: not a refused
        // control but no control, and none of the staff of the company sent to
        // somebody who came to read the regulation.
        $this->actingAs($colleague)->get("/departments/{$unit->id}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('editor.can_edit', false)->missing('editorOptions'));

        $this->actingAs($keeper)->get("/departments/{$unit->id}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('editor.can_edit', true)->missing('editorOptions'));

        // The visit the control makes when it is pressed: that one prop, and
        // the record in the shape «Подразделения» keeps it in.
        $options = $this->windowOpened($keeper, $unit)['editorOptions'];

        $this->assertSame('Отдел Дизайна', $options['department']['name']);
        $this->assertSame('ОД', $options['department']['abbreviation']);
        $this->assertSame($top->id, $options['department']['parent_id']);
        // The two names and the placement are the whole of what the window
        // writes. Nobody is chosen in it: who belongs to a department and who
        // leads it both follow from its positions, so the staff of the company
        // is not sent — and neither is anything for choosing a head, because
        // that is decided where a position is kept and not in a department's
        // form.
        $this->assertArrayNotHasKey('employees', $options);
        $this->assertArrayNotHasKey('heads', $options['department']);
        // Where it could sit.
        $this->assertEqualsCanonicalizing([$top->id, $unit->id], array_column($options['departments'], 'id'));

        // Asked for by somebody without the right, it answers with nothing: the
        // control is not drawn for them, and a request written by hand is not a
        // way round that.
        $this->assertNull($this->windowOpened($colleague, $unit)['editorOptions']);
    }

    /**
     * The control on the page writes through the one set of rules, because it
     * is the same form saving to the same address: moving the crown moves who
     * heads the department, the former head stays in it as an ordinary member
     * because he still holds his position, and the page is redrawn from the
     * answer.
     */
    public function test_the_page_writes_who_leads_through_the_one_set_of_rules()
    {
        $this->seed(PermissionSeeder::class);
        $unit = Department::create(['name' => 'Отдел Дизайна', 'abbreviation' => 'ОД']);
        $former = User::factory()->create(['surname' => 'Азимова', 'name' => 'Нигина']);
        $promoted = User::factory()->create(['surname' => 'Бобоев', 'name' => 'Фаррух']);
        $wasLeading = Position::create(['department_id' => $unit->id, 'name' => 'Ведущий дизайнер', 'is_head' => true]);
        $takesOver = Position::create(['department_id' => $unit->id, 'name' => 'Арт-директор']);
        $former->positions()->attach($wasLeading);
        $promoted->positions()->attach($takesOver);
        $unit->users()->attach([$former->id => ['is_head' => true], $promoted->id => ['is_head' => false]]);

        $keeper = $this->keeper();

        // Exactly what the window sends: the two names, the placement, and
        // which of the department's positions carry the crown.
        $this->actingAs($keeper)->put("/departments/{$unit->id}", [
            'name' => 'Отдел Дизайна',
            'abbreviation' => 'ОД',
            'parent_id' => null,
            'leading_position_ids' => [$takesOver->id],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame([$promoted->id], $unit->heads()->pluck('users.id')->all());
        // The former head keeps the position he holds, so he keeps the
        // department: losing the crown is not losing the job.
        $this->assertEqualsCanonicalizing([$former->id, $promoted->id], $unit->users()->pluck('users.id')->all());

        // And the page that follows the save shows both, without a reload by
        // hand: the new head at the top of the staff, the former one among them.
        $this->actingAs($keeper)->get("/departments/{$unit->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('department.heads', fn ($heads) => collect($heads)->pluck('id')->all() === [$promoted->id])
            ->where('department.members', fn ($members) => collect($members)->pluck('id')->all() === [$promoted->id, $former->id])
        );

        // The address is the one the list saves through, so it is shut to
        // somebody without the right wherever the window was opened from.
        $this->actingAs($this->colleague())->put("/departments/{$unit->id}", ['name' => 'Отдел сбыта'])->assertForbidden();
    }

    /**
     * Whoever keeps the structure: the pair of rights «Подразделения» is kept
     * by, which is what the window on a department's page asks for too.
     */
    private function keeper(): User
    {
        return User::factory()->create()->givePermissionTo([
            Directories::viewPermission('departments'),
            Directories::editPermission('departments'),
        ]);
    }

    /**
     * The visit the control makes when it is pressed: that one prop by name,
     * which is what `router.reload({ only: [...] })` sends.
     *
     * @return array<string, mixed>
     */
    private function windowOpened(User $viewer, Department $department): array
    {
        return $this->actingAs($viewer)
            ->get("/departments/{$department->id}", [
                'X-Inertia' => true,
                'X-Inertia-Partial-Data' => 'editorOptions',
                'X-Inertia-Partial-Component' => 'departments/show',
                'X-Inertia-Version' => Inertia::getVersion(),
            ])
            ->assertOk()
            ->json('props');
    }

    public function test_the_chart_is_open_to_everybody_and_the_list_is_not()
    {
        $this->seed(PermissionSeeder::class);
        $department = Department::create(['name' => 'Отдел Дизайна']);

        $colleague = $this->colleague();
        $this->actingAs($colleague)->get('/org-chart')->assertOk();
        $this->actingAs($colleague)->get("/departments/{$department->id}")->assertOk();
        $this->actingAs($colleague)->get('/departments')->assertForbidden();
        $this->actingAs($colleague)->post('/departments', ['name' => 'Отдел продаж'])->assertForbidden();
        $this->assertNull(Department::firstWhere('name', 'Отдел продаж'));

        // Reading the list and keeping it are two rights still, and the page says
        // which of them it is looking at.
        $reader = User::factory()->create()->givePermissionTo(Directories::viewPermission('departments'));
        $this->actingAs($reader)->get('/org-chart')->assertOk();
        $this->assertFalse($this->actingAs($reader)->get('/departments')->assertOk()->viewData('page')['props']['canEdit']);
        $this->actingAs($reader)->put("/departments/{$department->id}", ['name' => 'Отдел сбыта'])->assertForbidden();

        $keeper = User::factory()->create()->givePermissionTo([
            Directories::viewPermission('departments'),
            Directories::editPermission('departments'),
        ]);
        $this->assertTrue($this->actingAs($keeper)->get('/departments')->assertOk()->viewData('page')['props']['canEdit']);
        $this->actingAs($keeper)->put("/departments/{$department->id}", ['name' => 'Отдел сбыта'])->assertRedirect();
        $this->assertSame('Отдел сбыта', $department->fresh()->name);
        $this->actingAs($keeper)->delete("/departments/{$department->id}")->assertRedirect();
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
        $this->actingAs($reader)->get('/departments')->assertOk();

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
