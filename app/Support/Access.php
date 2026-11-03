<?php

namespace App\Support;

use App\Models\Position;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Permission\Models\Role;

/**
 * The four roles there are, and every right the system knows about.
 *
 * A right is a section plus what it lets a person do there. The list lives in
 * code because the sections and the actions are the application itself —
 * positions come and go, but "Оборудование: выдача" only exists as long as there
 * is a page that hands equipment out.
 *
 * Nothing hands these rights out at the moment. Roles used to be a directory
 * with a table of ticks behind it; they are now the four named below, two of
 * which pass every check through Gate::before while the other two wait for a
 * site that is open to them. The catalogue is kept whole all the same: it is
 * what the card is built out of, and it is what the ticks will go back onto.
 */
final class Access
{
    /**
     * The system administrator: one account, and nobody hands the role out.
     *
     * It holds no rights of its own and passes every check through Gate::before.
     * There is exactly one, it is offered in no picker, and it cannot be given to
     * anybody — not even by the account that holds it, since a second one would
     * be a second pair of hands on everything.
     */
    public const SOLE_ROLE = 'sysadmin';

    /** Personnel: everything the system administrator may do, and the work besides. */
    public const HR = 'hr';

    /** Whoever holds a leading position of a department. */
    public const HEAD = 'head';

    /** Everybody else who works here. */
    public const SPECIALIST = 'specialist';

    /**
     * The four roles there are, in the order a list shows them.
     *
     * Roles are no longer a directory the company keeps: they are these four and
     * nothing else, and what each may do is decided here rather than ticked on a
     * page. Two of them pass every check; the other two have no rights at all yet,
     * because the site is not open to them — their part of it is still being
     * built, and until it is they are shown that and nothing more.
     *
     * The arrangement is deliberately a blunt one and is expected to be undone:
     * the machinery for deciding a card line by line is still here, with nothing
     * driving it, waiting for the day somebody decides what a head of department
     * may read.
     *
     * @var array<string, string> key => the name it is called by
     */
    public const ROLES = [
        self::SOLE_ROLE => 'Системный администратор',
        self::HR => 'HR-специалист',
        self::HEAD => 'Руководитель',
        self::SPECIALIST => 'Специалист',
    ];

    /**
     * The roles that pass every check, Gate::before and all.
     *
     * @var list<string>
     */
    public const EVERYTHING = [self::SOLE_ROLE, self::HR];

    /**
     * The roles the site is not open to yet.
     *
     * Named rather than inferred from the two above, so that an account holding
     * neither — a fixture, an installation halfway through a migration — is not
     * shut out by an accident of arithmetic. In a working company every account
     * holds one of the four, and these are the two that wait.
     *
     * @var list<string>
     */
    public const CLOSED = [self::HEAD, self::SPECIALIST];

    /** Whether this person passes every check. */
    public static function everything(User $user): bool
    {
        return $user->hasAnyRole(self::EVERYTHING);
    }

    /** Whether the site is still closed to this person. */
    public static function closed(User $user): bool
    {
        return $user->hasAnyRole(self::CLOSED);
    }

    /**
     * The role a card is given when nobody says otherwise: a leading position
     * makes a head, and everything else makes a specialist.
     *
     * The flag is on the job rather than on the person, so this is a question
     * about the positions held and not about the card — somebody leads a
     * department by holding its leading job and stops when the job is taken away.
     *
     * @param  list<int>  $positions  the positions about to be held, when they are
     *                                not on the card yet
     */
    public static function derive(?User $employee, array $positions = []): string
    {
        $leads = Position::query()->where('is_head', true);

        $leads = $employee === null
            ? $leads->whereIn('id', $positions)
            : $leads->where(fn (Builder $q) => $q
                ->whereIn('id', $positions)
                ->orWhereHas('users', fn (Builder $u) => $u->whereKey($employee->id)));

        return $leads->exists() ? self::HEAD : self::SPECIALIST;
    }

