<?php

namespace Database\Seeders;

use App\Models\Chat;
use App\Models\ChatParticipant;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $passwordHash = Hash::make('Qq123581!@#');

        $owner = User::query()->updateOrCreate([
            'email' => 'asvrodya@gmail.com',
        ], [
            'name' => 'Rodion Maksimenko',
            'nickname' => 'Rodevillow',
            'locale' => 'ru',
            'password' => $passwordHash,
            'email_verified_at' => now(),
        ]);

        $friends = collect([
            ['name' => 'Eleanor Pena', 'nickname' => 'el_pena', 'email' => 'eleanor.pena@gmail.com', 'locale' => 'en'],
            ['name' => 'Cody Fisher', 'nickname' => 'cody_fisher', 'email' => 'cody.fisher@gmail.com', 'locale' => 'en'],
            ['name' => 'Jerome Bell', 'nickname' => 'jerome_bell', 'email' => 'jerome.bell@gmail.com', 'locale' => 'en'],
            ['name' => 'Esther Howard', 'nickname' => 'esther_h', 'email' => 'esther.howard@gmail.com', 'locale' => 'uk'],
            ['name' => 'Robert Fox', 'nickname' => 'robert_fox', 'email' => 'robert.fox@gmail.com', 'locale' => 'en'],
            ['name' => 'Dianne Russell', 'nickname' => 'dianne_rs', 'email' => 'dianne.russell@gmail.com', 'locale' => 'ru'],
        ])->map(function (array $friendData) use ($passwordHash): User {
            return User::query()->updateOrCreate(
                ['email' => $friendData['email']],
                [
                    'name' => $friendData['name'],
                    'nickname' => $friendData['nickname'],
                    'locale' => $friendData['locale'],
                    'password' => $passwordHash,
                    'email_verified_at' => now(),
                ]
            );
        });

        DB::table('user_contacts')->where('user_id', $owner->id)->delete();
        DB::table('user_contacts')->insert(
            $friends->map(fn (User $friend): array => [
                'user_id' => $owner->id,
                'contact_user_id' => $friend->id,
                'created_at' => now(),
                'updated_at' => now(),
            ])->all()
        );

        Chat::query()->delete();

        $friends->each(function (User $friend) use ($owner): void {
            $this->createDirectChat($owner, $friend);
        });

        $groupMembers = $friends->slice(0, 4)->push($owner);
        $groupChat = Chat::query()->create([
            'type' => Chat::TYPE_GROUP,
            'title' => 'Support Team',
            'created_by' => $owner->id,
        ]);

        $now = now();

        $rows = $groupMembers->map(fn (User $member): array => [
            'chat_id' => $groupChat->id,
            'user_id' => $member->id,
            'role' => $member->id === $owner->id ? 'owner' : 'member',
            'joined_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        ChatParticipant::query()->insert($rows);

        ChatParticipant::query()
            ->where('chat_id', $groupChat->id)
            ->where('user_id', $owner->id)
            ->update([
                'last_read_message_id' => null,
                'last_read_at' => null,
            ]);
    }

    private function createDirectChat(User $owner, User $friend): Chat
    {
        $chat = Chat::query()->create([
            'type' => Chat::TYPE_DIRECT,
            'created_by' => $owner->id,
        ]);

        $now = now();

        ChatParticipant::query()->insert([
            [
                'chat_id' => $chat->id,
                'user_id' => $owner->id,
                'role' => 'owner',
                'joined_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'chat_id' => $chat->id,
                'user_id' => $friend->id,
                'role' => 'member',
                'joined_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        return $chat;
    }
}
