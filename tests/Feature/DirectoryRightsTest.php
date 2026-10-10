<?php

namespace Tests\Feature;

use App\Models\Language;
use App\Models\Position;
use App\Models\User;
use App\Support\Directories;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Which reference lists a person may open, and which they may keep.
 *
 * "Справочники" is several lists sharing a page, and they are kept by different
 * people: whoever answers for the fleet adds a category of monitors, and only a
 * system administrator touches the positions. So each list is a right of its
 * own, and changing one takes being able to read it — a list one cannot open is
 * not a list one renames entries in.
 *
 * The job titles are a right of the section too, and read with every other one
 * here, although their page is no longer a tab of it: they are kept at
 * /positions now, and moving a page moved nothing about what it takes to open.
 */
class DirectoryRightsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Each list one adds entries to, with a page of its own and something one may
     * add. "Доступы" is a list of the section as well, but it is a table of what
     * already exists rather than something one adds rows to, so it is checked on
     * its own.
     */
    private const LISTS = [
        'roles' => ['/directories/roles', ['title' => 'Кладовщик']],
        'departments' => ['/directories/departments', ['name' => 'Отдел дизайна']],
        'languages' => ['/directories/languages', ['name' => 'Немецкий']],
        'equipment' => ['/directories/equipment', ['name' => 'Проекторы']],
    ];

    /**
     * The pages of the section, in the order the tabs show them. The job titles
     * are not among them: they have a page of its own, and PositionPagesTest
     * reads the rights there.
     */
    private const READABLE = [
        'roles' => '/directories/roles',
        'departments' => '/directories/departments',
        'languages' => '/directories/languages',
        'citizenships' => '/directories/citizenships',
        'equipment' => '/directories/equipment',
        'access' => '/directories/access',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    }

    private function person(string ...$rights): User
    {
        $user = User::factory()->create();

        return $rights === [] ? $user : $user->givePermissionTo($rights);
    }

    public function test_without_a_single_list_the_section_is_shut()
    {
        $nobody = $this->person();

        $this->actingAs($nobody)->get('/directories')->assertForbidden();

        foreach (self::READABLE as $url) {
            $this->actingAs($nobody)->get($url)->assertForbidden();
        }

        foreach (self::LISTS as [$url, $payload]) {
            $this->actingAs($nobody)->post($url, $payload)->assertForbidden();
        }
    }

    public function test_a_list_opens_for_whoever_may_read_that_list_and_nobody_else()
    {
        foreach (self::READABLE as $list => $url) {
            $reader = $this->person(Directories::viewPermission($list));

            foreach (self::READABLE as $other => $otherUrl) {
                $response = $this->actingAs($reader)->get($otherUrl);

                $other === $list ? $response->assertOk() : $response->assertForbidden();
            }
        }
    }

    public function test_changing_a_list_is_a_right_of_its_own()
    {
        $reader = $this->person(Directories::viewPermission('languages'));

        $this->actingAs($reader)->get('/directories/languages')->assertOk();
        $this->actingAs($reader)->post('/directories/languages', ['name' => 'Немецкий'])->assertForbidden();
        $this->assertSame(0, Language::where('name', 'Немецкий')->count());

        $keeper = $this->person(Directories::viewPermission('languages'), Directories::editPermission('languages'));

        $this->actingAs($keeper)->post('/directories/languages', ['name' => 'Немецкий'])->assertRedirect();
        $this->assertSame(1, Language::where('name', 'Немецкий')->count());

        // And only that list: keeping the languages is not keeping the job titles,
        // wherever those are now kept.
        $this->actingAs($keeper)->post('/positions', ['name' => 'Переводчик'])->assertForbidden();
        $this->assertSame(0, Position::count());
    }

    public function test_keeping_a_list_one_cannot_even_open_comes_to_nothing()
    {
        // The right to change the job titles, without the right to read them: the
        // page is refused, and so is the change.
        $editor = $this->person(Directories::editPermission('positions'));

        $this->assertFalse(Directories::canEdit($editor, 'positions'));
        $this->actingAs($editor)->get('/positions')->assertForbidden();
        $this->actingAs($editor)->post('/positions', ['name' => 'Переводчик'])->assertForbidden();
        $this->assertSame([], Directories::editableBy($editor->fresh()));
    }

    public function test_renaming_and_deleting_go_with_adding()
    {
        $keeper = $this->person(Directories::viewPermission('languages'), Directories::editPermission('languages'));
        $language = Language::create(['name' => 'Немецкий']);

        $this->actingAs($keeper)->put("/directories/languages/{$language->id}", ['name' => 'Французский'])->assertRedirect();
        $this->assertSame('Французский', $language->fresh()->name);
        $this->actingAs($keeper)->delete("/directories/languages/{$language->id}")->assertRedirect();
        $this->assertNull(Language::find($language->id));
    }

    public function test_the_section_opens_on_the_first_list_its_reader_may_see()
    {
        // Not on the roles, which is where the tabs start but not everybody who
        // keeps a directory is allowed.
        $this->actingAs($this->person(Directories::viewPermission('languages')))
            ->get('/directories')
            ->assertRedirect('/directories/languages');

        $this->actingAs($this->person(Directories::viewPermission('departments'), Directories::viewPermission('equipment')))
            ->get('/directories')
            ->assertRedirect('/directories/departments');

        $this->actingAs(User::factory()->create()->assignRole('sysadmin'))
            ->get('/directories')
            ->assertRedirect('/directories/roles');
    }

    public function test_each_page_says_whether_its_list_is_this_persons_to_keep()
    {
        foreach (self::LISTS as $list => [$url]) {  // "Доступы" has no canEdit of its own.
            $reader = $this->person(Directories::viewPermission($list));
            $keeper = $this->person(Directories::viewPermission($list), Directories::editPermission($list));

            $this->assertFalse($this->actingAs($reader)->get($url)->viewData('page')['props']['canEdit']);
            $this->assertTrue($this->actingAs($keeper)->get($url)->viewData('page')['props']['canEdit']);
        }
    }

    public function test_the_access_table_is_read_and_kept_by_rights_of_its_own()
    {
        $role = Role::findByName('analyst');

        // Reading the table is not deciding what is in it, here as everywhere.
        $reader = $this->person(Directories::viewPermission('access'));
        $this->actingAs($reader)->get('/directories/access')->assertOk();
        $this->actingAs($reader)
            ->put("/directories/access/{$role->id}", ['permissions' => ['employees.view']])
            ->assertForbidden();

        $keeper = $this->person(Directories::viewPermission('access'), Directories::editPermission('access'));
        $this->actingAs($keeper)
            ->put("/directories/access/{$role->id}", ['permissions' => ['employees.view']])
            ->assertRedirect();
        $this->assertSame(['employees.view'], $role->fresh()->permissions->pluck('name')->all());

        // A personal exception on somebody's card is the same duty, so it takes
        // the same right rather than one of its own.
        $colleague = User::factory()->create();
        $this->actingAs($reader)
            ->put("/employees/{$colleague->id}/access", ['permission' => 'employees.view', 'allowed' => true])
            ->assertForbidden();
        $this->actingAs($keeper)
            ->put("/employees/{$colleague->id}/access", ['permission' => 'employees.view', 'allowed' => true])
            ->assertRedirect();
    }

    public function test_the_one_account_keeps_every_list_without_a_single_right()
    {
        $sysadmin = User::factory()->create()->assignRole('sysadmin');

        $this->assertSame(array_keys(self::READABLE), Directories::visibleTo($sysadmin));
        $this->assertSame(array_keys(self::READABLE), Directories::editableBy($sysadmin));

        foreach (self::LISTS as [$url, $payload]) {
            $this->actingAs($sysadmin)->get($url)->assertOk();
            $this->actingAs($sysadmin)->post($url, $payload)->assertRedirect();
        }
    }

    public function test_the_access_page_offers_both_lists_of_rights()
    {
        $sysadmin = User::factory()->create()->assignRole('sysadmin');

        $page = $this->actingAs($sysadmin)->get('/directories/access')->assertOk()->viewData('page')['props'];

        // Every list of the catalogue, which is one more than the tabs: the job
        // titles are read and kept by rights of this section although their page
        // sits elsewhere, so the table still offers both of theirs.
        $this->assertCount(count(Directories::LISTS), $page['directoryLists']);
        $this->assertCount(count(Directories::LISTS), $page['directoryEdits']);
        $this->assertCount(count(self::READABLE) + 1, $page['directoryLists']);
        $this->assertContains(Directories::viewPermission('positions'), array_column($page['directoryLists'], 'key'));
        $this->assertContains(Directories::editPermission('positions'), array_column($page['directoryEdits'], 'key'));
        // Every right to change a list names the right to read it, so the dialog
        // can grey out what would never answer yes.
        foreach ($page['directoryEdits'] as $right) {
            $this->assertArrayHasKey('requires', $right);
            $this->assertContains($right['requires']['key'], array_column($page['directoryLists'], 'key'));
        }
    }
}
