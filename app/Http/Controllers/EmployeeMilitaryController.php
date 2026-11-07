<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * «Отношение к воинской обязанности»: a list of them, each one line and the
 * page of the военный билет that says it. The terms are the degrees', which is
 * to say EmployeePaperListController's.
 */
class EmployeeMilitaryController extends EmployeePaperListController
{
    protected function records(User $employee): HasMany
    {
        return $employee->militaryRecords();
    }

    protected function flag(): string
    {
        return 'has_military_records';
    }

    protected function right(): string
    {
        return 'military';
    }

    protected function named(): string
    {
        return 'отношение к воинской обязанности';
    }

    protected function routes(): string
    {
        return 'military_records';
    }
}
