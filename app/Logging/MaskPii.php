<?php

declare(strict_types=1);

namespace App\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * ログから PII をマスクする（docs/04 §5・CLAUDE.md の約束）。
 *
 * 完璧な検知は不可能なので「よくある形」を潰す多層防御の一枚。
 * そもそも PII をログに渡さないのが原則で、これは最後の網。
 */
final class MaskPii implements ProcessorInterface
{
    /** マスク対象のキー（context 内）。 */
    private const SENSITIVE_KEYS = [
        'password', 'birthdate', 'birthdate_encrypted', 'date_of_birth',
        'phone', 'address', 'location_hint', 'card', 'card_number',
        'public_key', 'auth_token', 'api_key', 'secret', 'token',
    ];

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            message: $this->maskMessage($record->message),
            context: $this->maskArray($record->context),
        );
    }

    /** @param array<mixed> $context */
    private function maskArray(array $context): array
    {
        foreach ($context as $key => $value) {
            if (is_array($value)) {
                $context[$key] = $this->maskArray($value);

                continue;
            }

            if (is_string($key) && $this->isSensitive($key)) {
                $context[$key] = '***';

                continue;
            }

            if (is_string($value)) {
                $context[$key] = $this->maskMessage($value);
            }
        }

        return $context;
    }

    private function isSensitive(string $key): bool
    {
        $needle = strtolower($key);

        foreach (self::SENSITIVE_KEYS as $sensitive) {
            if (str_contains($needle, $sensitive)) {
                return true;
            }
        }

        return false;
    }

    private function maskMessage(string $message): string
    {
        // メールアドレス
        $message = preg_replace('/[\w.+-]+@[\w-]+\.[\w.-]+/u', '***@***', $message) ?? $message;
        // 電話番号（国内の一般的な形）
        $message = preg_replace('/\b0\d{1,3}[-\s]?\d{2,4}[-\s]?\d{3,4}\b/u', '***', $message) ?? $message;

        // 生年月日らしき日付
        return preg_replace('/\b(19|20)\d{2}[-\/]\d{1,2}[-\/]\d{1,2}\b/u', '****-**-**', $message) ?? $message;
    }
}
