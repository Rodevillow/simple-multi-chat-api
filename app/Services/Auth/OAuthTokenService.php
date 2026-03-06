<?php

namespace App\Services\Auth;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class OAuthTokenService
{
    /**
     * @return array{access_token: string, refresh_token: string, token_type: string, expires_in: int}
     */
    public function issuePasswordToken(string $email, string $password): array
    {
        return $this->issueToken([
            'grant_type' => 'password',
            'username' => $email,
            'password' => $password,
        ]);
    }

    /**
     * @return array{access_token: string, refresh_token: string, token_type: string, expires_in: int}
     */
    public function issueRefreshToken(string $refreshToken): array
    {
        return $this->issueToken([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ]);
    }

    /**
     * @param array<string, string> $payload
     * @return array{access_token: string, refresh_token: string, token_type: string, expires_in: int}
     */
    private function issueToken(array $payload): array
    {
        $clientId = (string) config('services.passport.password_client_id');
        $clientSecret = (string) config('services.passport.password_client_secret');

        if ($clientId === '' || $clientSecret === '') {
            throw ValidationException::withMessages([
                'auth' => ['OAuth client is not configured. Set PASSPORT_PASSWORD_CLIENT_ID and PASSPORT_PASSWORD_CLIENT_SECRET.'],
            ]);
        }

        $oauthRequest = Request::create('/oauth/token', 'POST', [
            ...$payload,
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'scope' => '',
        ]);

        $oauthRequest->headers->set('Accept', 'application/json');

        $response = app()->handle($oauthRequest);
        $statusCode = $response->getStatusCode();

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($response->getContent(), true) ?? [];

        if ($statusCode >= 400) {
            $message = $decoded['message'] ?? $decoded['error_description'] ?? 'Token issue failed.';

            Log::warning('OAuth token issue failed', [
                'status' => $statusCode,
                'error' => $decoded,
            ]);

            throw ValidationException::withMessages([
                'auth' => [$message],
            ]);
        }

        return [
            'access_token' => (string) ($decoded['access_token'] ?? ''),
            'refresh_token' => (string) ($decoded['refresh_token'] ?? ''),
            'token_type' => (string) ($decoded['token_type'] ?? 'Bearer'),
            'expires_in' => (int) ($decoded['expires_in'] ?? 0),
        ];
    }
}
