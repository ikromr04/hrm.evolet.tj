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
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Access roles, shown in the UI as «Роль».
 */
class RoleController extends Controller
{
    /**
     * The one position the system relies on: it can be renamed but never deleted,
     * and there is nothing to tick beside it. Everything else here is an ordinary
     * position, whatever it happens to be called.
     */
    public const PROTECTED = [Access::SOLE_ROLE];

    public function index(Request $request): Response
    {
        return Inertia::render('directories/roles', [
            // What a position may do is edited here as well, in the same dialog:
            // a new position is no use until somebody says what it opens. Only a
            // system administrator is offered it, and only they may send it.
            'sections' => Access::tree(),
            // Which fields of a card the position reads, chosen in the same dialog.
            'fields' => EmployeeFields::tree(),
            'profileFields' => EmployeeFields::tree(EmployeeFields::OWN),
            'equipmentScopes' => EquipmentAccess::tree(),
            'equipmentBlocks' => EquipmentAccess::blockTree(),
            'equipmentActions' => EquipmentAccess::actionTree(),
            'directoryLists' => Directories::viewTree(),
            'directoryEdits' => Directories::editTree(),
            // What a new position starts with, so the dialog offers the same set a
            // seeded position gets rather than a copy of it kept in the client.
            'defaults' => Access::defaults(),
            'canManageAccess' => Directories::canEdit($request->user(), 'access'),
            // Counts match the employee list the number links to: working staff only.
            // Reading a list and keeping it are two rights, so the page says
            // which one it is looking at.
            'canEdit' => Directories::canEdit($request->user(), 'roles'),
            'items' => Role::query()
                ->with('permissions:id,name')
                ->withCount(['users' => fn ($q) => $q->where('status', 'active')])
                ->orderBy('title')
                ->get()
                ->map(fn (Role $role) => [
                    'id' => $role->id,
                    'name' => $role->name,
                    'title' => $role->title,
                    'users_count' => $role->users_count,
                    'protected' => in_array($role->name, self::PROTECTED, true),
                    // Only the system administrator has nothing to tick.
                    'everything' => $role->name === Access::SOLE_ROLE,
                    'permissions' => $role->permissions->pluck('name')->values(),
                ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $role = Role::create(['name' => $this->uniqueKey($data['title']), 'title' => $data['title'], 'guard_name' => 'web']);

        // What the dialog ticked, or — when it was not offered any — looking
        // around, which every position carries.
        $role->syncPermissions(
            isset($data['permissions'])
                // Only the rights this person holds themselves, so that a new
                // position cannot be written with more in it than its author
                // has — and then worn. See Access::writable.
                ? Access::writable($request->user(), $data['permissions'], [])
                : Access::defaults()
        );
        $this->forgetCache();

        return back();
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        $data = $this->validated($request, $role);

        // The key stays: code and permissions refer to it.
        $role->update(['title' => $data['title']]);

        // The system administrator answers yes to everything through
        // Gate::before, so its rights are not a list anybody edits.
        if (isset($data['permissions']) && $role->name !== Access::SOLE_ROLE) {
            // Only the rights this person holds themselves; see Access::writable.
            $role->syncPermissions(Access::writable($request->user(), $data['permissions'], $role->permissions->pluck('name')->all()));
        }

        $this->forgetCache();

        return back();
    }

    /**
     * The name, and — for a system administrator — what the position opens.
     *
     * @return array{title: string, permissions?: list<string>}
     */
    private function validated(Request $request, ?Role $role = null): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:150', Rule::unique('roles', 'title')->ignore($role)],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string', Rule::in(Access::keys())],
        ], attributes: ['title' => 'название', 'permissions' => 'доступы']);

        // Deciding on access is its own right; for anybody without it the list
        // is not so much refused as absent, and the rights stay as they are.
        if (! Directories::canEdit($request->user(), 'access')) {
            unset($data['permissions']);
        }

        return $data;
    }

    public function destroy(Role $role): RedirectResponse
    {
        abort_if(in_array($role->name, self::PROTECTED, true), 403, 'Эту позицию нельзя удалить.');

        $role->delete();
        $this->forgetCache();

        return back();
    }

    /**
     * "Ведущий специалист" -> "vedushchiy-specialist", with a number on clashes.
     */
    private function uniqueKey(string $title): string
    {
        $base = Str::slug($title, '-', 'ru') ?: 'role';
        $key = $base;

        for ($i = 2; Role::where('name', $key)->exists(); $i++) {
            $key = "{$base}-{$i}";
        }

        return $key;
    }

    private function forgetCache(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        // And what this request had already worked out from those rights: a card
        // reads its lines once per request and files the answer, so rewriting a
        // position's rights has to drop that too.
        AccessMemo::forget();
    }
}
