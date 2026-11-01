<?php

namespace App\Http\Requests\Concerns;

use App\Models\User;
use App\Support\Access;
use Closure;
use Illuminate\Validation\Validator;

/**
 * Whose role may be changed, and to what.
 *
 * A role is an ordinary line of a card with two things the right to that line
 * does not decide. One is the system administrator: it is handed to nobody, not
 * even by the account that holds it, and that account cannot give it up either —
 * there would be nobody left who can reach everything. The other is
 * «HR-специалист», which passes every check in the same way; personnel who could
 * appoint personnel would be the whole of the access system in one person's gift,
 * so that one is the system administrator's to give.
 */
trait GuardsPrivilegedRoles
{
    /**
     * A role being handed out, checked as the value itself so the message lands
     * on the field it was picked in.
     */
    protected function privilegedRoleGuard(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            // Leaving a role on a card that already holds it is not handing it
            // out, or an account could not so much as have its surname fixed.
            $employee = $this->route('employee');

            if ($employee instanceof User && $employee->hasRole($value)) {
                return;
            }

            if ($value === Access::SOLE_ROLE) {
                $fail('Роль системного администратора не выдаётся: она одна и уже занята.');

                return;
            }

            if ($value === Access::HR && ! $this->user()?->hasRole(Access::SOLE_ROLE)) {
                $fail('Роль HR-специалиста выдаёт только системный администратор.');
            }
        };
    }

    /**
     * Whose role this person may change at all, and what they may not do to their
     * own. Both are read from the list the form sent against what the person holds
     * now, which rules cannot see one value at a time.
     */
    protected function guardPrivilegedChanges(Validator $validator, User $employee): void
    {
        // A form that does not carry the role is not changing it: there is one
        // window for it and others for the rest of the card.
        if (! $this->has('roles')) {
            return;
        }

        $asked = array_values((array) $this->input('roles', []));
        $held = $employee->roles->pluck('name')->all();

        sort($asked);
        sort($held);

        // Sending the same role back is not a change, or nobody could so much as
        // fix a colleague's surname on a card whose role is not theirs.
        if ($asked === $held) {
            return;
        }

        $locked = Access::rolesLockedReason($employee);

        if ($locked !== null) {
            $validator->errors()->add('roles', $locked);
        }
    }
}
