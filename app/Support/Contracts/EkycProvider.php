<?php

declare(strict_types=1);

namespace App\Support\Contracts;

use App\Support\DTO\EkycResult;

/**
 * eKYC（本人確認）ベンダの抽象。18歳未満の排除と本人確認に使う。
 * PII（書類・顔画像）は Adapter 内で扱い、結果だけを返す。
 */
interface EkycProvider
{
    /**
     * ベンダの確認セッション参照から結果を取得する。
     */
    public function verify(string $sessionRef): EkycResult;
}
