<?php

namespace Tests\Feature;

use App\Models\Chat;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

class ChatFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_direct_chat_is_created_and_then_reused(): void
    {
        $owner = User::factory()->create();
        $friend = User::factory()->create();

        Passport::actingAs($owner);

        $first = $this->postJson('/api/chats/direct', [
            'user_id' => $friend->id,
        ]);

        $first->assertCreated()->assertJsonPath('created', true);

        $chatId = (int) $first->json('chat.id');

        $second = $this->postJson('/api/chats/direct', [
            'user_id' => $friend->id,
        ]);

        $second->assertOk()->assertJsonPath('created', false);
        $this->assertSame((string) $chatId, (string) $second->json('chat.id'));

        $this->assertDatabaseHas('chats', [
            'id' => $chatId,
            'type' => Chat::TYPE_DIRECT,
        ]);

        $this->assertDatabaseCount('chat_participants', 2);
    }

    public function test_group_chat_creation_and_message_read_flow(): void
    {
        $owner = User::factory()->create();
        $friendA = User::factory()->create();
        $friendB = User::factory()->create();

        Passport::actingAs($owner);

        $group = $this->postJson('/api/chats/group', [
            'title' => 'Core Team',
            'participant_ids' => [$friendA->id, $friendB->id],
        ]);

        $group->assertCreated()->assertJsonPath('chat.type', Chat::TYPE_GROUP);

        $chatId = (int) $group->json('chat.id');

        $messageResponse = $this->postJson("/api/chats/{$chatId}/messages", [
            'body' => 'Hello team!',
        ]);

        $messageResponse->assertCreated()->assertJsonPath('message.body', 'Hello team!');

        Passport::actingAs($friendA);

        $list = $this->getJson('/api/chats');
        $list->assertOk();

        $friendChat = collect($list->json('chats'))->firstWhere('id', (string) $chatId);

        $this->assertNotNull($friendChat);
        $this->assertSame(1, (int) $friendChat['unread_count']);

        $messages = $this->getJson("/api/chats/{$chatId}/messages");
        $messages->assertOk()->assertJsonCount(1, 'messages');

        $messageId = (int) $messages->json('messages.0.id');

        $markRead = $this->postJson("/api/chats/{$chatId}/read", [
            'message_id' => $messageId,
        ]);

        $markRead->assertOk()->assertJsonPath('last_read_message_id', (string) $messageId);

        $listAfterRead = $this->getJson('/api/chats');
        $listAfterRead->assertOk();

        $friendChatAfter = collect($listAfterRead->json('chats'))->firstWhere('id', (string) $chatId);
        $this->assertNotNull($friendChatAfter);
        $this->assertSame(0, (int) $friendChatAfter['unread_count']);
    }

    public function test_user_search_and_add_contact_flow(): void
    {
        $owner = User::factory()->create();
        $target = User::factory()->create([
            'nickname' => 'john_target',
            'email' => 'john.target@example.com',
        ]);

        Passport::actingAs($owner);

        $initialContacts = $this->getJson('/api/chats/users');
        $initialContacts->assertOk()->assertJsonCount(0, 'users');

        $search = $this->getJson('/api/chats/users/search?search=john_target');
        $search
            ->assertOk()
            ->assertJsonPath('users.0.id', (string) $target->id)
            ->assertJsonPath('users.0.is_contact', false);

        $add = $this->postJson('/api/chats/contacts', [
            'user_id' => $target->id,
        ]);

        $add
            ->assertCreated()
            ->assertJsonPath('contact.id', (string) $target->id)
            ->assertJsonPath('created', true);

        $contactsAfter = $this->getJson('/api/chats/users');
        $contactsAfter
            ->assertOk()
            ->assertJsonCount(1, 'users')
            ->assertJsonPath('users.0.id', (string) $target->id);

        $searchAfter = $this->getJson('/api/chats/users/search?search=john.target@example.com');
        $searchAfter
            ->assertOk()
            ->assertJsonPath('users.0.id', (string) $target->id)
            ->assertJsonPath('users.0.is_contact', true);

        $addAgain = $this->postJson('/api/chats/contacts', [
            'user_id' => $target->id,
        ]);

        $addAgain->assertOk()->assertJsonPath('created', false);
    }
}