    /**
     * The roles this person may put on somebody's card.
     *
     * The system administrator is in no list at all. «HR-специалист» is handed out
     * by the system administrator alone: personnel pass every check, so an HR
     * specialist who could appoint another would be the whole access system in one
     * person's gift. The remaining two are the ordinary choice.
     *
     * @return list<string>
     */
    public static function offeredTo(User $actor): array
    {
        return array_values(array_filter(
            [self::HR, self::HEAD, self::SPECIALIST],
            fn (string $role) => $role !== self::HR || $actor->hasRole(self::SOLE_ROLE),
        ));
    }

    /** The sections, in the order the table shows them. */
    public const SECTIONS = [
        // Its own card comes first: everybody has one, and a position is read from
        // what it does for the person holding it before what it does to others.
        // No rights of the plain kind at all — what is on a card is decided line
        // by line.
        'profile' => 'Профиль',
        'employees' => 'Сотрудники',
        'equipment' => 'Оборудование',
        'directories' => 'Справочники',
    ];

    /**
     * key => [what it is called, what it actually opens up].
     *
     * The key is the section, a dot and the action, so the section a right
     * belongs to is read off the key itself.
     *
     * @var array<string, array{string, string}>
     */
    public const PERMISSIONS = [
        // Открывается ли раздел вообще. Ставится не галочкой, а самим выбором
        // полей: позиция, которая не читает ни одной строки карточки, в разделе
        // ничего и не найдёт.
        'employees.view' => ['Просмотр', 'Список сотрудников и их карточки. Что именно видно в карточке — выбирается по строкам.'],

        // A new colleague is put on the books whole, every line of the card at
        // once, so adding somebody is an action rather than a line: the rights to
        // single lines decide what may be changed afterwards, not whether anybody
        // may be added at all.
        'employees.create' => ['Добавление', 'Завести нового сотрудника и заполнить его карточку целиком.'],
        'employees.transfer' => ['Перевод', 'Перевести сотрудника в другой отдел или на другую должность.'],
        'employees.fire' => ['Увольнение', 'Уволить сотрудника и восстановить уволенного.'],
        'employees.delete' => ['Удаление', 'Удалить сотрудника вместе со всем, что на него записано.'],

        // У оборудования обычных прав нет вовсе: просмотр — это три области со
        // своими журналами, изменение — блоки карточки, действия — операции над
        // единицей. Всё это живёт в App\Support\EquipmentAccess.

        // У справочников обычных прав тоже нет: это пять отдельных списков, и
        // каждый открывается и меняется сам по себе (App\Support\Directories).
    ];

    /**
     * What a position gets when it is first created: everybody who works here
     * may look around. Anything beyond looking is handed out deliberately, on
     * the access page.
     *
     * @var list<string>
     */
    public const DEFAULTS = ['employees.view', 'equipment.view.own'];

    /**
     * The same, with the fields a card used to show any colleague: a position is
     * created able to see what was never private in the first place.
     *
     * @return list<string>
     */
    public static function defaults(): array
    {
        return [...self::DEFAULTS, ...EmployeeFields::defaultPermissions()];
    }

    /**
     * Every right there is: the sections, and then one per field of an employee
     * card — those are rights like any other, so positions, personal exceptions
     * and Gate::before apply to them unchanged.
     *
     * @return list<string>
     */
    public static function keys(): array
    {
        return [
            ...array_keys(self::PERMISSIONS),
            ...EmployeeFields::permissions(),
            ...EquipmentAccess::permissions(),
            ...Directories::permissions(),
        ];
    }

    /**
     * The roles a list may be narrowed by: all of them but the one there is only
     * ever one of.
     *
     * Not the same question as what may be handed out — a filter is reading, and
     * somebody who cannot appoint personnel can still have business finding them.
     *
     * @return Builder<Role>
     */
    public static function listedRoles(): Builder
    {
        return self::inCatalogueOrder(
            Role::query()->whereNot('name', self::SOLE_ROLE),
            [self::HR, self::HEAD, self::SPECIALIST],
        );
    }

    /**
     * The roles a picker may offer this person, as rows to show.
     *
     * @return Builder<Role>
     */
    public static function offeredRoles(User $actor): Builder
    {
        $offered = self::offeredTo($actor);

        return self::inCatalogueOrder(Role::query()->whereIn('name', $offered), $offered);
    }

