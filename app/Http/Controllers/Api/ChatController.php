<?php

namespace App\Http\Controllers\Api;

use App\Events\Chat\ChatCreatedForUser;
use App\Events\Chat\ChatMessageNotificationForUser;
use App\Events\Chat\ChatMessageSent;
use App\Events\Chat\ChatReadUpdated;
use App\Http\Controllers\Controller;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\ChatParticipant;
use App\Models\User;
use App\Services\Chat\ChatPayloadFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Throwable;

class ChatController extends Controller
{
    public function users(Request $request, ChatPayloadFactory $payloadFactory): JsonResponse
    {
        $viewer = $this->currentUser($request);
        $search = Str::lower(trim((string) $request->query('search', '')));
        $contactIds = DB::table('user_contacts')
            ->where('user_id', $viewer->id)
            ->pluck('contact_user_id');

        $query = User::query()
            ->whereIn('id', $contactIds)
            ->orderBy('nickname')
            ->limit(100);

        if ($search !== '') {
            $query->where(function ($builder) use ($search): void {
                $builder
                    ->whereRaw('LOWER(nickname) LIKE ?', ["%{$search}%"])
                    ->orWhereRaw('LOWER(email) LIKE ?', ["%{$search}%"]);
            });
        }

        $users = $query->get();

        return response()->json([
            'users' => $users
                ->map(fn (User $user): array => $payloadFactory->makeMember($user))
                ->values()
                ->all(),
        ]);
    }

    public function searchUsers(Request $request, ChatPayloadFactory $payloadFactory): JsonResponse
    {
        $viewer = $this->currentUser($request);
        $search = Str::lower(trim((string) $request->query('search', '')));

        if ($search === '') {
            return response()->json([
                'users' => [],
            ]);
        }

        $contactIds = DB::table('user_contacts')
            ->where('user_id', $viewer->id)
            ->pluck('contact_user_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        $contactLookup = array_fill_keys($contactIds, true);

        $users = User::query()
            ->where('id', '!=', $viewer->id)
            ->where(function ($builder) use ($search): void {
                $builder
                    ->whereRaw('LOWER(nickname) LIKE ?', ["%{$search}%"])
                    ->orWhereRaw('LOWER(email) LIKE ?', ["%{$search}%"]);
            })
            ->orderBy('nickname')
            ->limit(20)
            ->get();

        return response()->json([
            'users' => $users
                ->map(function (User $user) use ($payloadFactory, $contactLookup): array {
                    $member = $payloadFactory->makeMember($user);
                    $member['is_contact'] = isset($contactLookup[(int) $user->id]);

                    return $member;
                })
                ->values()
                ->all(),
        ]);
    }

    public function addContact(Request $request, ChatPayloadFactory $payloadFactory): JsonResponse
    {
        $viewer = $this->currentUser($request);

        $data = $request->validate([
            'user_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id'),
                Rule::notIn([$viewer->id]),
            ],
        ]);

        $contactUserId = (int) $data['user_id'];

        /** @var User $contact */
        $contact = User::query()->findOrFail($contactUserId);

        $alreadyExists = DB::table('user_contacts')
            ->where('user_id', $viewer->id)
            ->where('contact_user_id', $contactUserId)
            ->exists();

