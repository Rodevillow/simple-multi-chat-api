<?php

namespace App\Services\Chat;

use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\ChatParticipant;
use App\Models\User;
use App\Services\Auth\UserPayloadFactory;

class ChatPayloadFactory
{
    public function __construct(private readonly UserPayloadFactory $userPayloadFactory)
    {
    }

    /**
     * @return array{id: string, nickname: string, email: string, avatar_url: string|null}
     */
    public function makeMember(User $user): array
    {
        $payload = $this->userPayloadFactory->make($user);

        return [
            'id' => $payload['id'],
            'nickname' => $payload['nickname'],
            'email' => $payload['email'],
            'avatar_url' => $payload['avatar_url'],
        ];
    }

    /**
     * @return array{
     *   id: string,
     *   chat_id: string,
     *   kind: string,
     *   body: string|null,
     *   meta: array<string,mixed>|null,
     *   created_at: string|null,
     *   sender: array{id: string, nickname: string, email: string, avatar_url: string|null},
     *   is_mine: bool
     * }
     */
    public function makeMessage(ChatMessage $message, User $viewer): array
    {
        $sender = $message->sender;

        if (! $sender) {
            /** @var User $fallbackSender */
            $fallbackSender = User::query()->findOrFail($message->sender_id);
            $sender = $fallbackSender;
        }

        return [
            'id' => (string) $message->id,
            'chat_id' => (string) $message->chat_id,
            'kind' => $message->kind,
            'body' => $message->body,
            'meta' => $message->meta,
            'created_at' => $message->created_at?->toIso8601String(),
            'sender' => $this->makeMember($sender),
            'is_mine' => (int) $message->sender_id === (int) $viewer->id,
        ];
    }

    /**
     * @return array{
     *   id: string,
     *   type: string,
     *   title: string,
     *   subtitle: string,
     *   avatar_url: string|null,
     *   participants: list<array{id: string, nickname: string, email: string, avatar_url: string|null}>,
     *   participants_count: int,
     *   unread_count: int,
     *   last_message_preview: string|null,
     *   last_message_at: string|null,
     *   my_role: string|null,
     *   my_last_read_message_id: string|null,
     *   direct_target: array{id: string, nickname: string, email: string, avatar_url: string|null}|null,
     *   last_message: array<string,mixed>|null
     * }
     */
    public function makeChat(
        Chat $chat,
        User $viewer,
        ?ChatParticipant $myParticipant,
        int $unreadCount,
        ?ChatMessage $lastMessage = null
    ): array {
        $chat->loadMissing('participants.user');

        $participants = $chat->participants
            ->filter(static fn (ChatParticipant $participant): bool => $participant->user !== null)
            ->map(fn (ChatParticipant $participant): array => $this->makeMember($participant->user))
            ->values();

        $directTarget = null;
        $title = (string) ($chat->title ?? '');
        $subtitle = '';
        $avatarUrl = null;

        if ($chat->type === Chat::TYPE_DIRECT) {
            $targetParticipant = $chat->participants->first(
                fn (ChatParticipant $participant): bool => (int) $participant->user_id !== (int) $viewer->id
            );

            if ($targetParticipant?->user) {
                $directTarget = $this->makeMember($targetParticipant->user);
                $title = $targetParticipant->user->nickname;
                $subtitle = $targetParticipant->user->email;
                $avatarUrl = $directTarget['avatar_url'];
            }
        }

        if ($chat->type === Chat::TYPE_GROUP) {
            if ($title === '') {
                $title = 'Group chat';
            }

            $subtitle = sprintf('%d members', $participants->count());
        }

        if ($title === '') {
            $title = 'Chat';
        }

        return [
            'id' => (string) $chat->id,
            'type' => $chat->type,
            'title' => $title,
            'subtitle' => $subtitle,
            'avatar_url' => $avatarUrl,
            'participants' => $participants->all(),
            'participants_count' => $participants->count(),
            'unread_count' => $unreadCount,
            'last_message_preview' => $chat->last_message_preview,
            'last_message_at' => $chat->last_message_at?->toIso8601String(),
            'my_role' => $myParticipant?->role,
            'my_last_read_message_id' => $myParticipant?->last_read_message_id !== null
                ? (string) $myParticipant->last_read_message_id
                : null,
            'direct_target' => $directTarget,
            'last_message' => $lastMessage ? $this->makeMessage($lastMessage, $viewer) : null,
        ];
    }
}
