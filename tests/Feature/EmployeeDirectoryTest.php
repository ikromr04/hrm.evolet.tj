<?php

namespace Tests\Feature;

use App\Models\Citizenship;
use App\Models\Department;
use App\Models\Position;
use App\Models\User;
use App\Models\UserChild;
use App\Models\UserDetail;
use Database\Seeders\PositionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class EmployeeDirectoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RoleSeeder::class, PositionSeeder::class]);
    }

    private function giveTitles(User $user, string ...$names): User
    {
        $user->positions()->attach(Position::whereIn('name', $names)->pluck('id'));

        return $user;
    }

    private function titleId(string $name): int
    {
        return Position::firstWhere('name', $name)->id;
    }

    /**
     * A position reads as «Переводчик (НО)», by the department it belongs to —
     * the same name may be filed in two of them, and the name alone no longer
     * says which position it is. Seeded without the structure the positions are
     * unplaced, so a test that reads one off a row files it somewhere first.
     */
    private function fileUnder(string $position, string $department, string $abbreviation): Position
    {
        $filed = Position::firstWhere('name', $position);
        $filed->department_id = Department::create(['name' => $department, 'abbreviation' => $abbreviation])->id;
        $filed->save();

        return $filed;
    }

    public function test_guests_are_redirected_to_the_login_page()
    {
        $this->get('/employees')->assertRedirect('/login');
    }

    public function test_directory_lists_real_users_ten_per_page()
    {
        User::factory(12)->create();
        $this->actingAs($this->mayLookAround(User::first()));

        $this->get('/employees')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('employees/index')
                ->has('employees.data', 10)
                ->where('employees.total', 12)
                ->where('total', 12)
                ->has('options.positions', 39)
                ->where('perPage', 10)
            );

        $this->get('/employees?page=2')
            ->assertInertia(fn (Assert $page) => $page->has('employees.data', 2));
    }

    public function test_colleagues_see_public_fields_only()
    {
        $colleague = User::factory()->has(UserDetail::factory(), 'details')->create(['surname' => 'Азимова']);
        $this->fileUnder('Переводчик', 'Научный отдел', 'НО');
        $this->giveTitles($colleague, 'Переводчик');
        UserChild::factory()->for($colleague)->create();
        $viewer = $this->colleague(['surname' => 'Шарипов']);

        $this->actingAs($viewer)
            ->get('/employees?sort=name')
            ->assertInertia(fn (Assert $page) => $page
                ->where('employees.data.0.surname', 'Азимова')
                // The column names the department behind the position, and the
                // abbreviation keeps the full name within reach.
                ->where('employees.data.0.positions', fn ($positions) => collect($positions)->map(fn ($position) => [
                    $position['name'],
                    $position['unit']['name'],
                    $position['unit']['full_name'],
                ])->all() === [['Переводчик', 'НО', 'Научный отдел']])
                ->where('employees.data.0.private', null)
                ->has('employees.data.0', fn (Assert $row) => $row
                    ->hasAll(['id', 'name', 'surname', 'patronymic', 'avatar', 'avatar_original', 'sex', 'email', 'roles', 'positions', 'departments', 'languages', 'status', 'status_changed_at', 'status_note', 'private'])
                    ->missing('details')
                    ->missing('children')
                )
            );
    }

    public function test_employee_sees_their_own_private_details()
    {
        $user = User::factory()->has(UserDetail::factory(), 'details')->create();
        UserChild::factory(2)->for($user)->create();

        $this->actingAs($this->mayLookAround($user))
            ->get('/employees')
            ->assertInertia(fn (Assert $page) => $page
                ->where('employees.data.0.private.phone', $user->details->phone)
                ->where('employees.data.0.private.sos_phone', $user->details->sos_phone)
                ->where('employees.data.0.private.hired_at', $user->details->hired_at->toDateString())
                ->has('employees.data.0.private.children', 2)
                ->missing('employees.data.0.private.passport_number')
            );
    }

    public function test_admin_sees_everyones_private_details()
    {
        $admin = $this->colleague(['surname' => 'Яхёев']);
        $admin->assignRole('sysadmin');
        $employee = User::factory()->has(UserDetail::factory(), 'details')->create(['surname' => 'Азимов']);

        $this->actingAs($admin)
            ->get('/employees')
            ->assertInertia(fn (Assert $page) => $page
                ->where('employees.data.0.surname', 'Азимов')
                ->where('employees.data.0.private.nationality', $employee->details->nationality)
                ->where('employees.data.0.private.citizenship', ['Таджикистан'])
            );
    }

    public function test_search_matches_surname_name_patronymic_and_email()
    {
        User::factory()->create(['surname' => 'Шарипов', 'name' => 'Алишер', 'email' => 'a.sharipov@evolet.test']);
        User::factory()->create(['surname' => 'Назарова', 'name' => 'Дилноза', 'email' => 'd.nazarova@evolet.test']);
        $this->actingAs($this->mayLookAround(User::first()));

        $this->get('/employees?search=Шарип')
            ->assertInertia(fn (Assert $page) => $page
                ->has('employees.data', 1)
                ->where('employees.data.0.surname', 'Шарипов')
                ->where('filters.search', 'Шарип')
            );

        $this->get('/employees?search=d.nazarova')
            ->assertInertia(fn (Assert $page) => $page->where('employees.data.0.surname', 'Назарова'));
    }

    public function test_toolbar_search_matches_every_public_column()
    {
        $design = Department::create(['name' => 'Отдел Дизайна', 'abbreviation' => 'ОД']);
        $target = $this->giveTitles(User::factory()->create(['surname' => 'Назарова', 'name' => 'Дилноза', 'sex' => 'female']), 'Графический дизайнер');
        $target->assignRole('kpg');
        $target->departments()->attach($design);
        $this->giveTitles(User::factory()->create(['surname' => 'Шарипов', 'name' => 'Алишер', 'sex' => 'male']), 'Переводчик');
        $this->actingAs($this->colleague(['surname' => 'Бобоев', 'sex' => 'male']));

        $finds = fn (string $q) => $this->get('/employees?q='.urlencode($q))
            ->assertInertia(fn (Assert $page) => $page->has('employees.data', 1)->where('employees.data.0.id', $target->id));

        $finds('Графический');       // position
        $finds('Дизайна');           // department (SQLite in tests only folds ASCII case)
        $finds('ОД');                // the short name the department column prints
        $finds('КПГ');               // role
        $finds('Дилноза Назарова');  // several words across fields
        $finds('женский');           // sex

        $this->get('/employees?q=Назарова')->assertInertia(fn (Assert $page) => $page->where('filters.q', 'Назарова'));
    }

    public function test_toolbar_search_ignores_private_fields_without_access()
    {
        User::factory()->has(UserDetail::factory(['home_address' => 'г. Душанбе, ул. Уникальная 7']), 'details')->create();
        $this->actingAs($this->colleague());

        $this->get('/employees?q=Уникальная')->assertInertia(fn (Assert $page) => $page->has('employees.data', 0));
    }

    public function test_admin_search_also_matches_private_fields()
    {
        $admin = $this->colleague();
        $admin->assignRole('sysadmin');
        $target = User::factory()->has(UserDetail::factory([
            'home_address' => 'г. Душанбе, ул. Уникальная 7',
            'phone' => '+992905554433',
            'birth_date' => '1977-03-09',
        ]), 'details')->create();
        UserChild::factory()->for($target)->create(['full_name' => 'Уникалов Самир']);
        $this->actingAs($admin);

        foreach (['Уникальная', '555 44 33', '09.03.1977', 'Самир'] as $q) {
            $this->get('/employees?q='.urlencode($q))
                ->assertInertia(fn (Assert $page) => $page->has('employees.data', 1)->where('employees.data.0.id', $target->id));
        }
    }

    public function test_a_person_is_found_by_any_of_their_citizenships()
    {
        $admin = $this->colleague();
        $admin->assignRole('sysadmin');
        $dual = User::factory()->has(UserDetail::factory(), 'details')->create();
        $dual->citizenships()->sync(Citizenship::idsFor(['Таджикистан', 'Россия']));
        $other = User::factory()->has(UserDetail::factory(), 'details')->create();
        $other->citizenships()->sync(Citizenship::idsFor(['Узбекистан']));
        $this->actingAs($admin);

        // The directory, by name.
        $this->get('/employees')->assertInertia(fn (Assert $page) => $page
            ->where('options.citizenships', ['Россия', 'Таджикистан', 'Узбекистан'])
        );

        $this->get('/employees?citizenship[]=Россия')
            ->assertInertia(fn (Assert $page) => $page->has('employees.data', 1)->where('employees.data.0.id', $dual->id));
        // Several countries ask for all of them at once.
        $this->get('/employees?citizenship[]=Россия&citizenship[]=Таджикистан')
            ->assertInertia(fn (Assert $page) => $page->has('employees.data', 1)->where('employees.data.0.id', $dual->id));
        $this->get('/employees?citizenship[]=Россия&citizenship[]=Узбекистан')
            ->assertInertia(fn (Assert $page) => $page->has('employees.data', 0));

        $this->get('/employees?q='.urlencode('Росс'))
            ->assertInertia(fn (Assert $page) => $page->has('employees.data', 1)->where('employees.data.0.id', $dual->id));
    }

    public function test_position_filter_accepts_several_titles()
    {
        User::factory(3)->create()->each(fn (User $u) => $this->giveTitles($u, 'Стажер'));
        User::factory(2)->create()->each(fn (User $u) => $this->giveTitles($u, 'Аналитик'));
        $this->fileUnder('Стажер', 'Департамент управления человеческими ресурсами', 'ДУЧР');
        $this->actingAs($this->mayLookAround(User::first()));
        [$intern, $analyst] = [$this->titleId('Стажер'), $this->titleId('Аналитик')];

        $this->get('/employees?position[]='.$intern)
            ->assertInertia(fn (Assert $page) => $page
                ->has('employees.data', 3)
                ->where('employees.data.0.positions', fn ($positions) => collect($positions)->map(fn ($position) => $position['unit'] === null
                    ? $position['name']
                    : $position['name'].' ('.$position['unit']['name'].')')->all() === ['Стажер (ДУЧР)'])
                ->where('filters.position', [$intern])
            );

        $this->get('/employees?position[]='.$intern.'&position[]='.$analyst)
            ->assertInertia(fn (Assert $page) => $page->has('employees.data', 5));
    }

    public function test_employee_with_several_positions_shows_all_and_matches_any()
    {
        $viewer = $this->colleague(['surname' => 'Бобоев']);
        $both = User::factory()->create(['surname' => 'Азимов']);
        $this->fileUnder('Переводчик', 'Научный отдел', 'НО');
        $this->fileUnder('Копирайтер', 'Отдел цифрового маркетинга', 'ОЦМ');
        $this->giveTitles($both, 'Переводчик', 'Копирайтер');
        $this->actingAs($viewer);

        $this->get('/employees')
            ->assertInertia(fn (Assert $page) => $page->where(
                'employees.data.0.positions',
                fn ($positions) => collect($positions)->map(fn ($position) => $position['unit'] === null
                    ? $position['name']
                    : $position['name'].' ('.$position['unit']['name'].')')->all() === ['Копирайтер (ОЦМ)', 'Переводчик (НО)'],
            ));

        foreach (['Переводчик', 'Копирайтер'] as $title) {
            $this->get('/employees?position[]='.$this->titleId($title))
                ->assertInertia(fn (Assert $page) => $page->has('employees.data', 1)->where('employees.data.0.id', $both->id));
        }
    }

    public function test_rows_list_departments_with_their_path()
    {
        $marketing = Department::create(['name' => 'Департамент маркетинга']);
        $design = Department::create(['name' => 'Отдел Дизайна', 'parent_id' => $marketing->id]);
        $user = $this->colleague();
        $user->departments()->attach([$design->id, $marketing->id]);

        $this->actingAs($user)
            ->get('/employees')
            ->assertInertia(fn (Assert $page) => $page
                ->where('employees.data.0.departments.0.name', 'Департамент маркетинга')
                ->where('employees.data.0.departments.1.path', 'Департамент маркетинга › Отдел Дизайна')
                // Each column asks about its own level of the tree.
                ->where('options.departments', [
                    ['id' => $marketing->id, 'name' => 'Департамент маркетинга', 'full_name' => 'Департамент маркетинга', 'depth' => 0],
                ])
                ->where('options.units', [
                    ['id' => $design->id, 'name' => 'Отдел Дизайна', 'full_name' => 'Отдел Дизайна', 'depth' => 0],
                ])
            );
    }

    public function test_rows_name_a_department_by_its_short_name()
    {
        $marketing = Department::create(['name' => 'Департамент маркетинга', 'abbreviation' => 'ДМ']);
        $design = Department::create(['name' => 'Отдел Дизайна', 'abbreviation' => 'ОД', 'parent_id' => $marketing->id]);
        $user = $this->colleague();
        $user->departments()->attach($design);

        $this->actingAs($user)
            ->get('/employees')
            ->assertInertia(fn (Assert $page) => $page
                // The column has no room for a department's full name, so the
                // row and the path above it are written short.
                ->where('employees.data.0.departments.0.name', 'ОД')
                ->where('employees.data.0.departments.0.path', 'ДМ › ОД')
                // A list one picks from is not a link one can follow, so each
                // option carries the full name the filter prints beside it.
                ->where('options.departments', [
                    ['id' => $marketing->id, 'name' => 'ДМ', 'full_name' => 'Департамент маркетинга', 'depth' => 0],
                ])
                ->where('options.units', [
                    ['id' => $design->id, 'name' => 'ОД', 'full_name' => 'Отдел Дизайна', 'depth' => 0],
                ])
            );
    }

    public function test_the_department_column_sorts_by_what_it_shows()
    {
        // Short names in the other order than the full ones: sorted by the name
        // the column prints, «АБ» comes before «Архив».
        $archive = Department::create(['name' => 'Архив']);
        $accounting = Department::create(['name' => 'Бухгалтерия', 'abbreviation' => 'АБ']);
        $viewer = $this->colleague();
        $inAccounting = User::factory()->create();
        $inAccounting->departments()->attach($accounting);
        $inArchive = User::factory()->create();
        $inArchive->departments()->attach($archive);
        $this->actingAs($viewer);

        // The viewer has no department and sorts first.
        $this->get('/employees?sort=department')
            ->assertInertia(fn (Assert $page) => $page
                ->where('employees.data.1.id', $inAccounting->id)
                ->where('employees.data.2.id', $inArchive->id)
            );
    }

    public function test_department_filter_includes_sub_departments()
    {
        $marketing = Department::create(['name' => 'Департамент маркетинга']);
        $design = Department::create(['name' => 'Отдел Дизайна', 'parent_id' => $marketing->id]);
        $finance = Department::create(['name' => 'Департамент финансов']);

        $head = User::factory()->create();
        $head->departments()->attach($marketing);
        $designer = User::factory()->create();
        $designer->departments()->attach($design);
        User::factory()->create()->departments()->attach($finance);
        $this->actingAs($this->colleague());

        $this->get('/employees?department[]='.$marketing->id)
            ->assertInertia(fn (Assert $page) => $page->has('employees.data', 2)->where('filters.department', [$marketing->id]));

        $this->get('/employees?department[]='.$design->id)
            ->assertInertia(fn (Assert $page) => $page->has('employees.data', 1)->where('employees.data.0.id', $designer->id));

        $this->get('/employees?department[]=999999')->assertSessionHasErrors('department.0');
    }

    public function test_the_unit_filter_narrows_within_the_department()
    {
        $marketing = Department::create(['name' => 'Департамент маркетинга']);
        $design = Department::create(['name' => 'Отдел Дизайна', 'parent_id' => $marketing->id]);
        $copy = Department::create(['name' => 'Отдел Текста', 'parent_id' => $marketing->id]);

        $designer = User::factory()->create();
        $designer->departments()->attach($design);
        User::factory()->create()->departments()->attach($copy);
        $this->actingAs($this->colleague());

        // The unit is asked for on its own, and the two questions narrow together.
        $this->get('/employees?unit[]='.$design->id)
            ->assertInertia(fn (Assert $page) => $page
                ->has('employees.data', 1)
                ->where('employees.data.0.id', $designer->id)
                ->where('filters.unit', [$design->id])
            );

        $this->get('/employees?department[]='.$marketing->id.'&unit[]='.$design->id)
            ->assertInertia(fn (Assert $page) => $page->has('employees.data', 1)->where('employees.data.0.id', $designer->id));

        $this->get('/employees?unit[]=999999')->assertSessionHasErrors('unit.0');
    }

    public function test_directory_sorts_by_department()
    {
        $a = Department::create(['name' => 'Архив']);
        $b = Department::create(['name' => 'Бухгалтерия']);
        $viewer = $this->colleague();
        $inB = User::factory()->create();
        $inB->departments()->attach($b);
        $inA = User::factory()->create();
        $inA->departments()->attach($a);
        $this->actingAs($viewer);

        // The viewer has no department and sorts first.
        $this->get('/employees?sort=department')
            ->assertInertia(fn (Assert $page) => $page
                ->where('employees.data.1.id', $inA->id)
                ->where('employees.data.2.id', $inB->id)
            );
    }

    public function test_roles_are_shown_as_a_separate_position_column()
    {
        $user = $this->giveTitles(User::factory()->create(), 'Переводчик');
        $user->assignRole(['translator', 'analyst']);
        User::factory()->create()->assignRole('intern');
        $this->actingAs($user);

        $this->get('/employees')
            ->assertInertia(fn (Assert $page) => $page
                // Every seeded position but the system administrator, which is
                // offered in no picker: there is only ever one of them.
                ->has('options.roles', 24)
                ->where('options.roles', fn ($roles) => ! collect($roles)->contains('name', 'sysadmin'))
                ->where('employees.data', fn ($rows) => collect($rows)->firstWhere('id', $user->id)['roles'] === ['Аналитик', 'Переводчик'])
            );

        $this->get('/employees?role[]=analyst')
            ->assertInertia(fn (Assert $page) => $page
                ->has('employees.data', 1)
                ->where('employees.data.0.id', $user->id)
                ->where('filters.role', ['analyst'])
            );

        // Аналитик < Стажер
        $this->get('/employees?sort=role')
            ->assertInertia(fn (Assert $page) => $page->where('employees.data.0.id', $user->id));

        $this->get('/employees?role[]=wizard')->assertSessionHasErrors('role.0');
    }

    public function test_directory_sorts_by_public_columns()
    {
        $viewer = $this->colleague(['surname' => 'Бобоев']);
        $this->fileUnder('Переводчик', 'Научный отдел', 'НО');
        $this->fileUnder('Аналитик', 'Отдел аналитики', 'ОА');
        $this->giveTitles(User::factory()->create(['surname' => 'Азимов']), 'Переводчик');
        $this->giveTitles(User::factory()->create(['surname' => 'Юсупов']), 'Аналитик');
        $this->actingAs($viewer);

        $this->get('/employees?sort=name')
            ->assertInertia(fn (Assert $page) => $page
                ->where('sort.key', 'name')
                ->where('employees.data.0.surname', 'Азимов')
                ->where('sortable', ['created', 'name', 'role', 'department', 'unit', 'position', 'sex'])
            );

        $this->get('/employees?sort=name&direction=desc')
            ->assertInertia(fn (Assert $page) => $page->where('employees.data.0.surname', 'Юсупов'));

        // Аналитик < Переводчик — sorted by the plain name, shown with the
        // department; the viewer without a position sorts first.
        $this->get('/employees?sort=position')
            ->assertInertia(fn (Assert $page) => $page
                ->where('employees.data.1.positions', fn ($positions) => collect($positions)->map(fn ($position) => $position['unit'] === null
                    ? $position['name']
                    : $position['name'].' ('.$position['unit']['name'].')')->all() === ['Аналитик (ОА)'])
                ->where('employees.data.2.positions', fn ($positions) => collect($positions)->map(fn ($position) => $position['unit'] === null
                    ? $position['name']
                    : $position['name'].' ('.$position['unit']['name'].')')->all() === ['Переводчик (НО)'])
            );
    }

    public function test_directory_opens_with_the_newest_first()
    {
        $viewer = $this->colleague(['surname' => 'Бобоев']);
        $this->travel(-2)->days();
        User::factory()->create(['surname' => 'Азимов']);
        $this->travelBack();
        User::factory()->create(['surname' => 'Юсупов']);
        $this->actingAs($viewer);

        $this->get('/employees')
            ->assertInertia(fn (Assert $page) => $page
                ->where('sort', ['key' => 'created', 'direction' => 'desc'])
                ->where('employees.data.0.surname', 'Юсупов')
                ->where('employees.data.2.surname', 'Азимов')
            );

        $this->get('/employees?direction=asc')
            ->assertInertia(fn (Assert $page) => $page->where('employees.data.0.surname', 'Азимов'));
    }

    public function test_employees_cannot_sort_by_private_columns()
    {
        $this->actingAs($this->colleague());

        $this->get('/employees?sort=birth_date')->assertSessionHasErrors('sort');
        $this->get('/employees?sort=hired_at')->assertSessionHasErrors('sort');
    }

    public function test_admin_can_sort_by_private_columns()
    {
        $admin = User::factory()->has(UserDetail::factory(['birth_date' => '1990-01-01']), 'details')->create();
        $admin->assignRole('sysadmin');
        $oldest = User::factory()->has(UserDetail::factory(['birth_date' => '1970-05-05']), 'details')->create();
        $youngest = User::factory()->has(UserDetail::factory(['birth_date' => '2001-02-02']), 'details')->create();
        UserChild::factory(3)->for($youngest)->create();

        $this->actingAs($admin);

        $this->get('/employees?sort=birth_date')
            ->assertInertia(fn (Assert $page) => $page
                ->where('employees.data.0.id', $oldest->id)
                ->where('employees.data.2.id', $youngest->id)
                ->has('sortable', 15)
            );

        $this->get('/employees?sort=children&direction=desc')
            ->assertInertia(fn (Assert $page) => $page->where('employees.data.0.id', $youngest->id));
    }

    public function test_the_citizenship_column_sorts_by_the_country_it_prints()
    {
        // Citizenships left `user_details` for a directory of their own, and
        // the column kept its header: pressing it has to put the list in order
        // rather than ask the database for a column that is not there.
        $admin = User::factory()->create();
        $admin->assignRole('sysadmin');
        $russia = User::factory()->create();
        $russia->citizenships()->sync(Citizenship::idsFor(['Россия']));
        $afghanistan = User::factory()->create();
        $afghanistan->citizenships()->sync(Citizenship::idsFor(['Афганистан']));

        $this->actingAs($admin);

        // The admin holds no citizenship and sorts first, before every country there is.
        $this->get('/employees?sort=citizenship')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('employees.data.1.id', $afghanistan->id)
                ->where('employees.data.2.id', $russia->id)
            );

        $this->get('/employees?sort=citizenship&direction=desc')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('employees.data.0.id', $russia->id));
    }

    public function test_the_unit_column_sorts_by_the_unit_it_prints()
    {
        $analytics = Department::create(['name' => 'Департамент аналитики', 'abbreviation' => 'ДАС']);
        $marketing = Department::create(['name' => 'Департамент маркетинга', 'abbreviation' => 'ДМ']);
        $design = Department::create(['name' => 'Отдел Дизайна', 'abbreviation' => 'ОД', 'parent_id' => $marketing->id]);
        $analysis = Department::create(['name' => 'Отдел аналитики', 'abbreviation' => 'ОА', 'parent_id' => $analytics->id]);

        $inDesign = User::factory()->create();
        $inDesign->departments()->attach($design);
        $inAnalysis = User::factory()->create();
        $inAnalysis->departments()->attach($analysis);
        // Attached to a department with no unit between: this column is empty
        // for them, and nothing sorts before every name there is.
        $inMarketing = User::factory()->create();
        $inMarketing->departments()->attach($marketing);

        $this->actingAs($this->colleague());

        // «ОА» before «ОД», and the two with no unit — the viewer and the one
        // attached to the department itself — ahead of both.
        $this->get('/employees?sort=unit')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('employees.data.2.id', $inAnalysis->id)
                ->where('employees.data.3.id', $inDesign->id)
            );

        $this->get('/employees?sort=unit&direction=desc')
            ->assertInertia(fn (Assert $page) => $page->where('employees.data.0.id', $inDesign->id));
    }

    public function test_per_page_can_be_chosen_from_the_allowed_options()
    {
        User::factory(30)->create();
        $this->actingAs($this->mayLookAround(User::first()));

        $this->get('/employees?per_page=25')
            ->assertInertia(fn (Assert $page) => $page
                ->where('perPage', 25)
                ->has('employees.data', 25)
                ->where('perPageOptions', [10, 25, 50, 100])
            );

        $this->get('/employees?per_page=7')->assertSessionHasErrors('per_page');
    }

    public function test_sex_filter()
    {
        User::factory(2)->create(['sex' => 'female']);
        User::factory(3)->create(['sex' => 'male']);
        $this->actingAs($this->mayLookAround(User::first()));

        $this->get('/employees?sex=female')
            ->assertInertia(fn (Assert $page) => $page->has('employees.data', 2)->where('filters.sex', 'female'));
    }

    public function test_employees_cannot_filter_by_private_fields()
    {
        $this->actingAs($this->colleague());

        $this->get('/employees?birth_from=1990-01-01')->assertSessionHasErrors('birth_from');
        $this->get('/employees?nationality[]=таджик')->assertSessionHasErrors('nationality');
        $this->get('/employees?phone=90')->assertSessionHasErrors('phone');
        $this->get('/employees?children[]=0')->assertSessionHasErrors('children');

        $this->get('/employees')->assertInertia(fn (Assert $page) => $page
            ->where('visibleFields', fn ($fields) => ! collect($fields)->contains('home_address'))
            ->where('options.nationalities', [])
        );
    }

    public function test_admin_can_filter_by_private_fields()
    {
        $admin = User::factory()->has(UserDetail::factory(['birth_date' => '1960-01-01', 'nationality' => 'узбек', 'hired_at' => '2015-05-01', 'phone' => '+992500000000', 'sos_phone' => '+992500000001']), 'details')->create();
        $admin->assignRole('sysadmin');
        $young = User::factory()->has(UserDetail::factory([
            'birth_date' => '2000-06-15',
            'nationality' => 'таджичка',
            'phone' => '+992901112233',
            'marital_status' => 'married',
            'hired_at' => '2024-03-01',
        ]), 'details')->create();
        UserChild::factory(2)->for($young)->create();

        $this->actingAs($admin);

        $only = fn (string $query) => $this->get("/employees?{$query}")
            ->assertInertia(fn (Assert $page) => $page->has('employees.data', 1)->where('employees.data.0.id', $young->id));

        $only('birth_from=1990-01-01');
        $only('nationality[]=таджичка');
        $only('phone=111 22 33');
        $only('children[]=2');
        $only('hired_from=2024-01-01&hired_to=2024-12-31');

        $this->get('/employees?children[]=0')
            ->assertInertia(fn (Assert $page) => $page->has('employees.data', 1)->where('employees.data.0.id', $admin->id));

        $this->get('/employees')->assertInertia(fn (Assert $page) => $page
            ->where('visibleFields', fn ($fields) => collect($fields)->contains('home_address'))
            ->where('options.nationalities', ['таджичка', 'узбек'])
        );
    }

    public function test_unknown_position_is_rejected()
    {
        $this->actingAs($this->colleague());

        $this->get('/employees?position[]=999999')->assertSessionHasErrors('position.0');
    }
}
