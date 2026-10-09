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
 * One unit of company hardware, identified by its inventory number. It belongs
 * to the company throughout: being handed to someone only changes who holds it.
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
    public bool $unannounced = false;

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
     * as it likes — and both are read here by the role the field carries, so
     * everything that speaks of a unit goes on speaking of it the same way.
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
     * The value of the field holding one of the two roles, or null while the
     * unit is new and has none. Reads what is loaded; `withIdentity` loads it
     * for a list in one go.
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
     * two fields of its category that carry those roles, adding them to the
     * category if it somehow has none — a unit without either cannot be shown
     * in a list, named in the journal or found by a search.
     */
    public function writeIdentity(?string $title, ?string $number): void
    {
        foreach (['title' => $title, 'inventory' => $number] as $role => $value) {
            if ($value === null) {
                continue;
            }

            // A category that somehow has no field for the role gets one at the
            // end of its list: the directory decides the order, and a mended
            // field pushing its way to the top would reorder what is there.
            $field = EquipmentField::firstOrCreate(
                ['equipment_type_id' => $this->equipment_type_id, 'role' => $role],
                [
                    'name' => EquipmentField::ROLES[$role],
                    'type' => 'text',
                    'required' => true,
                    'position' => 1 + (int) EquipmentField::where('equipment_type_id', $this->equipment_type_id)->max('position'),
                ],
            );

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
     * What one unit holds in the field of that role, as a subquery: for sorting
     * a list by name, searching by number, or showing either in a column.
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
     * Back on the balance sheet, held by nobody. A return and an employee's
     * deletion both end a spell this way, so the two cannot drift apart on
     * which columns a unit on the shelf leaves empty. One save, so the journal
     * reads it as one act; what the caller knows besides (the day it was
     * looked over, the state it came back in) goes into the same save.
     *
     * @param  array<string, mixed>  $also
     */
    public function putOnBalance(array $also = []): void
    {
        $this->update([
            'status' => 'stock',
            'holder_user_id' => null,
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
     * Being looked after right now: a piece of work with no end date on it.
     * This is not a status — a laptop can sit on its owner's desk while its
     * keyboard is on order — so it is asked of the records, not of the row.
     */
    public function scopeUnderService(Builder $query): void
    {
        $query->whereHas('repairs', fn (Builder $repairs) => $repairs->whereNull('ended_at'));
    }
}
