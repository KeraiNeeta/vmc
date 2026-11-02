<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\NewAccessToken;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    public function store(LoginRequest $request, AuditLogger $auditLogger): JsonResponse
    {
        $credentials = $request->validated();
        $user = User::query()->where('email', $credentials['email'])->first();

        if (! $user || ! $user->is_active || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => trans('auth.failed'),
            ]);
        }

        $newToken = DB::transaction(function () use ($user, $credentials, $auditLogger): NewAccessToken {
            $user = User::query()->lockForUpdate()->find($user->getKey());

            if (! $user || ! $user->is_active || ! Hash::check($credentials['password'], $user->password)) {
                throw ValidationException::withMessages([
                    'email' => trans('auth.failed'),
                ]);
            }

            $user->forceFill(['last_login_at' => now()])->save();
            $newToken = $user->createToken($credentials['device_name'] ?? 'emergency-dispatch-client');
            $auditLogger->record($user, 'auth.login', $user);

            return $newToken;
        });

        return response()->json([
            'data' => [
                'token' => $newToken->plainTextToken,
                'token_type' => 'Bearer',
                'user' => UserResource::make($user->refresh()->load('roles.permissions'))->resolve($request),
            ],
        ]);
    }

    public function show(Request $request): UserResource
    {
        return UserResource::make($request->user()->load([
            'roles.permissions',
            'fireStations',
            'managedFireStations',
        ]));
    }

    public function destroy(Request $request, AuditLogger $auditLogger): Response
    {
        $user = $request->user();
        $token = $user->currentAccessToken();

        DB::transaction(function () use ($user, $token, $auditLogger): void {
            if ($token instanceof PersonalAccessToken) {
                $token->delete();
            }

            $auditLogger->record($user, 'auth.logout', $user);
        });

        return response()->noContent();
    }
}
