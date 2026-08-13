<?php

declare(strict_types=1);

namespace App\Logging;

use Illuminate\Log\Logger;

/** ログチャンネルに PII マスクのプロセッサを差し込む tap。 */
final class ApplyPiiMask
{
    public function __invoke(Logger $logger): void
    {
        foreach ($logger->getLogger()->getHandlers() as $handler) {
            $handler->pushProcessor(new MaskPii);
        }
    }
}
