<?php

declare(strict_types=1);

namespace App\Application\Messaging;

use App\Domain\Messaging\ContentFilter;
use App\Models\Call;
use App\Models\Message;
use App\Models\Thread;
use App\Models\ThreadParticipant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * メッセージ投稿。禁止内容（連絡先交換・外部誘導・密室・現金手渡し・性的示唆）を
 * ContentFilter で検知し、フラグを立てて運用（監視・通報）に回す。
 *
 * ブロックはせず記録する方針。誤検知で会話が止まるより、運営が確認できることを優先する。
 * ただし現金手渡し・密室・性的示唆は重大なので、投稿者へ警告を返す。
 */
final class PostMessageService
{
    /** 警告を返す（＝ユーザーに注意喚起する）理由コード。 */
    private const SEVERE = ['cash_direct', 'private_room', 'sexual'];

    public function __construct(private readonly ContentFilter $filter = new ContentFilter) {}

    /**
     * @return array{message: Message, warnings: list<string>}
     */
    public function post(Thread $thread, User $sender, string $body): array
    {
        $reasons = $this->filter->scan($body);

        $message = DB::transaction(function () use ($thread, $sender, $body, $reasons) {
            $message = Message::create([
                'thread_id' => $thread->id,
                'sender_user_id' => $sender->id,
                'body' => $body,
                'flagged' => $reasons !== [],
                'flag_reasons' => $reasons === [] ? null : $reasons,
            ]);

            $thread->update(['last_message_at' => now()]);

            return $message;
        });

        return [
            'message' => $message,
            'warnings' => array_values(array_intersect($reasons, self::SEVERE)),
        ];
    }

    /** 成立した呼び出しのグループチャットを用意する（参加者を揃える）。 */
    public function ensureCallThread(Call $call): Thread
    {
        return DB::transaction(function () use ($call) {
            $thread = Thread::firstOrCreate(
                ['call_id' => $call->id],
                ['kind' => 'call', 'last_message_at' => now()],
            );

            $userIds = $call->participants()
                ->with('castProfile')
                ->get()
                ->pluck('castProfile.user_id')
                ->filter()
                ->push($call->guest_user_id)
                ->unique();

            foreach ($userIds as $userId) {
                ThreadParticipant::firstOrCreate([
                    'thread_id' => $thread->id,
                    'user_id' => $userId,
                ]);
            }

            return $thread;
        });
    }

    /** コンシェルジュ（公式）スレッドを用意する。 */
    public function ensureConciergeThread(User $user): Thread
    {
        return DB::transaction(function () use ($user) {
            $existing = Thread::where('kind', 'concierge')
                ->whereHas('participants', fn ($q) => $q->where('user_id', $user->id))
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $thread = Thread::create(['kind' => 'concierge', 'last_message_at' => now()]);
            ThreadParticipant::create(['thread_id' => $thread->id, 'user_id' => $user->id]);

            $concierge = User::firstOrCreate(
                ['email' => 'concierge@pato-okayama.local'],
                [
                    'password' => bcrypt(str()->random(32)),
                    'role' => 'system',
                    'status' => 'active',
                    'nickname' => 'patoコンシェルジュ',
                ],
            );
            ThreadParticipant::firstOrCreate(['thread_id' => $thread->id, 'user_id' => $concierge->id]);

            Message::create([
                'thread_id' => $thread->id,
                'sender_user_id' => $concierge->id,
                'body' => 'ご登録ありがとうございます。ご不明な点はこちらからお問い合わせください。',
            ]);

            return $thread;
        });
    }
}