        if (! $alreadyExists) {
            DB::table('user_contacts')->insert([
                'user_id' => $viewer->id,
                'contact_user_id' => $contactUserId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return response()->json([
            'contact' => $payloadFactory->makeMember($contact),
            'created' => ! $alreadyExists,
        ], $alreadyExists ? 200 : 201);
    }

    public function index(Request $request, ChatPayloadFactory $payloadFactory): JsonResponse
    {
        $viewer = $this->currentUser($request);
        $search = Str::lower(trim((string) $request->query('search', '')));

        $query = Chat::query()
            ->whereHas('participants', fn ($builder) => $builder->where('user_id', $viewer->id))
            ->with([
                'participants.user',
                'messages' => fn ($builder) => $builder->latest('id')->limit(1),
            ])
            ->orderByRaw('COALESCE(last_message_at, created_at) DESC');

        if ($search !== '') {
            $query->where(function ($builder) use ($search): void {
                $builder
                    ->whereRaw('LOWER(title) LIKE ?', ["%{$search}%"])
                    ->orWhereHas('participants.user', function ($userQuery) use ($search): void {
                        $userQuery
                            ->whereRaw('LOWER(nickname) LIKE ?', ["%{$search}%"])
                            ->orWhereRaw('LOWER(email) LIKE ?', ["%{$search}%"]);
                    });
            });
        }

        $chats = $query->get();

        $myParticipants = ChatParticipant::query()
            ->where('user_id', $viewer->id)
            ->whereIn('chat_id', $chats->pluck('id'))
            ->get()
            ->keyBy('chat_id');

        $items = $chats->map(function (Chat $chat) use ($viewer, $payloadFactory, $myParticipants): array {
            /** @var ChatParticipant|null $myParticipant */
            $myParticipant = $myParticipants->get($chat->id);
            $unreadCount = $this->countUnread($chat, $viewer, $myParticipant);
            $lastMessage = $chat->messages->first();

            return $payloadFactory->makeChat($chat, $viewer, $myParticipant, $unreadCount, $lastMessage);
        })->values();

        return response()->json([
            'chats' => $items,
        ]);
    }

    public function createDirect(Request $request, ChatPayloadFactory $payloadFactory): JsonResponse
    {
        $viewer = $this->currentUser($request);

        $data = $request->validate([
            'user_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id'),
                Rule::notIn([$viewer->id]),
            ],
        ]);

        $targetUserId = (int) $data['user_id'];

        $existing = $this->findDirectChat($viewer->id, $targetUserId);
        $created = false;

        if (! $existing) {
            $created = true;

            $existing = DB::transaction(function () use ($viewer, $targetUserId): Chat {
                $chat = Chat::query()->create([
                    'type' => Chat::TYPE_DIRECT,
                    'created_by' => $viewer->id,
                ]);

                $now = now();

                ChatParticipant::query()->insert([
                    [
                        'chat_id' => $chat->id,
                        'user_id' => $viewer->id,
                        'role' => 'owner',
                        'joined_at' => $now,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ],
                    [
                        'chat_id' => $chat->id,
                        'user_id' => $targetUserId,
                        'role' => 'member',
                        'joined_at' => $now,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ],
                ]);

                return $chat;
            });
        }

        $chat = $this->loadChat($existing);

        $myParticipant = $chat->participants->firstWhere('user_id', $viewer->id);
        $payload = $payloadFactory->makeChat(
            $chat,
            $viewer,
            $myParticipant,
            $this->countUnread($chat, $viewer, $myParticipant),
            $chat->messages->first()
        );

        if ($created) {
            foreach ($chat->participants as $participant) {
                $recipient = $participant->user;
                if (! $recipient) {
                    continue;
                }

                $recipientParticipant = $chat->participants->firstWhere('user_id', $recipient->id);

                $this->broadcastSafely(new ChatCreatedForUser(
                    $recipient->id,
                    $payloadFactory->makeChat($chat, $recipient, $recipientParticipant, 0, $chat->messages->first())
                ));
            }
        }

        return response()->json([
            'chat' => $payload,
            'created' => $created,
        ], $created ? 201 : 200);
    }

