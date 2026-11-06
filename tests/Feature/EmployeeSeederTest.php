<?php

namespace Tests\Feature;

use App\Enums\Gender;
use App\Models\Employee;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class EmployeeSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_creates_employees_with_full_personal_sheets()
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(120, Employee::count());
        $this->assertSame(120, User::count());
        $this->assertSame(0, Employee::whereNull('user_id')->count());

        Employee::with(['relatives', 'educations', 'languages', 'workExperiences'])->get()->each(function (Employee $employee) {
            $this->assertNotEmpty($employee->educations, "{$employee->full_name} has no education");
            $this->assertTrue($employee->relatives->contains('is_emergency_contact', true), "{$employee->full_name} has no SOS contact");
            $this->assertTrue($employee->languages->contains('code', 'tg'));
            $this->assertNull($employee->workExperiences->last()->ended_at, 'The latest job should be current');
            $this->assertSame('Эволет', $employee->workExperiences->last()->organization);

            if ($employee->gender === Gender::Female) {
                $this->assertNull($employee->military_rank_category);
            }
        });
    }

    public function test_fixed_accounts_can_log_in()
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertTrue(Auth::validate(['email' => 'd.nazarova@evolet.test', 'password' => 'password']));
        $this->assertSame('Назарова', User::firstWhere('email', 'd.nazarova@evolet.test')->employee->last_name);
    }

    public function test_seeder_can_run_twice_without_duplicating_fixed_accounts()
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(1, User::where('email', 'd.nazarova@evolet.test')->count());
        $this->assertSame(1, Employee::where('last_name', 'Назарова')->where('first_name', 'Дилноза')->count());
    }
}
