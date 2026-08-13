<?php

declare(strict_types=1);

namespace App\Application\Trust;

use App\Models\Call;
use App\Models\Report;
use App\Models\User;
use App\Support\Audit;
use App\Support\Contracts\PushSender;
use Illuminate\Support\Facades\DB;

/**
 * 通報と制裁。ゲスト・キャストのどちらからでも通報でき、運営が確認して対応する。
 *
 * 制裁は users.status を suspended にする（ログインは可能だが呼び出し作成・参加はできない）。
 * PII（通報詳細）はログに出さない。
 */
final class ReportService
{
    /** 通報理由。 */
    public const REASONS = [
        'harassment' => '迷惑行為・ハラスメント',
        'external_solicit' => '外部誘導・勧誘',
        'danger' => '危険を感じた',
        'other' => 'その他',
    ];

    public function __construct(private readonly PushSender $push) {}

    /** 通報する。同じ呼び出し・同じ相手への重複通報は1件にまとめる。 */
    public function report(User $reporter, User $target, string $reason, ?string $detail = null, ?Call $call = null): Report
    {
        if (! array_key_exists($reason, self::REASONS)) {
            throw new \InvalidArgumentException("未知の通報理由: {$reason}");
        }
        if ($reporter->id === $target->id) {
            throw new \DomainException('cannot_report_self');
        }

        return DB::transaction(function () use ($reporter, $target, $reason, $detail, $call) {
            $existing = Report::where('reporter_user_id', $reporter->id)
                ->where('target_user_id', $target->id)
                ->where('call_id', $call?->id)
                ->where('status', 'open')
                ->first();

            if ($existing !== null) {
                throw new \DomainException('already_reported');
            }

            return Report::create([
                'reporter_user_id' => $reporter->id,
                'target_user_id' => $target->id,
                'call_id' => $call?->id,
                'reason' => $reason,
                'detail' => $detail,
                'status' => 'open',
            ]);
        });
    }

    /** 対応中にする。 */
    public function startReview(Report $report): Report
    {
        return $this->transition($report, 'reviewing', ['open']);
    }

    /**
     * 対応済みにする。必要ならアカウントを停止する。
     */
    public function action(Report $report, bool $suspendTarget = false): Report
    {
        return DB::transaction(function () use ($report, $suspendTarget) {
            $updated = $this->transition($report, 'actioned', ['open', 'reviewing']);

            if ($suspendTarget) {
                $this->suspend($updated->target_user_id);
            }

            return $updated;
        });
    }

    /** 却下（問題なし）。 */
    public function dismiss(Report $report): Report
    {
        return $this->transition($report, 'dismissed', ['open', 'reviewing']);
    }

    /** アカウント停止。呼び出し作成・参加ができなくなる。 */
    public function suspend(int $userId): User
    {
        $user = User::findOrFail($userId);
        $user->update(['status' => 'suspended']);

        Audit::log('user.suspended', $user);
        $this->push->send($userId, 'ご利用制限のお知らせ', 'ガイドライン違反のためアカウントを制限しました。');

        return $user->fresh();
    }

    /** 停止解除。 */
    public function reinstate(int $userId): User
    {
        $user = User::findOrFail($userId);
        $user->update(['status' => 'active']);

        Audit::log('user.reinstated', $user);

        return $user->fresh();
    }

    /** @param  list<string>  $allowedFrom */
    private function transition(Report $report, string $to, array $allowedFrom): Report
    {
        $report->refresh();

        if (! in_array($report->status, $allowedFrom, true)) {
            throw new \DomainException('invalid_report_transition');
        }

        $report->update(['status' => $to]);

        return $report->fresh();
    }
}
