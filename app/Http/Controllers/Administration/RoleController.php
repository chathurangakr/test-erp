<?php

namespace App\Http\Controllers\Administration;

use App\Actions\Administration\DeleteRole;
use App\Actions\Administration\SaveRole;
use App\Enums\Permission as PermissionCode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Administration\SaveRoleRequest;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Roles and their permission matrix.
 *
 * Permissions themselves are not editable here — they are application capabilities defined
 * in code. A role may be deleted only while nobody holds it; otherwise it is deactivated.
 */
class RoleController extends Controller
{
    public function index(): Response
    {
        $roles = Role::query()
            ->withCount(['users', 'permissions'])
            ->orderByRaw('code = ? desc', [Role::ADMINISTRATOR])
            ->orderBy('name')
            ->get()
            ->map(fn (Role $role): array => [
                'id' => $role->id,
                'code' => $role->code,
                'name' => $role->name,
                'description' => $role->description,
                'is_active' => $role->is_active,
                'is_administrator' => $role->isAdministrator(),
                'users_count' => $role->users_count,
                'permissions_count' => $role->permissions_count,
            ]);

        return Inertia::render('administration/roles/Index', [
            'roles' => $roles,
            'permissionTotal' => Permission::query()->count(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('administration/roles/Create', [
            'groups' => $this->permissionGroups(),
        ]);
    }

    public function store(SaveRoleRequest $request, SaveRole $saveRole): RedirectResponse
    {
        $role = $saveRole->handle(null, $request->validated(), array_map('intval', $request->validated('permission_ids')));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Role created.')]);

        return to_route('roles.show', $role);
    }

    public function show(Role $role): Response
    {
        $role->load(['permissions:id', 'users:id,name,email,is_active']);

        return Inertia::render('administration/roles/Show', [
            'role' => $this->present($role),
            'users' => $role->users->map(fn ($user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_active' => $user->is_active,
            ])->values(),
            'groups' => $this->permissionGroups(),
        ]);
    }

    public function edit(Role $role): Response
    {
        $role->load('permissions:id');

        return Inertia::render('administration/roles/Edit', [
            'role' => $this->present($role),
            'groups' => $this->permissionGroups(),
        ]);
    }

    public function update(SaveRoleRequest $request, Role $role, SaveRole $saveRole): RedirectResponse
    {
        $saveRole->handle($role, $request->validated(), array_map('intval', $request->validated('permission_ids')));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Role updated.')]);

        return to_route('roles.show', $role);
    }

    public function destroy(Role $role, DeleteRole $deleteRole): RedirectResponse
    {
        $deleteRole->handle($role);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Role deleted.')]);

        return to_route('roles.index');
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Role $role): array
    {
        return [
            'id' => $role->id,
            'code' => $role->code,
            'name' => $role->name,
            'description' => $role->description,
            'is_active' => $role->is_active,
            'is_administrator' => $role->isAdministrator(),
            'permission_ids' => $role->permissions->pluck('id')->values(),
        ];
    }

    /**
     * The catalogue, grouped by module in a fixed order, for the permission matrix.
     *
     * @return list<array{module: string, permissions: list<array{id: int, code: string, name: string}>}>
     */
    private function permissionGroups(): array
    {
        $byModule = Permission::query()->orderBy('id')->get(['id', 'code', 'name', 'module'])->groupBy('module');

        $groups = [];

        foreach (PermissionCode::moduleOrder() as $module) {
            if (! $byModule->has($module)) {
                continue;
            }

            $groups[] = [
                'module' => $module,
                'permissions' => $byModule[$module]->map(fn (Permission $permission): array => [
                    'id' => $permission->id,
                    'code' => $permission->code,
                    'name' => $permission->name,
                ])->values()->all(),
            ];
        }

        return $groups;
    }
}
