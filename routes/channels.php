<?php

use App\Models\ChatParticipant;
use App\Models\User;
use App\Services\Auth\UserPayloadFactory;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('user.{id}', function (User $user, int $id): bool {
    return $user->id === $id;
});

Broadcast::channel('chat.{chatId}', function (User $user, int $chatId): bool {
    return ChatParticipant::query()
        ->where('chat_id', $chatId)
        ->where('user_id', $user->id)
        ->exists();
});

Broadcast::channel('chat-presence.{chatId}', function (User $user, int $chatId): array|bool {
    $isParticipant = ChatParticipant::query()
        ->where('chat_id', $chatId)
        ->where('user_id', $user->id)
        ->exists();

    if (! $isParticipant) {
        return false;
    }

    /** @var UserPayloadFactory $payloadFactory */
    $payloadFactory = app(UserPayloadFactory::class);
    $member = $payloadFactory->make($user);

    return [
        'id' => $member['id'],
        'nickname' => $member['nickname'],
        'avatar_url' => $member['avatar_url'],
    ];
});

Broadcast::channel('global-online', function (User $user): array {
    /** @var UserPayloadFactory $payloadFactory */
    $payloadFactory = app(UserPayloadFactory::class);
    $member = $payloadFactory->make($user);

    return [
        'id' => $member['id'],
        'nickname' => $member['nickname'],
        'avatar_url' => $member['avatar_url'],
    ];
});
