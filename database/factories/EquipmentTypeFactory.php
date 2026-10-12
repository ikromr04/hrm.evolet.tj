<?php

namespace Database\Factories;

use App\Models\EquipmentField;
use App\Models\EquipmentType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EquipmentType>
 */
class EquipmentTypeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->word(),
        ];
    }

    /**
     * A category made here starts out the way one made in the directory does:
     * with the two fields a unit is usually named by, so a unit built on top of
     * it has somewhere to keep what it is called and the number on its sticker.
     * Either can be dropped afterwards — `withoutIdentity` is that category.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (EquipmentType $type) {
            foreach (array_keys(EquipmentField::ROLES) as $position => $role) {
                $type->fields()->create([
                    'role' => $role,
                    'name' => EquipmentField::ROLES[$role],
                    'type' => 'text',
                    'required' => true,
                    'position' => $position,
                ]);
            }
        });
    }

    /**
     * A category that names its units by nothing at all: a box of patch cords
     * has no name of its own and no sticker to put a number on. Its units are
     * spoken of by their category and whatever else it asks about them.
     */
    public function withoutIdentity(): static
    {
        return $this->afterCreating(fn (EquipmentType $type) => $type->fields()->whereNotNull('role')->delete());
    }
}