    /**
     * Rows in the order the catalogue names them, which goes from the widest role
     * to the narrowest.
     *
     * Sorting by the name shown would put «HR-специалист» first for the alphabet's
     * reasons rather than for any of ours, and a database has no opinion about
     * which role is wider — so the order is spelled out, as a CASE rather than as
     * one of the vendor-specific functions for it.
     *
     * @param  Builder<Role>  $roles
     * @param  list<string>  $names
     * @return Builder<Role>
     */
    private static function inCatalogueOrder(Builder $roles, array $names): Builder
    {
        $case = 'case name';

        foreach ($names as $place => $name) {
            $case .= " when ? then {$place}";
        }

        return $roles->orderByRaw($case.' else '.count($names).' end', $names);
    }

    /**
     * Why this card's role may not be changed, or null when it may.
     *
     * Two rules, both about the roles that pass every check. The system
     * administrator's own role is nobody's to touch, its own holder included,
     * since there would be nobody left who can reach everything. And a card
     * holding «HR-специалист» answers to the system administrator alone: the
     * right to hand that role out is the system administrator's, and taking it
     * away is the same right read backwards — personnel who could demote
     * personnel would be closing the system to each other.
     *
     * The reason is a sentence rather than a flag, because the card shows it
     * beside the locked field and the form repeats it if a request comes anyway.
     */
    public static function roleLockedReason(User $actor, User $employee): ?string
    {
        if ($employee->hasRole(self::SOLE_ROLE)) {
            return 'Роль системного администратора не меняется.';
        }

        return $employee->hasRole(self::HR) && ! $actor->hasRole(self::SOLE_ROLE)
            ? 'Роль HR-специалиста меняет только системный администратор.'
            : null;
    }

    /**
     * Why the jobs on this card are not this person's to rearrange, or null when
     * they are.
     *
     * The same rule as the role above, for the same reason: what personnel may do
     * follows from the role, and the job is half of what the role is worked out
     * from. A card holding «HR-специалист» is the system administrator's — this
     * one included, when personnel are looking at their own.
     */
    public static function positionsLockedReason(User $actor, User $employee): ?string
    {
        return $employee->hasRole(self::HR) && ! $actor->hasRole(self::SOLE_ROLE)
            ? 'Должность HR-специалиста меняет только системный администратор.'
            : null;
    }

    /**
     * Whether this person picks a role, or reads the one the job decided.
     *
     * The system administrator picks, and the jobs on offer narrow to the ones
     * that role may hold. Everybody else who may fill a card in — personnel —
     * picks the job, and the role follows: a leading job makes a head and every
     * other job a specialist. Which way round it goes is the difference between
     * handing somebody authority and recording what they were hired to do.
     */
    public static function picksRole(User $actor): bool
    {
        return $actor->hasRole(self::SOLE_ROLE);
    }

    /**
     * Why this role cannot stand over these jobs, or null when it can.
     *
     * A head leads something, so one of the jobs on the card is a leading one —
     * and the rest need not be. Heading a department is work a person does
     * besides their own, not instead of it, and a card that refused the pair
     * would be telling a head of department they may not also be an analyst. A
     * specialist is the other way about and admits of no exception: hold a
     * leading job and you are a head, whatever the card says.
     *
     * «HR-специалист» is personnel wherever they sit, and the question does not
     * arise.
     *
     * @param  bool  $anyLeading  whether one of the jobs leads a department
     */
    public static function roleAgainstJobs(string $role, bool $anyLeading): ?string
    {
        return match ($role) {
            self::HEAD => $anyLeading ? null : 'Руководитель занимает хотя бы одну руководящую должность.',
            self::SPECIALIST => $anyLeading ? 'Специалист не занимает руководящих должностей.' : null,
            default => null,
        };
    }

    public static function has(string $key): bool
    {
        return isset(self::PERMISSIONS[$key]);
    }

    /** The section a right belongs to: "employees.view" => "employees". */
    public static function section(string $key): string
    {
        return explode('.', $key)[0];
    }
}
