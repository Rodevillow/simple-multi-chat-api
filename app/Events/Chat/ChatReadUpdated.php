<?php

namespace App\Events\Chat;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ChatReadUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public int $chatId, public int $userId, public ?int $lastReadMessageId)
    {
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel("chat.{$this->chatId}")];
    }

    public function broadcastAs(): string
    {
        return 'chat.read.updated';
    }

    /**
     * @return array{chat_id: string, user_id: string, last_read_message_id: string|null}
     */
    public function broadcastWith(): array
    {
        return [
            'chat_id' => (string) $this->chatId,
            'user_id' => (string) $this->userId,
            'last_read_message_id' => $this->lastReadMessageId !== null ? (string) $this->lastReadMessageId : null,
        ];
    }
}
