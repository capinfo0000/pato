<?php

declare(strict_types=1);

namespace App\Domain\Call\Enums;

enum CallStatus: string
{
    case Draft = 'draft';
    case Open = 'open';
    case Matched = 'matched';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Canceled = 'canceled';
    case Expired = 'expired';
}
