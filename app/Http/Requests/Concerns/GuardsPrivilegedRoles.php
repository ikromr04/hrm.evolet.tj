<?php

namespace App\Http\Requests\Concerns;

use App\Models\User;
use App\Support\Access;
use Closure;
use Illuminate\Validation\Validator;

/**
 * Whose positions may be changed, and to what.
 *
 * Positions are an ordinary line of a card, opened by an ordinary right, with two
 * things the right does not decide. One is the single system administrator: that
 * position cannot be handed to anybody, and the account holding it cannot give it
 * up, or nobody would be left who can reach everything. The other is whose card it
 * is — somebody who may change the access table has their positions to themselves,
 * which is read from Access::rolesLockedReason.
 */
trait GuardsPrivilegedRoles
{
    /**
     * Handing the one position out, checked as the value itself so the message
     * lands on the field it was picked in.
     */
    protected function privilegedRoleGuard(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            if ($value !== Access::SOLE_ROLE) {
                return;
            }

            // Leaving it in the list of a card that already holds it is not handing
            // it out, or the account could not so much as have its surname fixed.
            $employee = $this->route('employee');

            if ($employee instanceof User && $employee->hasRole($value)) {
                return;
            }

            $fail('Системный администратор в системе только один, назначить второго нельзя.');
        };
    }

    /**
     * Whose positions this person may change at all, and what they may not do to
     * their own. Both are read from the list the form sent against what the
     * person holds now, which rules cannot see one value at a time.
     */
    protected function guardPrivilegedChanges(Validator $validator, User $employee): void
    {
        // A form that does not carry the positions is not changing them: there
        // is one window for them and others for the rest of the card.
        if (! $this->has('roles')) {
            return;
        }

        $asked = array_values((array) $this->input('roles', []));
        $held = $employee->roles->pluck('name')->all();

        sort($asked);
        sort($held);

        // Sending the same list back is not a change, or nobody could so much as
        // fix a colleague's surname on a card whose positions are not theirs.
        if ($asked === $held) {
            return;
        }

        $locked = $this->user() === null ? null : Access::rolesLockedReason($this->user(), $employee);

        if ($locked !== null) {
            $validator->errors()->add('roles', $locked);

            return;
        }

        // Nobody leaves the system without a way back in: the one position that
        // cannot be granted cannot be given up either.
        if (in_array(Access::SOLE_ROLE, array_diff($held, $asked), true) && $employee->is($this->user())) {
            $validator->errors()->add('roles', 'Нельзя снять с себя роль системного администратора.');
        }
    }
}
