<?php

namespace Tests\Feature;

use App\Models\EquipmentType;
use App\Models\Position;
use App\Models\User;
use App\Support\Directories;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
 * The job titles and the departments are rights of the section too, and read
 * with every other one here, although neither page is a tab of it: they are kept
 * at /positions and /departments now, and moving a page moved nothing
 * about what it takes to open.
 *
 * The languages, the citizenships and the types of employment are no lists at
 * all any more — they are free text on a card, suggested from what the cards
 * already say — so there is nothing here to read or keep, and the six rights
 * they took have left the catalogue with them.
 */
class DirectoryRightsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Each list one adds entries to, with a page of its own and something one may
     * add.
     */
    private const LISTS = [
        'document_types' => ['/directories/document_types', [
            'name' => 'Копия паспорта',
            'on_paper' => false,
            'is_required' => true,
        ]],
        'equipment' => ['/directories/equipment', ['name' => 'Проекторы']],
    ];

    /**
     * The pages of the section, in the order the tabs show them. Neither the job
     * titles nor the departments are among them: each has a page of its own, and
     * PositionPagesTest and DepartmentPagesTest read the rights there.
     */
    private const READABLE = [
        'document_types' => '/directories/document_types',
        'equipment' => '/directories/equipment',
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
        $reader = $this->person(Directories::viewPermission('equipment'));

        $this->actingAs($reader)->get('/directories/equipment')->assertOk();
        $this->actingAs($reader)->post('/directories/equipment', ['name' => 'Проекторы'])->assertForbidden();
        $this->assertSame(0, EquipmentType::where('name', 'Проекторы')->count());

        $keeper = $this->person(Directories::viewPermission('equipment'), Directories::editPermission('equipment'));

        $this->actingAs($keeper)->post('/directories/equipment', ['name' => 'Проекторы'])->assertRedirect();
        $this->assertSame(1, EquipmentType::where('name', 'Проекторы')->count());

        // And only that list: keeping the categories of hardware is not keeping
        // the job titles, wherever those are now kept.
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
        $keeper = $this->person(Directories::viewPermission('equipment'), Directories::editPermission('equipment'));
        $category = EquipmentType::create(['name' => 'Проекторы']);

        $this->actingAs($keeper)->put("/directories/equipment/{$category->id}", ['name' => 'Мониторы'])->assertRedirect();
        $this->assertSame('Мониторы', $category->fresh()->name);
        $this->actingAs($keeper)->delete("/directories/equipment/{$category->id}")->assertRedirect();
        $this->assertNull(EquipmentType::find($category->id));
    }

    public function test_the_section_opens_on_the_first_list_its_reader_may_see()
    {
        $this->actingAs($this->person(Directories::viewPermission('document_types')))
            ->get('/directories')
            ->assertRedirect('/directories/document_types');

        // The second tab, for somebody the first one is shut to: the section
        // lands where its reader may actually look, not on the tab that happens
        // to come first.
        $this->actingAs($this->person(Directories::viewPermission('equipment')))
            ->get('/directories')
            ->assertRedirect('/directories/equipment');

        // And whoever may read every one of them lands on the first tab of all.
        $this->actingAs(User::factory()->create()->assignRole('sysadmin'))
            ->get('/directories')
            ->assertRedirect('/directories/document_types');
    }

    public function test_each_page_says_whether_its_list_is_this_persons_to_keep()
    {
        foreach (self::LISTS as $list => [$url]) {
            $reader = $this->person(Directories::viewPermission($list));
            $keeper = $this->person(Directories::viewPermission($list), Directories::editPermission($list));

            $this->assertFalse($this->actingAs($reader)->get($url)->viewData('page')['props']['canEdit']);
            $this->assertTrue($this->actingAs($keeper)->get($url)->viewData('page')['props']['canEdit']);
        }
    }

    public function test_the_one_account_keeps_every_list_without_a_single_right()
    {
        $sysadmin = User::factory()->create()->assignRole('sysadmin');

        $this->assertSame(Directories::TABS, array_keys(self::READABLE));
        $this->assertSame(array_keys(self::READABLE), Directories::visibleTo($sysadmin));
        $this->assertSame(array_keys(self::READABLE), Directories::editableBy($sysadmin));

        foreach (self::LISTS as [$url, $payload]) {
            $this->actingAs($sysadmin)->get($url)->assertOk();
            $this->actingAs($sysadmin)->post($url, $payload)->assertRedirect();
        }
    }
}
