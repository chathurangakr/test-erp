<?php

namespace App\Http\Resources\V1;

use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * The signed-in user, as the mobile app sees them.
 *
 * Only safe fields — never the password hash, remember token or two-factor secrets. The
 * permission list is for the app's UI; every request is still authorized on the server.
 *
 * @mixin User
 */
class UserResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'is_active' => $this->is_active,
            // The person's chosen language, so the app starts in it.
            'locale' => $this->locale ?? config('app.locale'),
            'roles' => $this->roles
                ->where('is_active', true)
                ->map(fn (Role $role): array => ['code' => $role->code, 'name' => $role->name])
                ->values(),
            'permissions' => $this->permissionCodes(),
        ];
    }
}
