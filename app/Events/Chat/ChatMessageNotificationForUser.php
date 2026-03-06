<?php

namespace App\Events\Chat;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ChatMessageNotificationForUser implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param array<string,mixed> $chat
     * @param array<string,mixed> $message
     */
    public function __construct(
        public int $userId,
        public array $chat,
        public array $message,
        public int $unreadCount
    ) {
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel("user.{$this->userId}")];
    }

    public function broadcastAs(): string
    {
        return 'chat.message.notification';
    }

    /**
     * @return array{chat: array<string,mixed>, message: array<string,mixed>, unread_count: int}
     */
    public function broadcastWith(): array
    {
        return [
            'chat' => $this->chat,
            'message' => $this->message,
            'unread_count' => $this->unreadCount,
        ];
    }
}
