<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Passport;
use Tests\TestCase;

class ProfileSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_get_profile_settings(): void
    {
        $user = User::factory()->create([
            'nickname' => 'my_user',
            'locale' => 'uk',
        ]);

        Passport::actingAs($user);

        $response = $this->getJson('/api/settings/profile');

        $response->assertOk()->assertJsonPath('user.nickname', 'my_user')->assertJsonPath('user.locale', 'uk');
    }

    public function test_authenticated_user_can_update_profile_settings(): void
    {
        $user = User::factory()->create([
            'nickname' => 'old_name',
            'locale' => 'en',
        ]);

        Passport::actingAs($user);

        $response = $this->putJson('/api/settings/profile', [
            'nickname' => 'new_name',
            'locale' => 'ru',
            'avatar_path' => null,
        ]);

        $response->assertOk()->assertJsonPath('user.nickname', 'new_name')->assertJsonPath('user.locale', 'ru');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'nickname' => 'new_name',
            'locale' => 'ru',
        ]);
    }

    public function test_authenticated_user_can_upload_avatar_via_backend_fallback(): void
    {
        $user = User::factory()->create();
        Passport::actingAs($user);
        Storage::fake('s3');

        $response = $this->post(
            '/api/settings/profile/avatar-upload',
            ['avatar' => UploadedFile::fake()->image('avatar.jpg', 320, 320)],
            ['Accept' => 'application/json']
        );

        $response->assertOk()->assertJsonStructure(['avatar_path']);

        $path = (string) $response->json('avatar_path');

        $this->assertStringStartsWith("avatars/{$user->id}/", $path);
        Storage::disk('s3')->assertExists($path);
    }
}
