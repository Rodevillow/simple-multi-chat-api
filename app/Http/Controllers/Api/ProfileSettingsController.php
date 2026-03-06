<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\UserPayloadFactory;
use Illuminate\Http\UploadedFile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProfileSettingsController extends Controller
{
    public function show(Request $request, UserPayloadFactory $userPayloadFactory): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'user' => $userPayloadFactory->make($user),
        ]);
    }

    public function update(Request $request, UserPayloadFactory $userPayloadFactory): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $data = $request->validate([
            'nickname' => [
                'required',
                'string',
                'alpha_dash',
                'min:3',
                'max:32',
                Rule::unique('users', 'nickname')->ignore($user->id),
            ],
            'locale' => ['required', Rule::in(['uk', 'en', 'ru'])],
            'avatar_path' => ['nullable', 'string', 'max:255'],
        ]);

        if (isset($data['avatar_path']) && $data['avatar_path'] !== null) {
            $avatarPath = (string) $data['avatar_path'];
            $requiredPrefix = "avatars/{$user->id}/";

            if (! str_starts_with($avatarPath, $requiredPrefix)) {
                throw ValidationException::withMessages([
                    'avatar_path' => ['Invalid avatar path.'],
                ]);
            }
        }

        $user->fill([
            'name' => $data['nickname'],
            'nickname' => $data['nickname'],
            'locale' => $data['locale'],
            'avatar_path' => $data['avatar_path'] ?? $user->avatar_path,
        ])->save();

        return response()->json([
            'message' => 'Profile settings updated.',
            'user' => $userPayloadFactory->make($user->fresh()),
        ]);
    }

    public function avatarUploadUrl(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $data = $request->validate([
            'file_name' => ['required', 'string', 'max:180'],
            'mime_type' => ['required', 'string', 'starts_with:image/'],
            'file_size' => ['required', 'integer', 'min:1', 'max:5242880'],
        ]);

        $extension = strtolower(pathinfo($data['file_name'], PATHINFO_EXTENSION));

        if ($extension === '') {
            $mimePart = Str::after($data['mime_type'], 'image/');
            $extension = $mimePart !== '' ? $mimePart : 'jpg';
        }

        $fileKey = sprintf('avatars/%s/%s.%s', $user->id, Str::uuid()->toString(), $extension);

        $signed = Storage::disk('s3')->temporaryUploadUrl($fileKey, now()->addMinutes(10), [
            'ContentType' => $data['mime_type'],
            'CacheControl' => 'public,max-age=31536000',
        ]);

        /** @var array<string, mixed> $signedHeaders */
        $signedHeaders = $signed['headers'];

        $headers = [];
        foreach ($signedHeaders as $name => $values) {
            $headers[$name] = is_array($values) ? implode(',', $values) : (string) $values;
        }

        return response()->json([
            'upload_url' => $signed['url'],
            'upload_headers' => $headers,
            'avatar_path' => $fileKey,
            'expires_in' => 600,
        ]);
    }

    public function avatarUpload(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $data = $request->validate([
            'avatar' => ['required', 'file', 'image', 'max:5120'],
        ]);

        /** @var UploadedFile $avatar */
        $avatar = $data['avatar'];
        $extension = strtolower($avatar->getClientOriginalExtension());

        if ($extension === '') {
            $guessed = $avatar->guessExtension();
            $extension = is_string($guessed) && $guessed !== '' ? strtolower($guessed) : 'jpg';
        }

        $fileKey = sprintf('avatars/%s/%s.%s', $user->id, Str::uuid()->toString(), $extension);
        $realPath = $avatar->getRealPath();

        if (! is_string($realPath) || $realPath === '') {
            throw ValidationException::withMessages([
                'avatar' => ['Unable to read uploaded avatar.'],
            ]);
        }

        $stream = fopen($realPath, 'r');

        if ($stream === false) {
            throw ValidationException::withMessages([
                'avatar' => ['Unable to read uploaded avatar.'],
            ]);
        }

        try {
            $uploaded = Storage::disk('s3')->put($fileKey, $stream, [
                'ContentType' => $avatar->getMimeType() ?: 'application/octet-stream',
                'CacheControl' => 'public,max-age=31536000',
            ]);
        } finally {
            fclose($stream);
        }

        if (! $uploaded) {
            throw ValidationException::withMessages([
                'avatar' => ['Unable to upload avatar to storage.'],
            ]);
        }

        return response()->json([
            'avatar_path' => $fileKey,
            'message' => 'Avatar uploaded via server fallback.',
        ]);
    }
}
