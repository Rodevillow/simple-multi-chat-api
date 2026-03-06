<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Throwable;

class UserPayloadFactory
{
    /**
     * @return array{id: string, nickname: string, email: string, locale: string, avatar_url: string|null}
     */
    public function make(User $user): array
    {
        return [
            'id' => (string) $user->id,
            'nickname' => $user->nickname,
            'email' => $user->email,
            'locale' => $user->locale ?? 'en',
            'avatar_url' => $this->avatarUrl($user),
        ];
    }

    private function avatarUrl(User $user): ?string
    {
        if (! $user->avatar_path) {
            return null;
        }

        try {
            return Storage::disk('s3')->temporaryUrl($user->avatar_path, now()->addHours(6));
        } catch (Throwable) {
            return null;
        }
    }
}