    public function createGroup(Request $request, ChatPayloadFactory $payloadFactory): JsonResponse
    {
        $viewer = $this->currentUser($request);

        $data = $request->validate([
            'title' => ['required', 'string', 'min:2', 'max:120'],
            'participant_ids' => ['required', 'array', 'min:1', 'max:100'],
            'participant_ids.*' => [
                'integer',
                'distinct',
                Rule::exists('users', 'id'),
                Rule::notIn([$viewer->id]),
            ],
        ]);

        $participantIds = collect($data['participant_ids'])
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();

        $chat = DB::transaction(function () use ($viewer, $participantIds, $data): Chat {
            $chat = Chat::query()->create([
                'type' => Chat::TYPE_GROUP,
                'title' => $data['title'],
                'created_by' => $viewer->id,
            ]);

            $now = now();

            $rows = [
                [
                    'chat_id' => $chat->id,
                    'user_id' => $viewer->id,
                    'role' => 'owner',
                    'joined_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ];

            foreach ($participantIds as $participantId) {
                $rows[] = [
                    'chat_id' => $chat->id,
                    'user_id' => $participantId,
                    'role' => 'member',
                    'joined_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            ChatParticipant::query()->insert($rows);

            return $chat;
        });

        $chat = $this->loadChat($chat);

        foreach ($chat->participants as $participant) {
            $recipient = $participant->user;
            if (! $recipient) {
                continue;
            }

            $this->broadcastSafely(new ChatCreatedForUser(
                $recipient->id,
                $payloadFactory->makeChat($chat, $recipient, $participant, 0, null)
            ));
        }

        $myParticipant = $chat->participants->firstWhere('user_id', $viewer->id);

        return response()->json([
            'chat' => $payloadFactory->makeChat($chat, $viewer, $myParticipant, 0, null),
            'created' => true,
        ], 201);
    }

    public function messages(Request $request, Chat $chat, ChatPayloadFactory $payloadFactory): JsonResponse
    {
        $viewer = $this->currentUser($request);
        $this->assertParticipant($chat, $viewer);

        $data = $request->validate([
            'before_id' => ['nullable', 'integer', 'min:1'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $limit = (int) ($data['limit'] ?? 50);

        $query = $chat->messages()
            ->with('sender')
            ->orderByDesc('id')
            ->limit($limit);

        if (! empty($data['before_id'])) {
            $query->where('id', '<', (int) $data['before_id']);
        }

        $messages = $query->get()->sortBy('id')->values();

        return response()->json([
            'messages' => $messages
                ->map(fn (ChatMessage $message): array => $payloadFactory->makeMessage($message, $viewer))
                ->values()
                ->all(),
            'next_before_id' => $messages->isNotEmpty() ? (string) $messages->first()->id : null,
            'has_more' => $messages->count() >= $limit,
        ]);
    }

    public function sendMessage(Request $request, Chat $chat, ChatPayloadFactory $payloadFactory): JsonResponse
    {
        $viewer = $this->currentUser($request);
        $myParticipant = $this->assertParticipant($chat, $viewer);

        $data = $request->validate([
            'body' => ['required', 'string', 'min:1', 'max:4000'],
        ]);

        $message = DB::transaction(function () use ($chat, $viewer, $myParticipant, $data): ChatMessage {
            $message = ChatMessage::query()->create([
                'chat_id' => $chat->id,
                'sender_id' => $viewer->id,
                'kind' => 'text',
                'body' => trim((string) $data['body']),
            ]);

            $chat->forceFill([
                'last_message_preview' => Str::limit($message->body ?? '', 220),
                'last_message_at' => $message->created_at,
            ])->save();

            $myParticipant->forceFill([
                'last_read_message_id' => $message->id,
                'last_read_at' => now(),
            ])->save();

            return $message;
        });

        $message->load('sender');
        $chat = $this->loadChat($chat->fresh());

        $messagePayload = $payloadFactory->makeMessage($message, $viewer);

        $this->broadcastSafely(new ChatMessageSent($chat->id, $messagePayload));

        foreach ($chat->participants as $participant) {
            if ((int) $participant->user_id === (int) $viewer->id || ! $participant->user) {
                continue;
            }

            $unreadCount = $this->countUnread($chat, $participant->user, $participant);
            $chatPayloadForRecipient = $payloadFactory->makeChat($chat, $participant->user, $participant, $unreadCount, $message);

            $this->broadcastSafely(new ChatMessageNotificationForUser(
                $participant->user_id,
                $chatPayloadForRecipient,
                $messagePayload,
                $unreadCount
            ));
        }

        return response()->json([
            'message' => $messagePayload,
            'chat' => $payloadFactory->makeChat($chat, $viewer, $chat->participants->firstWhere('user_id', $viewer->id), 0, $message),
        ], 201);
    }

    public function markRead(Request $request, Chat $chat): JsonResponse
    {
        $viewer = $this->currentUser($request);
        $participant = $this->assertParticipant($chat, $viewer);

        $data = $request->validate([
            'message_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $messageId = isset($data['message_id'])
            ? (int) $data['message_id']
            : (int) ($chat->messages()->max('id') ?? 0);

        if ($messageId > 0) {
            $participant->forceFill([
                'last_read_message_id' => $messageId,
                'last_read_at' => now(),
            ])->save();

            $this->broadcastSafely(new ChatReadUpdated($chat->id, $viewer->id, $messageId));
        }

        return response()->json([
            'message' => 'Read marker updated.',
            'last_read_message_id' => $messageId > 0 ? (string) $messageId : null,
        ]);
    }

    private function currentUser(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    private function assertParticipant(Chat $chat, User $user): ChatParticipant
    {
        /** @var ChatParticipant|null $participant */
        $participant = ChatParticipant::query()
            ->where('chat_id', $chat->id)
            ->where('user_id', $user->id)
            ->first();

        if (! $participant) {
            throw new AccessDeniedHttpException('You are not a participant of this chat.');
        }

        return $participant;
    }

    private function findDirectChat(int $viewerId, int $targetUserId): ?Chat
    {
        return Chat::query()
            ->where('type', Chat::TYPE_DIRECT)
            ->whereHas('participants', fn ($builder) => $builder->where('user_id', $viewerId))
            ->whereHas('participants', fn ($builder) => $builder->where('user_id', $targetUserId))
            ->whereDoesntHave('participants', fn ($builder) => $builder->whereNotIn('user_id', [$viewerId, $targetUserId]))
            ->first();
    }

    private function loadChat(Chat $chat): Chat
    {
        return $chat->load([
            'participants.user',
            'messages' => fn ($builder) => $builder->latest('id')->limit(1),
        ]);
    }

    private function countUnread(Chat $chat, User $viewer, ?ChatParticipant $participant): int
    {
        $query = ChatMessage::query()
            ->where('chat_id', $chat->id)
            ->where('sender_id', '!=', $viewer->id);

        if ($participant?->last_read_message_id) {
            $query->where('id', '>', $participant->last_read_message_id);
        }

        return $query->count();
    }

    private function broadcastSafely(object $event): void
    {
        try {
            broadcast($event);
        } catch (Throwable $exception) {
            Log::warning('Chat broadcast failed.', [
                'event' => $event::class,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
