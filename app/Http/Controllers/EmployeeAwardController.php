<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * «Государственные награды, поощрения»: a list of them, each one line and the
 * paper behind it — a decree, a certificate, a letter of thanks. The terms are
 * the degrees', which is to say EmployeePaperListController's.
 */
class EmployeeAwardController extends EmployeePaperListController
{
    protected function records(User $employee): HasMany
    {
        return $employee->awards();
    }

    protected function flag(): string
    {
        return 'has_awards';
    }

    protected function right(): string
    {
        return 'award';
    }

    protected function named(): string
    {
        return 'награда или поощрение';
    }

    protected function routes(): string
    {
        return 'awards';
    }
}
