<?php

namespace App\Http\Controllers;

use App\Models\PermissionOverride;
use App\Models\User;
use App\Support\Access;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * A right given to, or taken from, one colleague in particular.
 *
 * Rights normally come with a position. An exception is for the case the
 * positions cannot express — one person who needs the journal, one who must not
 * see private data — and it beats the position either way, so this is the only
 * place that has to be checked to explain why they, and not their neighbour,
 * can do a thing. Handing the exception out is the system administrator's.
 */
class EmployeeAccessController extends Controller
{
    public function __invoke(Request $request, User $employee): RedirectResponse
    {
        $data = $request->validate([
            'permission' => ['required', 'string', Rule::in(Access::keys())],
            // Yes: on top of the position. No: taken from it. Nothing at all:
            // back to whatever the position says.
            'allowed' => ['present', 'nullable', 'boolean'],
        ], attributes: ['permission' => 'доступ', 'allowed' => 'решение']);

        // Nobody hands out what they have not got: without this, whoever may
        // decide on access decides on their own, and one request per right
        // turns the catalogue into a full administrator. Taking a right away
        // is left alone — that way lies no escalation.
        abort_if(
            $data['allowed'] === true && ! Access::mayGrant($request->user(), $data['permission']),
            403,
            'Этот доступ нельзя выдать: у вас его нет.',
        );

        if ($data['allowed'] === null) {
            $employee->permissionOverrides()->where('permission', $data['permission'])->delete();

            return back();
        }

        PermissionOverride::updateOrCreate(
            ['user_id' => $employee->id, 'permission' => $data['permission']],
            ['allowed' => $data['allowed']],
        );

        return back();
    }
}
