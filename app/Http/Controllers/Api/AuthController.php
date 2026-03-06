<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\OAuthTokenService;
use App\Services\Auth\UserPayloadFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rules\Password as PasswordRule;

class AuthController extends Controller
{
    public function register(
        Request $request,
        OAuthTokenService $tokenService,
        UserPayloadFactory $userPayloadFactory
    ): JsonResponse
    {
        $data = $request->validate([
            'nickname' => ['required', 'string', 'alpha_dash', 'min:3', 'max:32', 'unique:users,nickname'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)->letters()->mixedCase()->numbers()],
        ]);

        $user = User::query()->create([
            'name' => $data['nickname'],
            'nickname' => $data['nickname'],
            'email' => $data['email'],
            'locale' => 'en',
            'password' => $data['password'],
        ]);

        $tokenPayload = $tokenService->issuePasswordToken($data['email'], $data['password']);

        return response()->json([
            ...$tokenPayload,
            'user' => $userPayloadFactory->make($user),
        ], 201);
    }

    public function login(
        Request $request,
        OAuthTokenService $tokenService,
        UserPayloadFactory $userPayloadFactory
    ): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::query()->where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Invalid credentials.'],
            ]);
        }

        $tokenPayload = $tokenService->issuePasswordToken($data['email'], $data['password']);

        return response()->json([
            ...$tokenPayload,
            'user' => $userPayloadFactory->make($user),
        ]);
    }

    public function refresh(Request $request, OAuthTokenService $tokenService): JsonResponse
    {
        $data = $request->validate([
            'refresh_token' => ['required', 'string'],
        ]);

        $tokenPayload = $tokenService->issueRefreshToken($data['refresh_token']);

        return response()->json($tokenPayload);
    }

    public function me(Request $request, UserPayloadFactory $userPayloadFactory): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'user' => $userPayloadFactory->make($user),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()?->token();

        if ($token) {
            DB::table('oauth_refresh_tokens')
                ->where('access_token_id', $token->id)
                ->update(['revoked' => true]);

            $token->revoke();
        }

        return response()->json([
            'message' => 'Logged out.',
        ]);
    }
}
