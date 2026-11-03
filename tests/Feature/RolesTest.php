<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Position;
use App\Models\User;
use App\Support\Access;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The four roles there are, and who may put which of them on a card.
 *
 * Roles stopped being a directory the company keeps with a table of ticks
 * behind it. They are «Системный администратор», «HR-специалист»,
 * «Руководитель» and «Специалист», they are named in App\Support\Access and
 * nowhere else, and what each may do is decided there rather than handed out on
 * a page: the first two pass every check through Gate::before while holding not
 * one right of their own, and the site is not open to the other two yet.
 *
 * A person holds exactly one of them, and the rest of the contract is about who
 * may hand which out. The system administrator is handed to nobody — there is
 * one and it is taken — personnel are appointed by the system administrator
 * alone, since personnel who could appoint personnel would be the whole access
 * system in one person's gift, and the two ordinary roles are not usually asked
 * for at all: a card that names no role takes the one its positions imply.
 *
 * And the role is tied to the job. «Руководитель» leads, so every job on such a
 * card is a leading one; «Специалист» does not, so none of theirs is; and
 * «HR-специалист» is personnel wherever they sit. Which way round the pair is
 * asked depends on who is asking — the system administrator picks the role and
 * the jobs narrow to it, while personnel pick the job and the role follows —
 * and a card holding «HR-специалист» answers to the system administrator in
 * both lines, its own holder included.
 */
class RolesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    /** The one account that passes every check without holding a single right. */
    private function sysadmin(): User
    {
        return User::factory()->create()->assignRole(Access::SOLE_ROLE);
    }

    /** Personnel, who pass every check in the same way. */
    private function personnel(): User
    {
        return User::factory()->create()->assignRole(Access::HR);
    }

    /**
     * A department with a leading job and an ordinary one in it, which is the
     * whole of what decides the role a card is given when it names none.
     *
     * @return array{Position, Position}
     */
    private function jobs(): array
    {
        $department = Department::create(['name' => 'Отдел Дизайна']);

        return [
            Position::create(['department_id' => $department->id, 'name' => 'Руководитель отдела дизайна', 'is_head' => true]),
            Position::create(['department_id' => $department->id, 'name' => 'Дизайнер']),
        ];
    }

    /**
     * One job as the window sends it. Nothing is picked out of it: a duty says
     * nothing about who leads a department, which is all these tests ask about.
     *
     * @return array{position_id: int, duty_ids: list<int>}
     */
    private function holding(Position $job): array
    {
        return ['position_id' => $job->id, 'duty_ids' => []];
    }

    /**
     * «Основное» of a card as its own window sends it: the one role, where the
     * person stands, and the three lines the form always carries. Every other
     * line of that form is «sometimes», so a window that shows none of them
     * sends none.
     *
     * The role is sent back as it stands by default, because leaving a card's
     * own role on it is not a change and nothing should be read into it. A card
     * holding none sends none: the line is left out rather than sent empty,
     * which is what the window does for a viewer who does not pick it.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function card(User $employee, array $overrides = []): array
    {
        $held = $employee->roles->first()?->name;

        return [
            'surname' => $employee->surname,
            'name' => $employee->name,
            'sex' => $employee->sex,
            ...$held === null ? [] : ['role' => $held],
            'placements' => [],
            ...$overrides,
        ];
    }

    /**
     * A whole new colleague, for the role they are given on the way in.
     *
     * The plainest of the four by default: the role is asked for outright, so
     * there is no such thing as a card opened without one, and every test that
     * is about something else still has to answer the question.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function newCard(array $overrides = []): array
    {
        return [
            'surname' => 'Азимов',
            'name' => 'Далер',
            'sex' => 'male',
            'email' => 'daler@evolet.tj',
            'role' => Access::SPECIALIST,
            'placements' => [],
            ...$overrides,
        ];
    }

    public function test_the_catalogue_is_the_four_roles_and_nothing_else()
    {
        // Written out rather than read back out of Access::ROLES, which would
        // only ask the code whether it agrees with itself. These are the names
        // the company reads on a card, and a seeder that quietly stopped making
        // one of them would leave somebody holding no role at all.
        $this->assertSame([
            'sysadmin' => 'Системный администратор',
            'hr' => 'HR-специалист',
            'head' => 'Руководитель',
            'specialist' => 'Специалист',
        ], Role::query()->pluck('title', 'name')->all());
    }

    public function test_personnel_pass_every_check_exactly_as_the_system_administrator_does()
    {
        // Two roles and one answer: every right in the catalogue — reading any
        // line of any card, moving any unit, keeping any list — passes for both
        // of them, and neither carries a single permission row to pass it with.
        foreach ([$this->sysadmin(), $this->personnel()] as $user) {
            $role = $user->roles->first()->name;

            $this->assertEmpty($user->getAllPermissions());

            foreach (Access::keys() as $key) {
                $this->assertTrue($user->can($key), "роль {$role} должна проходить {$key}");
            }
        }
    }

    public function test_personnel_are_offered_by_the_system_administrator_alone()
    {
        $sysadmin = $this->sysadmin();
        $personnel = $this->personnel();

        // The system administrator itself is in neither list: there is one of it
        // and no picker offers it.
        $this->assertSame([Access::HR, Access::HEAD, Access::SPECIALIST], Access::offeredTo($sysadmin));
        $this->assertSame([Access::HEAD, Access::SPECIALIST], Access::offeredTo($personnel));

        // The same answer as rows to show, in the order the catalogue names them
        // — widest first, which is not the order their titles sort in.
        $this->assertSame(['hr', 'head', 'specialist'], Access::offeredRoles($sysadmin)->pluck('name')->all());
        $this->assertSame(['head', 'specialist'], Access::offeredRoles($personnel)->pluck('name')->all());
    }

    public function test_the_system_administrator_is_handed_to_nobody()
    {
        $sysadmin = $this->sysadmin();
        $colleague = User::factory()->create();

        // Not even by the one who holds it, and on both of the doors a role
        // comes through: a card already on file, and a card being opened.
        $refusal = 'Роль системного администратора не выдаётся: она одна и уже занята.';

        $this->actingAs($sysadmin)
            ->put("/employees/{$colleague->id}/personal", $this->card($colleague, ['role' => Access::SOLE_ROLE]))
            ->assertSessionHasErrors(['role' => $refusal]);
        $this->assertFalse($colleague->refresh()->hasRole(Access::SOLE_ROLE));

        $this->actingAs($sysadmin)
            ->post('/employees', $this->newCard(['role' => Access::SOLE_ROLE]))
            ->assertSessionHasErrors(['role' => $refusal]);
        $this->assertNull(User::query()->where('email', 'daler@evolet.tj')->first());

        // And not by personnel either, who pass every check the one account does.
        $this->actingAs($this->personnel())
            ->put("/employees/{$colleague->id}/personal", $this->card($colleague, ['role' => Access::SOLE_ROLE]))
            ->assertSessionHasErrors(['role' => $refusal]);
        $this->assertSame([], $colleague->refresh()->getRoleNames()->all());
    }

    public function test_only_the_system_administrator_appoints_personnel()
    {
        $colleague = User::factory()->create();
        $refusal = 'Роль HR-специалиста выдаёт только системный администратор.';

        // Personnel pass every check, so one of them appointing another would
        // put the whole of the access system in their gift.
        $this->actingAs($this->personnel())
            ->put("/employees/{$colleague->id}/personal", $this->card($colleague, ['role' => Access::HR]))
            ->assertSessionHasErrors(['role' => $refusal]);
        $this->assertFalse($colleague->refresh()->hasRole(Access::HR));

        $this->actingAs($this->personnel())
            ->post('/employees', $this->newCard(['role' => Access::HR]))
            ->assertSessionHasErrors(['role' => $refusal]);

        // The one account it is in the gift of does it without ceremony, and the
        // card is holding that role and no other afterwards.
        $this->actingAs($this->sysadmin())
            ->put("/employees/{$colleague->id}/personal", $this->card($colleague, ['role' => Access::HR]))
            ->assertSessionHasNoErrors();
        $this->assertSame([Access::HR], $colleague->refresh()->getRoleNames()->all());
    }

    /**
     * A role is replaced rather than added to: the save leaves the one named.
     *
     * The four are an order rather than a set, and the table behind them could
     * hold several — so what the window sends is what the card holds afterwards,
     * with whatever it held before gone rather than kept beside it.
     */
    public function test_a_role_saved_from_a_card_is_the_only_one_the_card_then_holds()
    {
        [$leading] = $this->jobs();
        $sysadmin = $this->sysadmin();
        $colleague = User::factory()->create()->assignRole(Access::SPECIALIST);

        // The job travels with the role, because «Руководитель» is the department
        // somebody leads: there is no such card without a leading job on it.
        $this->actingAs($sysadmin)
            ->put("/employees/{$colleague->id}/personal", $this->card($colleague, [
                'role' => Access::HEAD,
                'placements' => [$this->holding($leading)],
            ]))
            ->assertSessionHasNoErrors();
        $this->assertSame([Access::HEAD], $colleague->refresh()->getRoleNames()->all());

        // And back again, which is the same question asked of the other direction.
        $this->actingAs($sysadmin)
            ->put("/employees/{$colleague->id}/personal", $this->card($colleague, ['role' => Access::SPECIALIST]))
            ->assertSessionHasNoErrors();
        $this->assertSame([Access::SPECIALIST], $colleague->refresh()->getRoleNames()->all());

        // The card hands the window one name rather than a list, and the name
        // the picker submits rather than the title the card prints.
        $this->assertSame(
            Access::SPECIALIST,
            $this->actingAs($sysadmin)->get("/employees/{$colleague->id}")->viewData('page')['props']['assigned']['role'],
        );

        // And nothing at all while a card has not been given a role.
        $this->assertNull(
            $this->actingAs($sysadmin)
                ->get('/employees/'.User::factory()->create()->id)
                ->viewData('page')['props']['assigned']['role'],
        );
    }

    /**
     * The role is one name, not a list of one.
     *
     * The picker used to send a list, and the window on the card is the one place
     * a stale page could still do so — so it is refused outright rather than
     * having its first entry quietly taken for the answer.
     */
    public function test_a_card_sending_a_list_of_roles_is_refused()
    {
        $colleague = User::factory()->create()->assignRole(Access::SPECIALIST);

        foreach ([[Access::HEAD], [Access::HEAD, Access::SPECIALIST]] as $sent) {
            $this->actingAs($this->sysadmin())
                ->put("/employees/{$colleague->id}/personal", $this->card($colleague, ['role' => $sent]))
                ->assertSessionHasErrors('role');
        }

        $this->assertSame([Access::SPECIALIST], $colleague->refresh()->getRoleNames()->all());
    }

    /**
     * The role and the jobs on a card have to be able to both be true.
     *
     * A head leads something, so one of the jobs on their card is a leading one;
     * a specialist leads nothing, so none of theirs is; and personnel are
     * personnel wherever they sit. Asked on both of the doors the pair comes
     * through, because the page narrows the list for the person's sake and the
     * server is what decides.
     */
    public function test_a_head_holds_a_leading_job_and_a_specialist_holds_none()
    {
        [$leading, $plain] = $this->jobs();
        $sysadmin = $this->sysadmin();

        // The role, the one job it is sent with, and the sentence it is refused
        // with — or null where the two can both be true. One job each, so a head
        // sent an ordinary one has no leading job at all.
        $pairs = [
            [Access::HEAD, $leading, null],
            [Access::HEAD, $plain, 'Руководитель занимает хотя бы одну руководящую должность.'],
            [Access::SPECIALIST, $plain, null],
            [Access::SPECIALIST, $leading, 'Специалист не занимает руководящих должностей.'],
            [Access::HR, $leading, null],
            [Access::HR, $plain, null],
        ];

        foreach ($pairs as [$role, $job, $refusal]) {
            $colleague = User::factory()->create();
            $email = "{$role}.{$job->id}@evolet.tj";

            $card = $this->actingAs($sysadmin)->put("/employees/{$colleague->id}/personal", $this->card($colleague, [
                'role' => $role,
                'placements' => [$this->holding($job)],
            ]));

            $opened = $this->actingAs($sysadmin)->post('/employees', $this->newCard([
                'email' => $email,
                'role' => $role,
                'placements' => [$this->holding($job)],
            ]));

            if ($refusal === null) {
                $card->assertSessionHasNoErrors();
                $opened->assertSessionHasNoErrors();

                $this->assertSame([$role], $colleague->refresh()->getRoleNames()->all());
                $this->assertSame([$role], User::query()->where('email', $email)->sole()->getRoleNames()->all());

                continue;
            }

            // The refusal lands on the jobs rather than on the role: the role is
            // what somebody was given and the jobs are what disagrees with it.
            $card->assertSessionHasErrors(['placements' => $refusal]);
            $opened->assertSessionHasErrors(['placements' => $refusal]);

            $this->assertSame([], $colleague->refresh()->getRoleNames()->all());
            $this->assertNull(User::query()->where('email', $email)->first());
        }
    }

    /**
     * Heading a department is work somebody does besides their own.
     *
     * So a head may hold an ordinary job alongside the leading one, and a card
     * that refused the pair would be telling a head of department they may not
     * also be a designer. What is asked of such a card is that one of its jobs
     * leads something — a head with nothing to head is a role with no department
     * behind it, and nothing would answer for the authority it carries.
     */
    public function test_a_head_may_hold_an_ordinary_job_beside_the_leading_one()
    {
        [$leading, $plain] = $this->jobs();
        $sysadmin = $this->sysadmin();
        $colleague = User::factory()->create();
        $both = [$this->holding($leading), $this->holding($plain)];

        $this->actingAs($sysadmin)
            ->put("/employees/{$colleague->id}/personal", $this->card($colleague, [
                'role' => Access::HEAD,
                'placements' => $both,
            ]))
            ->assertSessionHasNoErrors();

        $colleague->refresh();
        $this->assertSame([Access::HEAD], $colleague->getRoleNames()->all());
        $this->assertEqualsCanonicalizing([$leading->id, $plain->id], $colleague->positions->pluck('id')->all());

        $this->actingAs($sysadmin)
            ->post('/employees', $this->newCard(['role' => Access::HEAD, 'placements' => $both]))
            ->assertSessionHasNoErrors();

        $opened = User::query()->where('email', 'daler@evolet.tj')->sole();
        $this->assertSame([Access::HEAD], $opened->getRoleNames()->all());
        $this->assertEqualsCanonicalizing([$leading->id, $plain->id], $opened->positions->pluck('id')->all());

        // And no job at all is the one way the pair cannot stand, on either door.
        $refusal = 'Руководитель занимает хотя бы одну руководящую должность.';
        $nobody = User::factory()->create();

        $this->actingAs($sysadmin)
            ->put("/employees/{$nobody->id}/personal", $this->card($nobody, ['role' => Access::HEAD]))
            ->assertSessionHasErrors(['placements' => $refusal]);
        $this->assertSame([], $nobody->refresh()->getRoleNames()->all());

        $this->actingAs($sysadmin)
            ->post('/employees', $this->newCard(['email' => 'nilufar@evolet.tj', 'role' => Access::HEAD]))
            ->assertSessionHasErrors(['placements' => $refusal]);
        $this->assertNull(User::query()->where('email', 'nilufar@evolet.tj')->first());
    }

    /**
     * The role is named outright, and an empty one is not an answer.
     *
     * It used to mean «whatever the jobs say», and the save would work one out
     * behind the person's back — which is how somebody could be quietly put down
     * to a specialist by a window that was never about their role. Now a card
     * carrying the line empty is refused where it would once have been filled
     * in for them. No picker offers emptiness any more, so this only answers a
     * request that was not made by the page — and that is the point of asking:
     * the server is what decides, and it decides the same for either.
     */
    public function test_the_role_is_named_outright_and_an_empty_one_is_refused()
    {
        $sysadmin = $this->sysadmin();
        // Named outright: the surname is what says whether the refused save left
        // the rest of the window alone, so it cannot be whatever came up.
        $colleague = User::factory()->create(['surname' => 'Азимов'])->assignRole(Access::SPECIALIST);

        // Both of the doors a role comes through, and both ways of saying
        // nothing: an empty string and no value at all are one answer, which is
        // none.
        foreach (['', null] as $blank) {
            $this->actingAs($sysadmin)
                ->post('/employees', $this->newCard(['role' => $blank]))
                ->assertSessionHasErrors('role');
            $this->assertNull(User::query()->where('email', 'daler@evolet.tj')->first());

            $this->actingAs($sysadmin)
                ->put("/employees/{$colleague->id}/personal", $this->card($colleague, [
                    'surname' => 'Шарипов',
                    'role' => $blank,
                ]))
                ->assertSessionHasErrors('role');
            $this->assertSame('Азимов', $colleague->refresh()->surname);
        }

        // The line left out of the request is another matter: there is one
        // window for the role and others for the rest of the card, so a window
        // that does not carry it is not asked about it and does not write it.
        $this->actingAs($sysadmin)
            ->put("/employees/{$colleague->id}/personal", Arr::except(
                $this->card($colleague, ['surname' => 'Шарипов']),
                ['role'],
            ))
            ->assertSessionHasNoErrors();

        $colleague->refresh();
        $this->assertSame('Шарипов', $colleague->surname);
        $this->assertSame([Access::SPECIALIST], $colleague->getRoleNames()->all());
    }

    /**
     * A window that moves the jobs and leaves the role alone answers for the
     * role the card keeps.
     *
     * Personnel do not pick the role, so their window carries the jobs and not
     * the role — and the pair still has to hang together once the jobs have
     * moved. It is read against the role the card holds and is about to keep,
     * since working one out from the jobs instead would answer for a role nobody
     * is saving.
     */
    public function test_jobs_moved_without_the_role_answer_for_the_role_the_card_keeps()
    {
        [$leading, $plain] = $this->jobs();
        $personnel = $this->personnel();
        $colleague = User::factory()->create()->assignRole(Access::HEAD);
        $colleague->positions()->attach($leading->id);

        $window = fn (Position ...$jobs) => Arr::except(
            $this->card($colleague, ['placements' => array_map($this->holding(...), $jobs)]),
            ['role'],
        );

        // A head taken out of the job they lead, by a window that says nothing
        // about the role: refused under the role they keep, since nothing would
        // be left for that role to be about.
        $this->actingAs($personnel)
            ->put("/employees/{$colleague->id}/personal", $window($plain))
            ->assertSessionHasErrors(['placements' => 'Руководитель занимает хотя бы одну руководящую должность.']);
        $this->assertSame([$leading->id], $colleague->refresh()->positions->pluck('id')->all());

        // That same ordinary job added beside the leading one is no trouble at
        // all: they still lead the department, and now do other work as well.
        $this->actingAs($personnel)
            ->put("/employees/{$colleague->id}/personal", $window($leading, $plain))
            ->assertSessionHasNoErrors();

        $colleague->refresh();
        $this->assertEqualsCanonicalizing([$leading->id, $plain->id], $colleague->positions->pluck('id')->all());
        $this->assertSame([Access::HEAD], $colleague->getRoleNames()->all());
    }

    /**
     * A card holding «HR-специалист» answers to the system administrator alone,
     * in both of the lines its role is worked out from.
     *
     * Personnel pass every check, so personnel who could demote personnel would
     * be closing the system to each other — and the job is half of what the role
     * is read from, so it is shut the same way. Their own card is no exception:
     * the way out of the role is not the holder's to take either.
     */
    public function test_an_hr_card_is_the_system_administrators_to_rearrange()
    {
        [$leading, $plain] = $this->jobs();
        $role = 'Роль HR-специалиста меняет только системный администратор.';
        $positions = 'Должность HR-специалиста меняет только системный администратор.';

        /** A colleague on file as personnel, holding one ordinary job. */
        $asPersonnel = function () use ($plain): User {
            $colleague = User::factory()->create()->assignRole(Access::HR);
            $colleague->positions()->attach($plain->id);

            return $colleague;
        };

        $personnel = $asPersonnel();
        $colleague = $asPersonnel();

        // Somebody else's card first: the role is refused with the one sentence
        // and the jobs with the other, each on its own field. A name and an
        // empty box alike — the lock is on the line and not on what was written
        // in it, so emptying the box is a change like any other.
        foreach ([Access::SPECIALIST, null, ''] as $sent) {
            $this->actingAs($personnel)
                ->put("/employees/{$colleague->id}/personal", $this->card($colleague, [
                    'role' => $sent,
                    'placements' => [$this->holding($plain)],
                ]))
                ->assertSessionHasErrors(['role' => $role]);
            $this->assertSame([Access::HR], $colleague->refresh()->getRoleNames()->all());
        }

        $this->actingAs($personnel)
            ->put("/employees/{$colleague->id}/personal", $this->card($colleague, [
                'placements' => [$this->holding($leading)],
            ]))
            ->assertSessionHasErrors(['placements' => $positions]);
        $this->assertSame([$plain->id], $colleague->refresh()->positions->pluck('id')->all());

        // And their own card is the same card: the holder is personnel looking
        // at personnel.
        $this->actingAs($personnel)
            ->put("/employees/{$personnel->id}/personal", $this->card($personnel, [
                'role' => Access::SPECIALIST,
                'placements' => [$this->holding($plain)],
            ]))
            ->assertSessionHasErrors(['role' => $role]);
        $this->actingAs($personnel)
            ->put("/employees/{$personnel->id}/personal", $this->card($personnel, [
                'placements' => [$this->holding($leading)],
            ]))
            ->assertSessionHasErrors(['placements' => $positions]);

        $personnel->refresh();
        $this->assertSame([Access::HR], $personnel->getRoleNames()->all());
        $this->assertSame([$plain->id], $personnel->positions->pluck('id')->all());

        // Leaving the two lines where they are is not a change, which is what
        // lets personnel save the rest of their own «Основное». Their window
        // leaves the role out altogether while the jobs have not moved, and
        // sending the one on the card back says the same thing.
        $own = $this->card($personnel, [
            'surname' => 'Азимов',
            'placements' => [$this->holding($plain)],
        ]);

        $this->actingAs($personnel)
            ->put("/employees/{$personnel->id}/personal", Arr::except($own, ['role']))
            ->assertSessionHasNoErrors();
        $this->assertSame('Азимов', $personnel->refresh()->surname);

        $this->actingAs($personnel)
            ->put("/employees/{$personnel->id}/personal", [...$own, 'surname' => 'Азимова'])
            ->assertSessionHasNoErrors();

        $personnel->refresh();
        $this->assertSame('Азимова', $personnel->surname);
        $this->assertSame([Access::HR], $personnel->getRoleNames()->all());

        // The one account it all answers to moves either line without ceremony.
        $sysadmin = $this->sysadmin();

        $this->actingAs($sysadmin)
            ->put("/employees/{$colleague->id}/personal", $this->card($colleague, [
                'placements' => [$this->holding($leading)],
            ]))
            ->assertSessionHasNoErrors();
        $this->assertSame([$leading->id], $colleague->refresh()->positions->pluck('id')->all());

        $this->actingAs($sysadmin)
            ->put("/employees/{$colleague->id}/personal", $this->card($colleague, [
                'role' => Access::HEAD,
                'placements' => [$this->holding($leading)],
            ]))
            ->assertSessionHasNoErrors();
        $this->assertSame([Access::HEAD], $colleague->refresh()->getRoleNames()->all());

        // And both cards say so beside the field rather than letting somebody
        // find out by saving. Their own card is read at /profile, which is the
        // one address it is drawn at.
        $props = fn (User $actor, string $url) => $this->actingAs($actor)->get($url)->viewData('page')['props'];
        $own = $props($personnel, '/profile');

        $this->assertSame($role, $own['roleLocked']);
        $this->assertSame($positions, $own['positionsLocked']);

        $seen = $props($sysadmin, "/employees/{$personnel->id}");
        $this->assertNull($seen['roleLocked']);
        $this->assertNull($seen['positionsLocked']);
    }

    /**
     * Which way round the page asks the pair.
     *
     * The system administrator hands authority out, so the role is picked and
     * the jobs on offer narrow to the ones it may hold. Personnel write down
     * what somebody was hired to do, so the job is picked and the role reads
     * itself off it — which is the difference between appointing a head of
     * department and recording that somebody holds its leading job.
     */
    public function test_the_system_administrator_picks_the_role_and_personnel_read_it_off_the_job()
    {
        $colleague = User::factory()->create();

        foreach ([[$this->sysadmin(), true], [$this->personnel(), false]] as [$actor, $picks]) {
            $held = $actor->roles->first()->name;

            foreach (['/employees/create', "/employees/{$colleague->id}"] as $url) {
                $this->assertSame(
                    $picks,
                    $this->actingAs($actor)->get($url)->viewData('page')['props']['picksRole'],
                    "{$url} глазами роли {$held}",
                );
            }
        }
    }

    /**
     * A card whose role and jobs already disagree keeps both while neither moves.
     *
     * Cards were written before there was a rule about the pair, and a seeder
     * writes them still — so a save answers for what it moves and leaves what it
     * found. Otherwise somebody fixing a surname would be told about a job
     * nobody in that window had touched, with nothing they could do about it.
     *
     * Personnel do not pick the role at all, so their window carries it only
     * once the jobs it is read from have actually moved: a surname saved beside
     * such a card sends no role, and a role nobody sent is a role nobody wrote.
     */
    public function test_an_old_card_whose_role_and_jobs_disagree_keeps_both_while_neither_moves()
    {
        [$leading, $plain] = $this->jobs();
        $personnel = $this->personnel();
        // A head holding nothing but an ordinary job: there is no department on
        // this card to lead, a pair the rule would never have let through, and
        // on file all the same.
        $colleague = User::factory()->create()->assignRole(Access::HEAD);
        $colleague->positions()->attach($plain->id);

        $window = $this->card($colleague, [
            'surname' => 'Азимов',
            'placements' => [$this->holding($plain)],
        ]);

        $this->actingAs($personnel)
            ->put("/employees/{$colleague->id}/personal", Arr::except($window, ['role']))
            ->assertSessionHasNoErrors();

        $colleague->refresh();
        $this->assertSame('Азимов', $colleague->surname);
        // The pair is left exactly as it was found, rather than being tidied up
        // by a save that was never about it.
        $this->assertSame([Access::HEAD], $colleague->getRoleNames()->all());
        $this->assertSame([$plain->id], $colleague->positions->pluck('id')->all());

        // Moving the jobs is the other half of the same decision: then the pair
        // is answered for as it will stand, and a leading job is what makes this
        // card's own role true of it at last.
        $this->actingAs($personnel)
            ->put("/employees/{$colleague->id}/personal", $this->card($colleague, [
                'placements' => [$this->holding($leading)],
            ]))
            ->assertSessionHasNoErrors();

        $colleague->refresh();
        $this->assertSame([Access::HEAD], $colleague->getRoleNames()->all());
        $this->assertSame([$leading->id], $colleague->positions->pluck('id')->all());

        // And a move that would put the card back where it was found is refused,
        // which is the difference between leaving an old pair alone and writing
        // a new one just like it.
        $this->actingAs($personnel)
            ->put("/employees/{$colleague->id}/personal", $this->card($colleague, [
                'placements' => [$this->holding($plain)],
            ]))
            ->assertSessionHasErrors(['placements' => 'Руководитель занимает хотя бы одну руководящую должность.']);
        $this->assertSame([$leading->id], $colleague->refresh()->positions->pluck('id')->all());
    }

    /**
     * A card is opened under the role the page names, with the jobs beside it.
     *
     * The page is what works the role out for whoever does not pick it — a
     * leading job reads as «Руководитель» and every other one as «Специалист» —
     * and the request carries the answer. The server writes what it was told,
     * having made sure the two agree. A card with no job at all is a card all
     * the same: the role is the line that has to be answered, the job is not.
     */
    public function test_a_card_is_opened_under_the_role_the_page_names()
    {
        [$leading, $plain] = $this->jobs();
        $sysadmin = $this->sysadmin();

        /** @param  list<array{position_id: int, duty_ids: list<int>}>  $placements */
        $opened = function (string $email, string $role, array $placements) use ($sysadmin): User {
            $this->actingAs($sysadmin)
                ->post('/employees', $this->newCard([
                    'email' => $email,
                    'role' => $role,
                    'placements' => $placements,
                ]))
                ->assertSessionHasNoErrors();

            return User::query()->where('email', $email)->sole();
        };

        $head = $opened('daler@evolet.tj', Access::HEAD, [$this->holding($leading)]);
        $this->assertSame([Access::HEAD], $head->roles->pluck('name')->all());

        $specialist = $opened('nilufar@evolet.tj', Access::SPECIALIST, [$this->holding($plain)]);
        $this->assertSame([Access::SPECIALIST], $specialist->roles->pluck('name')->all());

        // No job at all, which is nothing to answer for: nobody is placed
        // anywhere until somebody says so, and the role is on the card either
        // way.
        $nowhere = $opened('rustam@evolet.tj', Access::SPECIALIST, []);
        $this->assertSame([Access::SPECIALIST], $nowhere->roles->pluck('name')->all());
        $this->assertSame([], $nowhere->positions->pluck('id')->all());
    }

    public function test_a_card_moved_into_a_leading_job_holds_the_role_that_comes_with_it()
    {
        [$leading, $plain] = $this->jobs();
        $sysadmin = $this->sysadmin();
        $colleague = User::factory()->create();

        // The role and the job travel together, and the role is written after
        // the jobs, so what lands is the pair the window sent rather than half
        // of each. Leading the department is read off the job and not off the
        // role: the flag on the membership follows the position.
        $this->actingAs($sysadmin)
            ->put("/employees/{$colleague->id}/personal", $this->card($colleague, [
                'role' => Access::SPECIALIST,
                'placements' => [$this->holding($plain)],
            ]))
            ->assertSessionHasNoErrors();

        $colleague->refresh();
        $this->assertSame([Access::SPECIALIST], $colleague->roles->pluck('name')->all());
        $this->assertFalse((bool) $colleague->departments->first()->pivot->is_head);

        $this->actingAs($sysadmin)
            ->put("/employees/{$colleague->id}/personal", $this->card($colleague, [
                'role' => Access::HEAD,
                'placements' => [$this->holding($leading)],
            ]))
            ->assertSessionHasNoErrors();

        $colleague->refresh();
        $this->assertSame([Access::HEAD], $colleague->roles->pluck('name')->all());
        $this->assertTrue((bool) $colleague->departments->first()->pivot->is_head);
    }

    public function test_the_system_administrators_own_role_is_nobodys_to_change()
    {
        $sysadmin = $this->sysadmin();
        $locked = 'Роль системного администратора не меняется.';

        // Including by the account that holds it: there would be nobody left who
        // can reach everything.
        $this->actingAs($sysadmin)
            ->put("/employees/{$sysadmin->id}/personal", $this->card($sysadmin, ['role' => Access::HR]))
            ->assertSessionHasErrors(['role' => $locked]);
        $this->assertSame([Access::SOLE_ROLE], $sysadmin->refresh()->roles->pluck('name')->all());

        // An empty box is refused on this one card, where on any other it means
        // «whatever the jobs say»: letting it through would have the save work a
        // role out from the positions and quietly put the one account that
        // reaches everything down to a specialist.
        foreach ([null, ''] as $blank) {
            $this->actingAs($sysadmin)
                ->put("/employees/{$sysadmin->id}/personal", $this->card($sysadmin, ['role' => $blank]))
                ->assertSessionHasErrors(['role' => $locked]);
            $this->assertSame([Access::SOLE_ROLE], $sysadmin->refresh()->roles->pluck('name')->all());
        }

        // Nobody else gets to do it either, personnel included.
        $this->actingAs($this->personnel())
            ->put("/employees/{$sysadmin->id}/personal", $this->card($sysadmin, ['role' => Access::SPECIALIST]))
            ->assertSessionHasErrors(['role' => $locked]);
        $this->assertSame([Access::SOLE_ROLE], $sysadmin->refresh()->roles->pluck('name')->all());

        // The card says so beside the field, rather than letting somebody find
        // out by saving.
        $this->assertSame(
            $locked,
            $this->actingAs($sysadmin)->get('/profile')->viewData('page')['props']['roleLocked'],
        );

        // Nothing else on that card is locked, and no other card is.
        $this->actingAs($sysadmin)
            ->put("/employees/{$sysadmin->id}/personal", $this->card($sysadmin, ['surname' => 'Азимов']))
            ->assertSessionHasNoErrors();
        $this->assertSame('Азимов', $sysadmin->refresh()->surname);

        $colleague = User::factory()->create();
        $this->assertNull(
            $this->actingAs($sysadmin)->get("/employees/{$colleague->id}")->viewData('page')['props']['roleLocked'],
        );
    }

    public function test_a_system_administrator_cannot_strike_out_their_own_card()
    {
        // The one thing the role does not open. Everything else on the card of
        // somebody else is theirs, and their own card is theirs to edit — but
        // there would be nobody left to hand access out.
        $sysadmin = $this->sysadmin();

        $this->actingAs($sysadmin)->delete("/employees/{$sysadmin->id}")->assertForbidden();
        $this->assertNotNull(User::find($sysadmin->id));

        // Leaving by the other doors is refused just the same.
        $this->actingAs($sysadmin)
            ->post("/employees/{$sysadmin->id}/fire", ['date' => '2026-09-01'])
            ->assertForbidden();
        $this->actingAs($sysadmin)
            ->post("/employees/{$sysadmin->id}/transfer", ['date' => '2026-09-01', 'note' => 'В другую компанию'])
            ->assertForbidden();
        $this->assertTrue($sysadmin->refresh()->isActive());

        // Somebody else's card is another matter.
        $colleague = User::factory()->create();
        $this->actingAs($sysadmin)->delete("/employees/{$colleague->id}")->assertRedirect();
        $this->assertNull(User::find($colleague->id));
    }

    public function test_the_system_administrator_is_offered_in_no_picker()
    {
        $sysadmin = $this->sysadmin();
        $colleague = User::factory()->create();

        $names = fn (string $url) => collect(
            $this->actingAs($sysadmin)->get($url)->viewData('page')['props']['options']['roles'],
        )->pluck('name')->all();

        $this->assertSame(['hr', 'head', 'specialist'], $names('/employees/create'));
        $this->assertSame(['hr', 'head', 'specialist'], $names("/employees/{$colleague->id}"));
        // The filter on the list is reading rather than handing out, so it
        // offers the three a person here can be found by — and not the fourth.
        $this->assertSame(['hr', 'head', 'specialist'], $names('/employees'));

        // Their own card is the exception: it must show the role it carries, or
        // the next save would quietly drop it.
        $this->assertContains(Access::SOLE_ROLE, $names('/profile'));

        // And search does not hand out a way in either.
        $this->assertSame([], $this->actingAs($sysadmin)->getJson('/search?q='.urlencode('Системный'))->json('roles'));
    }
}
