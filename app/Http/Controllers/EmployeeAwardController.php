<?php

namespace App\Http\Controllers;

use App\Support\EmployeeCard;

/**
 * «Государственные награды, поощрения»: one line, and the one document behind
 * it — a decree, a certificate, a letter of thanks. The terms are the degree's,
 * which is to say EmployeeCardLineController's.
 */
class EmployeeAwardController extends EmployeeCardLineController
{
    protected function line(): string
    {
        return 'award';
    }

    /** @return array<string, mixed> */
    protected function rules(): array
    {
        return EmployeeCard::awardRules();
    }

    /** @return array<string, string> */
    protected function attributes(): array
    {
        return EmployeeCard::awardAttributes();
    }
}
