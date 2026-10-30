<?php

namespace App\Http\Controllers\Directories;

use App\Http\Controllers\Controller;
use App\Support\Access;
use App\Support\AccessMemo;
use App\Support\Directories;
use App\Support\EmployeeFields;
use App\Support\EquipmentAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Who may do what: the whole picture at once, positions down the side and
 * rights across the top.
 *
 * Only a system administrator opens this page — an administrator holds every
 * right there is and still does not decide who else gets them. The two access
 * roles are shown with everything ticked and nothing to tick: they pass every
 * check through Gate::before, so the table would only be lying if it let those
 * rows be edited.
 */
class AccessController extends Controller
{
    public function index(): Response
    {
        $roles = Role::query()
            ->with('permissions:id,name')
            ->withCount(['users' => fn ($q) => $q->where('status', 'active')])
            ->orderBy('title')
            ->get();

        return Inertia::render('directories/access', [
            'sections' => Access::tree(),
            // The fields of a card are two dozen rights; the table shows how many
            // of them a position reads and opens a dialog for the list itself.
            'fields' => EmployeeFields::tree(),
            // The same lines asked about one's own card, which is a different set
            // of rights and a column of its own.
            'profileFields' => EmployeeFields::tree(EmployeeFields::OWN),
            // How much of the fleet a position sees, which is three answers and
            // a journal apiece rather than a column.
            'equipmentScopes' => EquipmentAccess::tree(),
            // And what it may change there: the blocks of a card, and the moves
            // one makes on a unit rather than on a line of it.
            'equipmentBlocks' => EquipmentAccess::blockTree(),
            'equipmentActions' => EquipmentAccess::actionTree(),
            // The reference lists, one right each: five lists kept by different
            // people, and changing one takes being able to read it.
            'directoryLists' => Directories::viewTree(),
            'directoryEdits' => Directories::editTree(),
            'roles' => $roles->map(fn (Role $role) => [
                'id' => $role->id,
                'name' => $role->name,
                'title' => $role->title,
                'users_count' => $role->users_count,
                // One position answers yes to everything whatever its rows say:
                // the system administrator, who passes through Gate::before.
                'everything' => $role->name === Access::SOLE_ROLE,
                'permissions' => $role->permissions->pluck('name')->values(),
            ]),
        ]);
    }

    /**
     * What one position may do, ticked off in the table.
     */
    public function update(Request $request, Role $role): RedirectResponse
    {
        abort_if($role->name === Access::SOLE_ROLE, 403, 'У этой позиции есть все доступы.');

        $data = $request->validate([
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', Rule::in(Access::keys())],
        ], attributes: ['permissions' => 'доступы']);

        // Only the rights this person holds themselves; see Access::writable.
        $role->syncPermissions(Access::writable($request->user(), $data['permissions'], $role->permissions->pluck('name')->all()));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        // And what this request had already worked out from those rights.
        AccessMemo::forget();

        return back();
    }
}
