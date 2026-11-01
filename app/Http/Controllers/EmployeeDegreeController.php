<?php

namespace App\Http\Controllers;

use App\Support\EmployeeCard;

/**
 * «Учёная степень, звание»: one line, and the one document behind it. The
 * terms are the block above's — see EmployeeCardLineController.
 */
class EmployeeDegreeController extends EmployeeCardLineController
{
    protected function line(): string
    {
        return 'degree';
    }

    /** @return array<string, mixed> */
    protected function rules(): array
    {
        return EmployeeCard::degreeRules();
    }

    /** @return array<string, string> */
    protected function attributes(): array
    {
        return EmployeeCard::degreeAttributes();
    }
}
