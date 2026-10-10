<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Position;
use App\Models\User;
use App\Models\UserDetail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

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
        $top = Department::create(['name' => 'Департамент']);
        $unit = Department::create(['name' => 'Отдел', 'parent_id' => $top->id]);
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
            // Its own branch for the chart: itself and what is below, not its parent.
            ->where('chart', fn ($chart) => collect($chart)->pluck('id')->all() === [$sub->id, $unit->id])
            ->where('chart', fn ($chart) => collect($chart)->firstWhere('id', $unit->id)['members'] === [['id' => $member->id, 'name' => 'Бобоев Фаррух', 'avatar' => null]])
            ->where('department.name', 'Отдел')
            ->where('department.parents', [['id' => $top->id, 'name' => 'Департамент', 'full_name' => 'Департамент']])
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
        $sub = Department::create(['name' => 'Группа вёрстки', 'parent_id' => $unit->id]);
        $named = fn ($chart, int $id) => collect($chart)->firstWhere('id', $id)['name'];

        $this->actingAs($this->colleague());

        $this->get('/departments')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('departments/index')
            ->where('departments', fn ($chart) => $named($chart, $top->id) === 'ДМ')
            ->where('departments', fn ($chart) => $named($chart, $unit->id) === 'ОД')
            ->where('departments', fn ($chart) => $named($chart, $sub->id) === 'Группа вёрстки')
        );

        // Its own page, the chain above it and the units under it: every box of
        // the structure is named the one way.
        $this->get("/departments/{$unit->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('departments/show')
            ->where('chart', fn ($chart) => $named($chart, $unit->id) === 'ОД')
            ->where('department.name', 'ОД')
            // Short on screen, spelled out for the hover and the screen reader.
            ->where('department.parents', [['id' => $top->id, 'name' => 'ДМ', 'full_name' => 'Департамент маркетинга']])
            ->where('department.full_name', 'Отдел Дизайна')
            ->where('department.children', [['id' => $sub->id, 'name' => 'Группа вёрстки', 'full_name' => 'Группа вёрстки', 'total_count' => 0, 'heads' => []]])
        );
    }
}
