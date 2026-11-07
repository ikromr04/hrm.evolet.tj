<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * «Учёная степень, звание»: a list of them, each one line and the diploma
 * behind it. The terms are the block above's — see EmployeePaperListController.
 */
class EmployeeDegreeController extends EmployeePaperListController
{
    protected function records(User $employee): HasMany
    {
        return $employee->degrees();
    }

    protected function flag(): string
    {
        return 'has_degrees';
    }

    protected function right(): string
    {
        return 'degree';
    }

    protected function named(): string
    {
        return 'учёная степень, звание';
    }

    protected function routes(): string
    {
        return 'degrees';
    }
}
