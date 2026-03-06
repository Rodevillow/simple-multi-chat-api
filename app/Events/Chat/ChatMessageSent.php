<?php

namespace App\Events\Chat;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ChatMessageSent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param array<string,mixed> $message
     */
    public function __construct(public int $chatId, public array $message)
    {
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel("chat.{$this->chatId}")];
    }

    public function broadcastAs(): string
    {
        return 'chat.message.sent';
    }

    /**
     * @return array{chat_id: string, message: array<string,mixed>}
     */
    public function broadcastWith(): array
    {
        return [
            'chat_id' => (string) $this->chatId,
            'message' => $this->message,
        ];
    }
}
