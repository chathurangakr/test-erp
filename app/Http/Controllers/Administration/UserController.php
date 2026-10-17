<?php

namespace App\Http\Controllers\Administration;

use App\Actions\Administration\CreateUser;
use App\Actions\Administration\UpdateUserAccess;
use App\Http\Controllers\Controller;
use App\Http\Requests\Administration\StoreUserRequest;
use App\Http\Requests\Administration\UpdateUserAccessRequest;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Users — who may sign in, and which roles they hold.
 *
 * Administrators may add an account here (the way in when self-registration is closed). There
 * is no destroy route: a user with history is deactivated, never deleted, so every document
 * keeps naming who created, approved, posted or reversed it. Only the fields an
 * administrator needs are sent to the browser — never a password hash or 2FA secret.
 */
class UserController extends Controller
{
    private const PER_PAGE = 20;

    public function index(Request $request): Response
    {
        $search = (string) $request->string('search');

        $users = User::query()
            ->with('roles:id,code,name,is_active')
            ->when($search !== '', fn ($query) => $query->where(fn ($q) => $q
                ->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")))
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (User $user): array => $this->present($user));

        return Inertia::render('administration/users/Index', [
            'users' => $users,
            'filters' => ['search' => $search],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('administration/users/Create', [
            'roles' => $this->roleOptions(),
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]);
    }

    public function store(StoreUserRequest $request, CreateUser $createUser): RedirectResponse
    {
        $user = $createUser->handle(
            $request->safe()->only(['name', 'email', 'password']),
            array_map('intval', $request->validated('role_ids')),
            $request->boolean('start_in_simple_mode'),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('User added.')]);

        return to_route('users.show', $user);
    }

    public function show(User $user): Response
    {
        $user->load('roles:id,code,name,is_active');

        return Inertia::render('administration/users/Show', [
            'user' => $this->present($user),
            'permissions' => $user->permissionCodes(),
        ]);
    }

    public function edit(User $user): Response
    {
        $user->load('roles:id');

        return Inertia::render('administration/users/Edit', [
            'user' => $this->present($user),
            'roles' => $this->roleOptions(),
        ]);
    }

    public function update(UpdateUserAccessRequest $request, User $user, UpdateUserAccess $updateAccess): RedirectResponse
    {
        $updateAccess->handle(
            $user,
            array_map('intval', $request->validated('role_ids')),
            $request->boolean('is_active'),
            $request->has('start_in_simple_mode') ? $request->boolean('start_in_simple_mode') : null,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('User access updated.')]);

        return to_route('users.show', $user);
    }

    /**
     * @return Collection<int, Role>
     */
    private function roleOptions(): Collection
    {
        return Role::query()->orderBy('name')->get(['id', 'code', 'name', 'description', 'is_active']);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'is_active' => $user->is_active,
            'start_in_simple_mode' => $user->start_in_simple_mode,
            'created_at' => $user->created_at?->toDateString(),
            'roles' => $user->roles->map(fn (Role $role): array => [
                'id' => $role->id,
                'code' => $role->code,
                'name' => $role->name,
                'is_active' => $role->is_active,
            ])->values()->all(),
        ];
    }
}
