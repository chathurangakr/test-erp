<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Api\IssueApiToken;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\LoginRequest;
use App\Http\Resources\V1\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Device sign-in and sign-out.
 *
 * There is no registration endpoint: mobile users sign in with accounts that already exist
 * in the ERP.
 */
class AuthController extends Controller
{
    public function login(LoginRequest $request, IssueApiToken $issue): JsonResponse
    {
        $token = $issue->handle(
            (string) $request->validated('email'),
            (string) $request->validated('password'),
            (string) $request->validated('device_name'),
            $request->validated('code'),
            $request->validated('recovery_code'),
        );

        $user = $token->accessToken->tokenable->load('roles');

        return response()->json([
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $token->accessToken->expires_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'user' => (new UserResource($user))->resolve($request),
        ], 201);
    }

    /**
     * Revokes only the token used for this request — signs out this device.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => __('Signed out of this device.')]);
    }

    /**
     * Revokes every API token the user holds — signs out all devices.
     */
    public function logoutAll(Request $request): JsonResponse
    {
        $request->user()->tokens()->delete();

        return response()->json(['message' => __('Signed out of all devices.')]);
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user()->load('roles'));
    }
}
