<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // What follows is the demo: invented people, and lists that are put back
        // the way the code has them on every run. A plain `db:seed` typed on the
        // company's server out of habit gets the careful seeder instead.
        if (app()->isProduction()) {
            $this->call(ProductionSeeder::class);

            return;
        }

        $this->call([
            RoleSeeder::class,
            DepartmentSeeder::class,
            PositionSeeder::class,
            RegulationTemplateSeeder::class,
            PositionProfileTemplateSeeder::class,
            LanguageSeeder::class,
            EquipmentTypeSeeder::class,
            UserSeeder::class,
        ]);
    }
}
