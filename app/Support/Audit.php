<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

/**
 * 監査ログの記録。運営の重要操作から呼ぶ。
 *
 * context に PII（氏名・生年月日・本人確認書類・位置情報など）を入れないこと。
 * 記録するのは「何を対象に、どんな値で操作したか」まで。
 */
final class Audit
{
    /** @param array<string, scalar|null> $context */
    public static function log(string $action, ?Model $subject = null, array $context = []): AuditLog
    {
        return AuditLog::create([
            'actor_user_id' => Auth::id(),
            'action' => $action,
            'subject_type' => $subject !== null ? $subject::class : null,
            'subject_id' => $subject?->getKey(),
            'context' => $context === [] ? null : $context,
            'ip' => Request::ip(),
            'created_at' => now(),
        ]);
    }
}
