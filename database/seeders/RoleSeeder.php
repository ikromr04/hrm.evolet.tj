<?php

namespace Database\Seeders;

use App\Support\Access;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The four roles there are, with nothing hung on them.
 *
 * There used to be a list of them here, twenty-five titles long, because roles
 * were a directory the company kept and a seeder had to invent something for a
 * developer to look at. They are now the four named in App\Support\Access, and
 * that catalogue is the only list of them: two of the four pass every check
 * through Gate::before, the other two are shown a site that is not open to them
 * yet, and neither of those answers is a row anybody ticks.
 *
 * The rights are still made, deliberately. They outlived the ticks — the
 * employee card is built out of that catalogue — and they are what the ticks
 * will go back onto on the day somebody settles what a head of department may
 * read.
 */
class RoleSeeder extends Seeder
{
    public function run(): void
    {
        // Nothing is given any rights below, but the card is built out of the
        // catalogue, so the database goes on knowing the same list as the code.
        $this->call(PermissionSeeder::class);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (Access::ROLES as $name => $title) {
            // Written over on every run, titles and all: this is the demo, and
            // its lists are put back the way the code has them. Where the rows
            // are the company's own a rename is theirs to keep, and that is
            // ProductionSeeder's business rather than this one's.
            Role::updateOrCreate(['name' => $name, 'guard_name' => 'web'], ['title' => $title]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
