<?php

declare(strict_types=1);

namespace App\Domain\Point\Enums;

/**
 * ポイント台帳のトランザクション種別。
 *
 * 残高は「台帳の合算」で表現する（残高カラムを直接書き換えない）。
 * 各種別が「確定残高」「与信（ホールド）」にどう効くかは PointBalance が解釈する。
 */
enum TransactionType: string
{
    case Purchase = 'purchase';       // 有償ポイント購入（+ 確定）
    case Grant = 'grant';             // 無償ポイント付与（+ 確定）
    case Hold = 'hold';               // 呼び出し作成/延長の与信（利用可能残高を控除）
    case Capture = 'capture';         // 完了時にホールドを確定消費（- 確定, ホールド解消）
    case Release = 'release';         // キャンセル/不成立でホールド解放（ホールド解消）
    case Tip = 'tip';                 // おひねり消費（- 確定）
    case Expire = 'expire';           // 有効期限切れ失効（- 確定）
    case PayoutDebit = 'payout_debit'; // キャスト精算の引き落とし（- 確定, キャスト側ウォレット）
    case Refund = 'refund';           // 返金・チャージバックによる回収（- 確定）

    /**
     * 「確定残高（settled）」への符号。ホールドは確定残高を動かさないので 0。
     */
    public function settledSign(): int
    {
        return match ($this) {
            self::Purchase, self::Grant => 1,
            self::Capture, self::Tip, self::Expire, self::PayoutDebit, self::Refund => -1,
            self::Hold, self::Release => 0,
        };
    }

    /**
     * 「未解消ホールド（outstanding hold）」への符号。
     * Hold で増え、Capture / Release で解消される。
     */
    public function holdSign(): int
    {
        return match ($this) {
            self::Hold => 1,
            self::Capture, self::Release => -1,
            default => 0,
        };
    }
}
