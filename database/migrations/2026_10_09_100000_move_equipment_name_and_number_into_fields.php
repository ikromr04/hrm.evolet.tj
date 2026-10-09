<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** The two a unit cannot be without, and what they are called to begin with. */
    private const ROLES = [
        'title' => 'Наименование',
        'inventory' => 'Инвентарный номер',
    ];

    /**
     * What a unit is called and the number on its sticker used to be columns of
     * the equipment table, the same two for every category. They become fields
     * of the category like all the others, so a category can call them by its
     * own words and put them where it wants — a printer's "Наименование" may
     * read "Модель", a cable's number may come first.
     *
     * They keep a role, because the rest of the system still has to know which
     * field names a unit and which carries its number: the list, the journal,
     * the letters and the search all speak of a unit by those two.
     */
    public function up(): void
    {
        Schema::table('equipment_fields', function (Blueprint $table) {
            // Null for an ordinary field; one of each role per category.
            $table->string('role', 20)->nullable()->after('equipment_type_id');
        });

        $now = now();

        // Each category gets the two, ahead of what it already has.
        DB::table('equipment_fields')->increment('position', count(self::ROLES));

        foreach (DB::table('equipment_types')->orderBy('id')->get(['id']) as $type) {
            $position = 0;

            foreach (self::ROLES as $role => $name) {
                $field = DB::table('equipment_fields')->insertGetId([
                    'equipment_type_id' => $type->id,
                    'role' => $role,
                    // A category that already has a field by that name keeps its
                    // own: the role goes to a second one, named so it is clear.
                    'name' => $this->freeName($type->id, $name),
                    'type' => 'text',
                    'required' => true,
                    'position' => $position++,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $column = $role === 'title' ? 'name' : 'inventory_number';

                DB::table('equipment')
                    ->where('equipment_type_id', $type->id)
                    ->orderBy('id')
                    ->each(function (object $unit) use ($field, $column, $now) {
                        DB::table('equipment_field_values')->insertOrIgnore([
                            'equipment_id' => $unit->id,
                            'equipment_field_id' => $field,
                            'value' => $unit->{$column},
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    });
            }
        }

        Schema::table('equipment', function (Blueprint $table) {
            // The unique index goes with the column; a number is checked against
            // the values of the inventory field from here on.
            $table->dropUnique(['inventory_number']);
            $table->dropColumn(['name', 'inventory_number']);
        });
    }

    /**
     * Reverse the migrations: the columns come back carrying what the two
     * fields hold, and the fields themselves go.
     */
    public function down(): void
    {
        Schema::table('equipment', function (Blueprint $table) {
            $table->string('name', 200)->default('')->after('equipment_type_id');
            $table->string('inventory_number', 50)->default('')->after('name');
        });

        foreach (array_keys(self::ROLES) as $role) {
            $column = $role === 'title' ? 'name' : 'inventory_number';

            DB::table('equipment_field_values')
                ->join('equipment_fields', 'equipment_fields.id', '=', 'equipment_field_values.equipment_field_id')
                ->where('equipment_fields.role', $role)
                ->orderBy('equipment_field_values.id')
                ->select(['equipment_field_values.equipment_id', 'equipment_field_values.value'])
                ->each(fn (object $row) => DB::table('equipment')
                    ->where('id', $row->equipment_id)
                    ->update([$column => (string) ($row->value ?? '')]));
        }

        DB::table('equipment_fields')->whereNotNull('role')->delete();
        DB::table('equipment_fields')->decrement('position', count(self::ROLES));

        Schema::table('equipment', function (Blueprint $table) {
            $table->unique('inventory_number');
        });

        Schema::table('equipment_fields', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }

    /**
     * The name for a role field in a category, left alone where it is free and
     * given the role's own words where the category already uses it.
     */
    private function freeName(int $type, string $name): string
    {
        $taken = fn (string $candidate) => DB::table('equipment_fields')
            ->where('equipment_type_id', $type)
            ->where('name', $candidate)
            ->exists();

        if (! $taken($name)) {
            return $name;
        }

        for ($n = 2; $n < 50; $n++) {
            if (! $taken("{$name} {$n}")) {
                return "{$name} {$n}";
            }
        }

        return $name.' '.uniqid();
    }
};
