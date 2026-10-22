<?php

namespace App\Models;

use App\Observers\EquipmentObserver;
use Database\Factories\EquipmentFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One unit of company hardware, known by whatever its category asks about it —
 * an inventory number where the category keeps one. It belongs to the company
 * throughout: being handed to someone only changes who holds it.
 */
#[ObservedBy(EquipmentObserver::class)]
class Equipment extends Model
{
    /** @use HasFactory<EquipmentFactory> */
    use HasFactory;

    protected $table = 'equipment';

    /**
     * A line for the journal entry the next save writes — why a unit moved,
     * when the move itself does not say. Not a column: it lives only as long
     * as the request that sets it.
     */
    public ?string $journalNote = null;

    /**
     * Changes to fold into the journal entry the next save writes: what moved
     * in the fields of the unit's category, which live in a table of their own
     * and so are invisible to the row's own diff. Like the note, it lives only
     * as long as the request that sets it.
     *
     * @var array<string, array{mixed, mixed}>
     */
    public array $journalExtra = [];

    /**
     * Set when the next save moves the unit without anybody to tell about it:
     * its holder is being deleted, and a line behind the bell of a person who
     * is gone would be read by nobody. Like the note, it lasts one save.
     */

    /** In the order the list's tabs show them. */
    public const STATUSES = ['issued', 'stock', 'written_off'];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'equipment_type_id',
        'condition',
        'checked_at',
        'next_inventory_at',
        'accessories',
        'status',
        'holder_user_id',
        'holder_department_id',
        'issued_at',
        'written_off_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'issued_at' => 'date',
            'written_off_at' => 'date',
            'checked_at' => 'date',
            'next_inventory_at' => 'date',
            'accessories' => 'array',
        ];
    }

    /**
     * What this unit has in the fields of its category. Keyed by field id, so a
     * form and a card can look a value up without walking the list.
     */
    public function fieldValues(): HasMany
    {
        return $this->hasMany(EquipmentFieldValue::class);
    }

    /**
     * What the unit is called, and the number on its sticker. Both are fields
     * of its category like any other — a category names them and orders them
     * as it likes, and may have neither — and both are read here by the role
     * the field carries, so everything that speaks of a unit goes on speaking
     * of it the same way. Null where the category asks nothing of the kind, or
     * where it asks and this unit was left blank.
     */
    protected function name(): Attribute
    {
        return Attribute::get(fn () => $this->roleValue('title'));
    }

    protected function inventoryNumber(): Attribute
    {
        return Attribute::get(fn () => $this->roleValue('inventory'));
    }

    /**
     * The value of the field holding that role, or null when the category has
     * no such field, when the unit is new, or when the field was left blank.
     * Reads what is loaded; `withIdentity` loads it for a list in one go.
     */
    public function roleValue(string $role): ?string
    {
        $values = $this->relationLoaded('fieldValues')
            ? $this->fieldValues
            : $this->fieldValues()->with('field:id,role')->get();

        return $values->first(fn (EquipmentFieldValue $value) => $value->field?->role === $role)?->value;
    }

    /**
     * Writes what the unit is called and the number on its sticker into the
     * fields of its category that carry those roles.
     *
     * A category that has dropped one of them is asked nothing about it and has
     * nowhere to keep it: the value is let go rather than written into a field
     * made up for it, so deleting a field in the directory stays deleted. A
     * value of null is left alone either way — nobody said anything about it.
     */
    public function writeIdentity(?string $title, ?string $number): void
    {
        if ($title === null && $number === null) {
            return;
        }

        $fields = EquipmentField::query()
            ->where('equipment_type_id', $this->equipment_type_id)
            ->whereNotNull('role')
            ->get(['id', 'role'])
            ->keyBy('role');

        foreach (['title' => $title, 'inventory' => $number] as $role => $value) {
            $field = $fields->get($role);

            if ($value === null || $field === null) {
                continue;
            }

            $this->fieldValues()->updateOrCreate(['equipment_field_id' => $field->id], ['value' => $value]);
        }

        $this->unsetRelation('fieldValues');
    }

    /**
     * Loads what it takes to name a unit: its values and the roles of the
     * fields they belong to.
     *
     * @param  Builder<Equipment>  $query
     */
    public function scopeWithIdentity(Builder $query): void
    {
        $query->with(['fieldValues' => fn ($q) => $q->whereHas('field', fn ($q) => $q->whereNotNull('role'))->with('field:id,role,name')]);
    }

    /**
     * The unit whose inventory field holds this number. The number is a field
     * value now, so it is asked for through the field that carries the role
     * rather than off a column of the unit's own.
     *
     * @param  Builder<Equipment>  $query
     */
    public function scopeWhereInventory(Builder $query, string $number): void
    {
        $query->where(self::roleValueQuery('inventory'), $number);
    }

    /**
     * In the order of what the units hold in the field of that role, the ones
     * holding nothing after them — whichever way the column itself is read.
     *
     * A unit whose category has no field for a name has nothing to be ordered
     * by, and the foot of the list is where it is least in the way: a column
     * sorted by name is read for the names in it.
     *
     * @param  Builder<Equipment>  $query
     */
    public function scopeOrderByRole(Builder $query, string $role, string $direction = 'asc'): void
    {
        self::putMissingLast($query, $role);

        $query->orderBy(self::roleValueQuery($role), $direction);
    }

    /**
     * Units holding nothing in the field of that role sort after the ones that
     * do. Its own direction, for a walk that reads one order backwards: the
     * step back from a unit has to undo the step forward exactly.
     *
     * @param  Builder<Equipment>  $query
     */
    public static function putMissingLast(Builder $query, string $role, string $direction = 'asc'): void
    {
        $value = self::roleValueQuery($role);

        $query->orderByRaw('('.$value->toSql().') is null '.($direction === 'desc' ? 'desc' : 'asc'), $value->getBindings());
    }

    /**
     * What one unit holds in the field of that role, as a subquery: for sorting
     * a list by name, searching by number, or showing either in a column. Null
     * where the unit's category has no field of the kind: a comparison against
     * null is simply never true, so such a unit answers no search, and where it
     * lands in an order is `orderByRole`'s business.
     *
     * @return \Illuminate\Database\Query\Builder
     */
    public static function roleValueQuery(string $role)
    {
        return EquipmentFieldValue::query()
            ->select('equipment_field_values.value')
            ->join('equipment_fields', 'equipment_fields.id', '=', 'equipment_field_values.equipment_field_id')
            ->whereColumn('equipment_field_values.equipment_id', 'equipment.id')
            ->where('equipment_fields.role', $role)
            ->limit(1)
            ->getQuery();
    }

    public function repairs(): HasMany
    {
        return $this->hasMany(EquipmentRepair::class)->orderByDesc('started_at')->orderByDesc('id');
    }

    /**
     * Every photograph ever taken of it, newest first. Each one belongs to the
     * check it was taken for; this is the whole run of them.
     */
    public function photos(): HasMany
    {
        return $this->hasMany(EquipmentPhoto::class)->orderByDesc('id');
    }

    /**
     * Everything that has happened to it, newest first.
     */
    public function events(): HasMany
    {
        return $this->hasMany(EquipmentEvent::class)->orderByDesc('created_at')->orderByDesc('id');
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(EquipmentType::class, 'equipment_type_id');
    }

    public function holder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'holder_user_id');
    }

    /**
     * The department a unit is signed out to, where its category is the kind
     * whose things are a department's rather than a colleague's. Empty for
     * everything else, and never filled at the same time as the colleague
     * beside it — the database itself refuses a unit held twice.
     */
    public function holderDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'holder_department_id');
    }

    /**
     * Who answers for it right now: the colleague it is signed out to, the
     * department it is signed out to, or nobody while it is on the balance
     * sheet. Two columns, one question, asked in one place so that nothing has
     * to remember there are two.
     */
    public function heldBy(): User|Department|null
    {
        return $this->holder ?? $this->holderDepartment;
    }

    /**
     * Back on the balance sheet, held by nobody. A return, a write-off and an
     * employee's deletion all end a spell this way, so they cannot drift apart
     * on which columns a unit on the shelf leaves empty — both holders are let
     * go, whichever of the two the spell was. One save, so the journal reads it
     * as one act; what the caller knows besides (the day it was looked over,
     * the state it came back in) goes into the same save.
     *
     * @param  array<string, mixed>  $also
     */
    public function putOnBalance(array $also = []): void
    {
        $this->update([
            'status' => 'stock',
            'holder_user_id' => null,
            'holder_department_id' => null,
            'issued_at' => null,
            ...$also,
        ]);
    }

    /**
     * Still part of the fleet: everything but what has been written off.
     */
    public function scopeInService(Builder $query): void
    {
        $query->where('status', '!=', 'written_off');
    }

    /**
     * The equipment of these departments, held either way: the units signed
     * out to a department itself, and the units of the people who stand in it.
     *
     * Both are what «техника отдела» means — the printer in its corridor and
     * the laptops of everyone who works there — and saying so once is what
     * keeps the equipment table, its two funnels and a department's own page
     * from each having an idea of their own about it. Nothing here says
     * anything about who may see what: that is `EquipmentAccess`, and every
     * caller narrows by it as well.
     *
     * A unit nobody holds is in neither half. It is on the balance sheet or
     * struck off, and a thing in no one's hands is no department's either.
     *
     * @param  Builder<Equipment>  $query
     * @param  list<int>  $departments
     */
    public function scopeFiledIn(Builder $query, array $departments): void
    {
        $query->where(fn (Builder $q) => $q
            ->whereIn('holder_department_id', $departments)
            ->orWhereHas('holder.departments', fn (Builder $q) => $q->whereIn('departments.id', $departments)));
    }

    /**
     * Being looked after right now: a piece of work with no end date on it.
     * This is not a status — a laptop can sit on its owner's desk while its
     * keyboard is on order — so it is asked of the records, not of the row.
     */
    public function scopeUnderService(Builder $query): void
    {
        $query->whereHas('repairs', fn (Builder $repairs) => $repairs->whereNull('ended_at'));
    }
}
