<?php

namespace App\Events\Chat;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ChatCreatedForUser implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param array<string,mixed> $chat
     */
    public function __construct(public int $userId, public array $chat)
    {
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel("user.{$this->userId}")];
    }

    public function broadcastAs(): string
    {
        return 'chat.created';
    }

    /**
     * @return array{chat: array<string,mixed>}
     */
    public function broadcastWith(): array
    {
        return [
            'chat' => $this->chat,
        ];
    }
}
