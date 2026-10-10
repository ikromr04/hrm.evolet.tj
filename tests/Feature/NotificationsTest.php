<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Equipment;
use App\Models\EquipmentType;
use App\Models\User;
use App\Notifications\EmployeeAdded;
use App\Notifications\EquipmentMoved;
use App\Notifications\InventoryDue;
use App\Notifications\PlacementChanged;
use Database\Seeders\EquipmentTypeSeeder;
use Database\Seeders\PositionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * What the bell holds: a line for a person about something that happened
 * without them. Who is told follows from the rights and from whom the event
 * was about, never from a list of names — and nobody is told about what they
 * did themselves.
 */
class NotificationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RoleSeeder::class, PositionSeeder::class, EquipmentTypeSeeder::class]);
    }

    private function sysadmin(): User
    {
        return User::factory()->create()->assignRole('sysadmin');
    }

    /** Somebody who sees the whole fleet and holds exactly these rights besides. */
    private function keeper(string ...$rights): User
    {
        return User::factory()->create()->givePermissionTo(['equipment.view.all', ...$rights]);
    }

    private function unit(?User $holder = null, array $attributes = []): Equipment
    {
        $unit = Equipment::factory()->ofType(EquipmentType::firstWhere('name', 'Ноутбуки'));

        return ($holder === null ? $unit : $unit->issuedTo($holder->id))->create([
            'name' => 'Ноутбук Lenovo ThinkPad E14',
            'inventory_number' => 'EV-0155',
            ...$attributes,
        ]);
    }

    /** The sentences behind somebody's bell, newest first. */
    private function lines(User $user): array
    {
        return $user->notifications()->get()->map(fn (DatabaseNotification $n) => $n->data['text'])->all();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function newcomer(array $overrides = []): array
    {
        return [
            'surname' => 'Азимова',
            'name' => 'Нилуфар',
            'patronymic' => 'Рустамовна',
            'sex' => 'female',
            'email' => 'nilufar@evolet.tj',
            'birth_date' => '1990-04-17',
            'birth_place' => 'г. Худжанд',
            'citizenship' => ['Таджикистан'],
            'hired_at' => '2026-03-02',
            'roles' => [],
            'positions' => [],
            'departments' => [],
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function personal(User $employee, array $overrides = []): array
    {
        return [
            'surname' => $employee->surname,
            'name' => $employee->name,
            'patronymic' => $employee->patronymic,
            'sex' => $employee->sex,
            'birth_date' => '1990-04-17',
            'birth_place' => 'г. Худжанд',
            'citizenship' => ['Таджикистан'],
            'nationality' => 'таджичка',
            'home_address' => 'г. Душанбе, ул. Рудаки, 25',
            'roles' => $employee->roles()->pluck('name')->all(),
            'positions' => [],
            'departments' => $employee->departments()->pluck('departments.id')->all(),
            ...$overrides,
        ];
    }

    // --- Оборудование: выдано / принято ---

    public function test_a_unit_handed_over_is_told_to_whoever_receives_it_and_not_to_whoever_hands_it()
    {
        $storekeeper = $this->keeper('equipment.issue');
        $holder = $this->colleague();
        $unit = $this->unit();

        $this->actingAs($storekeeper)
            ->post("/equipment/{$unit->id}/issue", ['holder_user_id' => $holder->id, 'issued_at' => now()->toDateString()])
            ->assertSessionHasNoErrors();

        $this->assertSame(['Вам выдано: Ноутбук Lenovo ThinkPad E14, инв. № EV-0155'], $this->lines($holder));
        $this->assertSame(EquipmentMoved::class, $holder->notifications()->first()->type);
        $this->assertSame([], $this->lines($storekeeper));
    }

    public function test_nobody_is_told_about_a_unit_they_handed_to_themselves()
    {
        $storekeeper = $this->keeper('equipment.issue', 'equipment.take');
        $unit = $this->unit();

        $this->actingAs($storekeeper)
            ->post("/equipment/{$unit->id}/issue", ['holder_user_id' => $storekeeper->id, 'issued_at' => now()->toDateString()])
            ->assertSessionHasNoErrors();
        $this->actingAs($storekeeper)
            ->post("/equipment/{$unit->id}/take", ['returned_at' => now()->toDateString()])
            ->assertSessionHasNoErrors();

        $this->assertSame(0, DatabaseNotification::count());
    }

    public function test_a_unit_taken_back_is_told_to_whoever_held_it()
    {
        $storekeeper = $this->keeper('equipment.take');
        $holder = $this->colleague();
        $unit = $this->unit($holder);

        $this->actingAs($storekeeper)
            ->post("/equipment/{$unit->id}/take", ['returned_at' => now()->toDateString()])
            ->assertSessionHasNoErrors();

        $this->assertSame(['У вас принято: Ноутбук Lenovo ThinkPad E14, инв. № EV-0155'], $this->lines($holder));
        $this->assertSame([], $this->lines($storekeeper));
    }

    public function test_a_unit_moved_from_one_colleague_to_another_is_told_to_both()
    {
        $storekeeper = $this->keeper('equipment.issue');
        [$former, $next] = [$this->colleague(), $this->colleague()];
        $unit = $this->unit($former);

        $this->actingAs($storekeeper)
            ->post("/equipment/{$unit->id}/issue", ['holder_user_id' => $next->id, 'issued_at' => now()->toDateString()])
            ->assertSessionHasNoErrors();

        $this->assertSame(['У вас принято: Ноутбук Lenovo ThinkPad E14, инв. № EV-0155'], $this->lines($former));
        $this->assertSame(['Вам выдано: Ноутбук Lenovo ThinkPad E14, инв. № EV-0155'], $this->lines($next));
    }

    public function test_a_unit_written_off_while_held_is_told_to_its_holder()
    {
        $accountant = $this->keeper('equipment.write_off');
        $holder = $this->colleague();
        $unit = $this->unit($holder);

        $this->actingAs($accountant)
            ->post("/equipment/{$unit->id}/write-off", ['written_off_at' => now()->toDateString()])
            ->assertSessionHasNoErrors();

        $this->assertSame(['У вас списано: Ноутбук Lenovo ThinkPad E14, инв. № EV-0155'], $this->lines($holder));
    }

    public function test_a_unit_put_on_the_books_for_somebody_is_told_to_them()
    {
        $holder = $this->colleague();

        $this->actingAs($this->sysadmin())
            ->post('/equipment', [
                'equipment_type_id' => EquipmentType::firstWhere('name', 'Ноутбуки')->id,
                'name' => 'Ноутбук Dell Latitude 5440',
                'inventory_number' => 'EV-0200',
                'holder_user_id' => $holder->id,
                'issued_at' => now()->toDateString(),
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(['Вам выдано: Ноутбук Dell Latitude 5440, инв. № EV-0200'], $this->lines($holder));
    }

    public function test_units_handed_over_from_a_card_are_told_one_by_one()
    {
        $holder = $this->colleague();
        $first = $this->unit();
        $second = $this->unit(attributes: ['name' => 'Монитор Dell P2422H', 'inventory_number' => 'EV-0156']);

        $this->actingAs($this->sysadmin())
            ->post("/employees/{$holder->id}/equipment", ['equipment' => [$first->id, $second->id]])
            ->assertSessionHasNoErrors();

        $this->assertEqualsCanonicalizing([
            'Вам выдано: Ноутбук Lenovo ThinkPad E14, инв. № EV-0155',
            'Вам выдано: Монитор Dell P2422H, инв. № EV-0156',
        ], $this->lines($holder));
    }

    public function test_deleting_an_employee_returns_their_units_without_telling_anybody_and_clears_their_bell()
    {
        $admin = $this->sysadmin();
        $employee = $this->colleague();
        $this->unit($employee);
        $employee->notify(new EmployeeAdded($admin));

        $this->actingAs($admin)->delete("/employees/{$employee->id}")->assertRedirect();

        $this->assertSame('stock', Equipment::first()->status);
        $this->assertSame(0, DatabaseNotification::count());
    }

    public function test_somebody_who_no_longer_works_here_is_not_told_about_a_unit_taken_from_them()
    {
        $holder = $this->colleague(['status' => 'fired']);
        $unit = $this->unit($holder);

        $this->actingAs($this->keeper('equipment.take'))
            ->post("/equipment/{$unit->id}/take", ['returned_at' => now()->toDateString()])
            ->assertSessionHasNoErrors();

        $this->assertSame(0, DatabaseNotification::count());
    }

    // --- Срок инвентаризации ---

    public function test_a_due_inventory_is_told_once_to_those_who_keep_that_unit()
    {
        $this->travelTo('2026-11-05 09:00:00');

        $admin = $this->sysadmin();
        $keeper = $this->keeper('equipment.edit.state');
        // Sees the fleet but does not keep its inventory dates.
        $onlooker = $this->keeper('equipment.edit.specs');
        // Keeps the dates, but only of what is on their own desk.
        $own = User::factory()->create()->givePermissionTo(['equipment.view.own', 'equipment.edit.state']);
        // Would have been told, had they still worked here.
        $gone = $this->keeper('equipment.edit.state');
        $gone->update(['status' => 'fired']);

        $due = $this->unit(attributes: ['next_inventory_at' => '2026-11-05']);
        $this->unit(attributes: ['inventory_number' => 'EV-0156', 'next_inventory_at' => '2026-11-06']);
        $this->unit(attributes: ['inventory_number' => 'EV-0157', 'next_inventory_at' => '2026-10-01', 'status' => 'written_off']);

        $this->artisan('hrm:inventory-due')->assertSuccessful();

        $line = 'Подошёл срок инвентаризации: Ноутбук Lenovo ThinkPad E14, инв. № EV-0155';
        $this->assertSame([$line], $this->lines($keeper));
        $this->assertSame([$line], $this->lines($admin));
        $this->assertSame([], $this->lines($onlooker));
        $this->assertSame([], $this->lines($own));
        $this->assertSame([], $this->lines($gone));

        $reminder = $keeper->notifications()->first();
        $this->assertSame(InventoryDue::class, $reminder->type);
        $this->assertSame('2026-11-05', $reminder->data['due']);
        $this->assertSame(['type' => 'equipment', 'id' => $due->id], $reminder->data['target']);

        // The next day, and the day after: the unit is still uncounted, and
        // nobody is told a second time. The unit due on the 6th has its turn.
        $this->travelTo('2026-11-06 09:00:00');
        $this->artisan('hrm:inventory-due')->assertSuccessful();
        $this->artisan('hrm:inventory-due')->assertSuccessful();

        $this->assertSame(2, $keeper->notifications()->count());
        $this->assertSame(1, $keeper->notifications()->where('data->reminder', "{$due->id}:2026-11-05")->count());
    }

    public function test_a_keeper_of_their_own_units_is_told_about_those()
    {
        $this->travelTo('2026-11-05 09:00:00');

        $own = User::factory()->create()->givePermissionTo(['equipment.view.own', 'equipment.edit.state']);
        $this->unit($own, ['next_inventory_at' => '2026-11-01']);

        $this->artisan('hrm:inventory-due')->assertSuccessful();

        $this->assertCount(1, $this->lines($own));
    }

    public function test_a_new_date_on_the_same_unit_is_a_new_reminder()
    {
        $this->travelTo('2026-11-05 09:00:00');

        $keeper = $this->keeper('equipment.edit.state');
        $unit = $this->unit(attributes: ['next_inventory_at' => '2026-11-05']);

        $this->artisan('hrm:inventory-due');
        $unit->update(['next_inventory_at' => '2027-05-05']);

        $this->travelTo('2027-05-05 09:00:00');
        $this->artisan('hrm:inventory-due');
        $this->artisan('hrm:inventory-due');

        $this->assertSame(2, $keeper->notifications()->count());
    }

    // --- Смена позиции / должности / отдела ---

    public function test_a_change_of_position_or_department_is_told_to_the_employee()
    {
        $admin = $this->sysadmin();
        $employee = $this->colleague();
        $department = Department::create(['name' => 'Отдел продаж', 'abbreviation' => 'ОП']);

        $this->actingAs($admin)
            ->put("/employees/{$employee->id}/personal", $this->personal($employee, [
                'roles' => ['specialist'],
                'departments' => [$department->id],
            ]))
            ->assertSessionHasNoErrors();

        // One line per save, naming everything that moved — and naming a
        // department the way the card they are sent to names it.
        $this->assertSame(['Вам изменили позицию: Специалист; отдел: ОП'], $this->lines($employee));
        $this->assertSame(PlacementChanged::class, $employee->notifications()->first()->type);
        $this->assertSame([], $this->lines($admin));

        // Saved again as it is: nothing moved, nothing is said.
        $this->actingAs($admin)
            ->put("/employees/{$employee->id}/personal", $this->personal($employee))
            ->assertSessionHasNoErrors();

        $this->assertCount(1, $this->lines($employee));

        // Taken out of the department: the line says what is left.
        $this->travel(1)->minutes();
        $this->actingAs($admin)
            ->put("/employees/{$employee->id}/personal", $this->personal($employee, ['departments' => []]))
            ->assertSessionHasNoErrors();

        $this->assertSame('Вам изменили отдел: не указан', $this->lines($employee)[0]);
    }

    public function test_nobody_is_told_about_a_change_they_made_to_their_own_card()
    {
        $admin = $this->sysadmin();
        $department = Department::create(['name' => 'Отдел продаж']);

        $this->actingAs($admin)
            ->put("/employees/{$admin->id}/personal", $this->personal($admin, ['departments' => [$department->id]]))
            ->assertSessionHasNoErrors();

        $this->assertSame([$department->id], $admin->departments()->pluck('departments.id')->all());
        $this->assertSame(0, DatabaseNotification::count());
    }

    public function test_being_put_into_a_department_from_the_directory_is_told_too()
    {
        $admin = $this->sysadmin();
        [$stays, $joins, $leaves] = [$this->colleague(), $this->colleague(), $this->colleague()];
        $department = Department::create(['name' => 'Отдел продаж']);
        $department->users()->attach([$stays->id, $leaves->id]);

        $this->actingAs($admin)
            ->put("/directories/departments/{$department->id}", [
                // Renamed in the same save: a new name alone is not a move.
                'name' => 'Отдел сбыта',
                'head_ids' => [],
                'member_ids' => [$stays->id, $joins->id],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame([], $this->lines($stays));
        $this->assertSame(['Вам изменили отдел: Отдел сбыта'], $this->lines($joins));
        $this->assertSame(['Вам изменили отдел: не указан'], $this->lines($leaves));
    }

    // --- Новый сотрудник ---

    public function test_a_new_colleague_is_told_to_everybody_who_adds_people_except_whoever_added_them()
    {
        $admin = $this->sysadmin();
        $creator = $this->colleague()->givePermissionTo('employees.create');
        $other = $this->colleague()->givePermissionTo('employees.create');
        $bystander = $this->colleague();
        // The right is theirs by name, and so is the exception that takes it away.
        $excepted = $this->colleague()->givePermissionTo('employees.create');
        $excepted->permissionOverrides()->create(['permission' => 'employees.create', 'allowed' => false]);
        // No position carries it, but they were given it personally.
        $granted = $this->colleague();
        $granted->permissionOverrides()->create(['permission' => 'employees.create', 'allowed' => true]);

        $this->actingAs($creator)->post('/employees', $this->newcomer())->assertSessionHasNoErrors();

        $line = 'Добавлен сотрудник: Азимова Нилуфар';
        $this->assertSame([$line], $this->lines($other));
        $this->assertSame([$line], $this->lines($admin));
        $this->assertSame([$line], $this->lines($granted));
        $this->assertSame([], $this->lines($creator));
        $this->assertSame([], $this->lines($bystander));
        $this->assertSame([], $this->lines($excepted));
        // The newcomer gets a letter, not a line about themselves — and the
        // wizard filing them under a position is not a "change" to tell them of.
        $this->assertSame([], $this->lines(User::firstWhere('email', 'nilufar@evolet.tj')));
    }

    public function test_the_wizard_going_on_to_fill_a_card_in_is_not_a_change_of_position()
    {
        $creator = $this->sysadmin();

        $this->actingAs($creator)->post('/employees', $this->newcomer(['continue' => true]))->assertSessionHasNoErrors();
        $employee = User::firstWhere('email', 'nilufar@evolet.tj');

        $this->actingAs($creator)
            ->put("/employees/{$employee->id}/personal", $this->personal($employee, ['roles' => ['specialist']]))
            ->assertSessionHasNoErrors();

        $this->assertTrue($employee->fresh()->hasRole('specialist'));
        $this->assertSame([], $this->lines($employee));
    }

    // --- Колокольчик ---

    public function test_every_page_carries_the_count_of_unread_lines_and_nothing_else()
    {
        $user = $this->colleague();
        $somebody = $this->colleague();
        $user->notify(new EmployeeAdded($somebody));
        $user->notify(new EmployeeAdded($somebody));
        $user->notifications()->first()->markAsRead();
        $somebody->notify(new EmployeeAdded($user));

        $this->actingAs($user)->get('/profile')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('notifications', ['unread' => 1]));
    }

    public function test_the_list_holds_ones_own_lines_only()
    {
        $user = $this->colleague();
        $somebody = $this->colleague();
        $user->notify(new EmployeeAdded($somebody));
        $somebody->notify(new EmployeeAdded($user));

        $this->get('/notifications')->assertRedirect('/login');

        $this->actingAs($user)->getJson('/notifications')
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('unread', 1)
            ->assertJsonPath('items.0.id', $user->notifications()->first()->id)
            ->assertJsonPath('items.0.kind', 'employee.added')
            ->assertJsonPath('items.0.text', "Добавлен сотрудник: {$somebody->surname} {$somebody->name}")
            ->assertJsonPath('items.0.read', false);
    }

    public function test_the_list_stops_at_the_latest_thirty()
    {
        $user = $this->colleague();

        foreach (range(1, 32) as $ignored) {
            $user->notify(new EmployeeAdded($user));
        }

        $this->actingAs($user)->getJson('/notifications')->assertJsonCount(30, 'items')->assertJsonPath('unread', 32);
    }

    public function test_a_line_is_marked_read_by_its_owner_and_by_nobody_else()
    {
        $user = $this->colleague();
        $somebody = $this->colleague();
        $user->notify(new EmployeeAdded($somebody));
        $user->notify(new EmployeeAdded($somebody));
        $somebody->notify(new EmployeeAdded($user));

        $own = $user->notifications()->first();
        $foreign = $somebody->notifications()->first();

        $this->actingAs($user)->postJson("/notifications/{$foreign->id}/read")->assertNotFound();
        $this->assertNull($foreign->fresh()->read_at);

        $this->actingAs($user)->postJson("/notifications/{$own->id}/read")->assertOk()->assertJsonPath('unread', 1);
        $this->assertNotNull($own->fresh()->read_at);
    }

    public function test_reading_everything_reads_ones_own_lines_only()
    {
        $user = $this->colleague();
        $somebody = $this->colleague();
        $user->notify(new EmployeeAdded($somebody));
        $user->notify(new EmployeeAdded($somebody));
        $somebody->notify(new EmployeeAdded($user));

        $this->post('/notifications/read')->assertRedirect('/login');

        $this->actingAs($user)->postJson('/notifications/read')->assertOk()->assertJsonPath('unread', 0);

        $this->assertSame(0, $user->unreadNotifications()->count());
        $this->assertSame(1, $somebody->unreadNotifications()->count());
    }

    public function test_a_line_leads_to_a_card_only_for_a_reader_who_may_open_it()
    {
        $newcomer = User::factory()->create();
        $unit = $this->unit();

        // Adds people and reads the staff list; sees no part of the fleet.
        $reads = $this->colleague()->givePermissionTo('employees.create');
        $reads->revokePermissionTo('equipment.view.own');
        // Adds people without the right to open anybody's card; sees the fleet.
        $blind = User::factory()->create()->givePermissionTo(['employees.create', 'equipment.view.all']);

        foreach ([$reads, $blind] as $reader) {
            $reader->notify(new EquipmentMoved($unit, 'taken'));
            $reader->notify(new EmployeeAdded($newcomer));
        }

        $hrefs = fn (User $reader) => collect($this->actingAs($reader)->getJson('/notifications')->assertOk()->json('items'))
            ->pluck('href', 'kind')
            ->all();

        // Compared by kind: the two lines are a moment apart at most, and their
        // order is not what this is about.
        $this->assertEquals(['employee.added' => "/employees/{$newcomer->id}", 'equipment.taken' => null], $hrefs($reads));
        $this->assertEquals(['employee.added' => null, 'equipment.taken' => "/equipment/{$unit->id}"], $hrefs($blind));
    }

    public function test_a_unit_leads_to_its_card_only_while_it_is_still_ones_own()
    {
        $storekeeper = $this->keeper('equipment.issue', 'equipment.take');
        // Sees what is on their own desk and nothing else of the fleet.
        $holder = $this->colleague();
        $unit = $this->unit();

        $this->actingAs($storekeeper)
            ->post("/equipment/{$unit->id}/issue", ['holder_user_id' => $holder->id, 'issued_at' => now()->toDateString()]);

        $this->actingAs($holder)->getJson('/notifications')->assertJsonPath('items.0.href', "/equipment/{$unit->id}");

        $this->actingAs($storekeeper)->post("/equipment/{$unit->id}/take", ['returned_at' => now()->toDateString()]);

        // Both lines are about a unit that is no longer theirs to open.
        $this->actingAs($holder)->getJson('/notifications')
            ->assertJsonCount(2, 'items')
            ->assertJsonPath('items.0.href', null)
            ->assertJsonPath('items.1.href', null);
    }

    public function test_a_line_about_a_card_that_is_gone_stays_as_plain_text()
    {
        $reader = $this->sysadmin();
        $newcomer = User::factory()->create();
        $reader->notify(new EmployeeAdded($newcomer));
        $newcomer->delete();

        $this->actingAs($reader)->getJson('/notifications')
            ->assertJsonPath('items.0.text', "Добавлен сотрудник: {$newcomer->surname} {$newcomer->name}")
            ->assertJsonPath('items.0.href', null);
    }
}
