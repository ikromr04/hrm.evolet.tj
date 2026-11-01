<?php

namespace App\Http\Controllers;

use App\Support\EmployeeCard;

/**
 * «Отношение к воинской обязанности»: one line, and the one document behind it
 * — the военный билет, or the page of it that says this. The terms are the
 * degree's, which is to say EmployeeCardLineController's.
 */
class EmployeeMilitaryController extends EmployeeCardLineController
{
    protected function line(): string
    {
        return 'military';
    }

    /** @return array<string, mixed> */
    protected function rules(): array
    {
        return EmployeeCard::militaryRules();
    }

    /** @return array<string, string> */
    protected function attributes(): array
    {
        return EmployeeCard::militaryAttributes();
    }
}
