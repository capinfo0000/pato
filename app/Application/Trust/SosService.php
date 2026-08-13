<?php

declare(strict_types=1);

namespace App\Application\Trust;

use App\Models\Call;
use App\Models\SosEvent;
use App\Models\User;
use App\Support\Contracts\PushSender;
use Illuminate\Support\Facades\DB;

/**
 * SOS通報。合流中に危険を感じたとき、ゲスト・キャストのどちらからでも押せる。
 *
 * - 押下で即座に運営へエスカレーション（PushSender）
 * - 対応状況（open → acknowledged → resolved）を管理画面で追跡
 * - 位置メモは PII。ログに出さず、閲覧は管理者に限る
 *
 * ※ 緊急時はまず110番等の公的機関へ、という案内を必ずUIに出すこと（docs/04）。
 */
final class SosService
{
    public function __construct(private readonly PushSender $push) {}

    /** SOSを発報する。連打は直近の未対応イベントにまとめる。 */
    public function raise(User $user, ?Call $call = null, ?string $note = null, ?string $locationHint = null): SosEvent
    {
        return DB::transaction(function () use ($user, $call, $note, $locationHint) {
            $existing = SosEvent::where('user_id', $user->id)
                ->where('status', 'open')
                ->where('created_at', '>=', now()->subMinutes(10))
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $event = SosEvent::create([
                'user_id' => $user->id,
                'call_id' => $call?->id,
                'status' => 'open',
                'note' => $note,
                'location_hint' => $locationHint,
            ]);

            // 運営（admin）全員へ即時通知
            foreach (User::where('role', 'admin')->pluck('id') as $adminId) {
                $this->push->send(
                    $adminId,
                    'SOSが発報されました',
                    '至急ご確認ください。',
                    ['sos_id' => $event->id, 'call_id' => (int) $call?->id],
                );
            }

            return $event;
        });
    }

    /** 運営が受信を確認した。 */
    public function acknowledge(SosEvent $event, User $admin): SosEvent
    {
        return $this->transition($event, 'acknowledged', ['open'], $admin, ['acknowledged_at' => now()]);
    }

    /** 対応完了。 */
    public function resolve(SosEvent $event, User $admin, ?string $note = null): SosEvent
    {
        return $this->transition(
            $event,
            'resolved',
            ['open', 'acknowledged'],
            $admin,
            array_filter(['resolved_at' => now(), 'note' => $note]),
        );
    }

    /**
     * @param  list<string>  $allowedFrom
     * @param  array<string, mixed>  $extra
     */
    private function transition(SosEvent $event, string $to, array $allowedFrom, User $admin, array $extra = []): SosEvent
    {
        $event->refresh();

        if (! in_array($event->status, $allowedFrom, true)) {
            throw new \DomainException('invalid_sos_transition');
        }

        $event->update(array_merge(['status' => $to, 'handled_by' => $admin->id], $extra));

        return $event->fresh();
    }
}
