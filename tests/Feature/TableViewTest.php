<?php

namespace Tests\Feature;

use App\Models\TableView;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * How a table opens for whoever has not arranged it themselves.
 *
 * Arranging a table is each reader's own business and stays in their own
 * browser. The one arrangement underneath — what a table shows before anybody
 * has touched it — is the company's, and the single system administrator is
 * the one who says what it is.
 */
class TableViewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function arrangement(): array
    {
        return [
            'view' => [
                'hidden' => ['created'],
                'pinned' => ['left' => ['name'], 'right' => []],
                'order' => ['name', 'status'],
                'widths' => ['name' => 240],
            ],
            // The sorting, the tab and the filters go with the columns.
            'query' => 'sort=name&tab=stock',
        ];
    }

    public function test_the_one_account_says_how_a_table_opens_for_everybody()
    {
        $sysadmin = User::factory()->create()->assignRole('sysadmin');

        $this->actingAs($sysadmin)->put('/table-views/equipment', $this->arrangement())->assertSessionHasNoErrors();

        $kept = TableView::query()->sole();
        $this->assertSame('equipment', $kept->key);
        $this->assertSame(['created'], $kept->view['view']['hidden']);
        $this->assertSame('sort=name&tab=stock', $kept->view['query']);
        $this->assertSame($sysadmin->id, $kept->updated_by);

        // And every page carries it, so a table opens in it without asking.
        $this->actingAs($sysadmin)->get('/inventory/equipment')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('tableViews.equipment.view.hidden', ['created'])
            ->where('tableViews.equipment.view.widths.name', 240)
            ->where('tableViews.equipment.query', 'sort=name&tab=stock')
        );

        // Setting it again replaces it rather than making a second one.
        $this->actingAs($sysadmin)->put('/table-views/equipment', ['view' => ['hidden' => [], 'pinned' => ['left' => [], 'right' => []]]])
            ->assertSessionHasNoErrors();
        $this->assertSame(1, TableView::query()->count());
        $this->assertSame([], TableView::query()->sole()->view['view']['hidden']);

        // And it can be taken back, which leaves the page's own arrangement.
        $this->actingAs($sysadmin)->delete('/table-views/equipment')->assertSessionHasNoErrors();
        $this->assertSame(0, TableView::query()->count());
    }

    public function test_nobody_else_touches_it_however_much_they_may_otherwise_do()
    {
        // Somebody who may read and keep the lists, and is still not the one.
        $manager = User::factory()->create()->givePermissionTo(['equipment.view.all', 'employees.view']);

        $this->actingAs($manager)->put('/table-views/equipment', $this->arrangement())->assertForbidden();
        $this->assertSame(0, TableView::query()->count());

        TableView::create(['key' => 'equipment', 'view' => ['view' => ['hidden' => [], 'pinned' => ['left' => [], 'right' => []]], 'query' => '']]);
        $this->actingAs($manager)->delete('/table-views/equipment')->assertForbidden();
        $this->assertSame(1, TableView::query()->count());

        // They are told how the table opens, all the same: it is how they read it.
        $this->actingAs($manager)->get('/inventory/equipment')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('tableViews.equipment.view.hidden', [])
            ->where('auth.sysadmin', false)
        );
    }

    public function test_an_arrangement_comes_back_exactly_as_it_was_sent()
    {
        $sysadmin = User::factory()->create()->assignRole('sysadmin');

        // A table nobody has dragged a column in: the two it says nothing about
        // travel as nothing at all, and come back as nothing at all, so the
        // menu reads the arrangement on screen as the one it just set.
        $sent = [
            'view' => ['hidden' => ['created'], 'pinned' => ['left' => [], 'right' => []], 'order' => null, 'widths' => null],
            'query' => 'sort=name',
            'state' => ['table' => 'list', 'label' => 'Список'],
        ];

        $this->actingAs($sysadmin)->put('/table-views/equipment', $sent)->assertSessionHasNoErrors();

        // The keys of what comes back are not the keys that went in: MySQL
        // keeps a JSON object with its keys sorted, and the store this test runs
        // on does not, so the page is written to read the answer rather than the
        // order it is written in — see `stable` in view.ts.
        $this->actingAs($sysadmin)->get('/inventory/equipment')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('tableViews.equipment.view', $sent['view'])
            ->where('tableViews.equipment.query', $sent['query'])
            ->where('tableViews.equipment.state', $sent['state'])
        );
    }

    public function test_an_arrangement_that_makes_no_sense_is_refused()
    {
        $sysadmin = User::factory()->create()->assignRole('sysadmin');
        $this->actingAs($sysadmin);

        // A width outside what a column may be dragged to.
        $this->put('/table-views/equipment', ['view' => [
            'hidden' => [],
            'pinned' => ['left' => [], 'right' => []],
            'widths' => ['name' => 4000],
        ]])->assertSessionHasErrors('view.widths.name');

        // And an arrangement that says nothing about its columns at all.
        $this->put('/table-views/equipment', ['view' => ['hidden' => []]])->assertSessionHasErrors('view.pinned');

        $this->assertSame(0, TableView::query()->count());
    }
}
