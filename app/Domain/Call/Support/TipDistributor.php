<?php

declare(strict_types=1);

namespace App\Domain\Call\Support;

/**
 * おひねり（チップ）ポイントを参加キャストへ配分する純粋ロジック。
 *
 * - 指定配分があればそれを使う（合計が総額と一致すること）。
 * - 未指定なら均等分配。割り切れない端数は先頭のキャストから1ポイントずつ寄せる。
 */
final class TipDistributor
{
    /**
     * @param  list<int>            $castProfileIds 参加キャスト
     * @param  array<int,int>|null  $explicit       castProfileId => points（指定配分, 任意）
     * @return array<int,int>       castProfileId => 配分ポイント
     */
    public function distribute(int $totalPoints, array $castProfileIds, ?array $explicit = null): array
    {
        if ($totalPoints < 0) {
            throw new \InvalidArgumentException('totalPoints は0以上');
        }
        if ($castProfileIds === []) {
            throw new \InvalidArgumentException('配分先のキャストがいない');
        }

        if ($explicit !== null) {
            foreach ($castProfileIds as $id) {
                if (! array_key_exists($id, $explicit)) {
                    throw new \InvalidArgumentException("配分未指定のキャスト: {$id}");
                }
            }
            if (array_sum($explicit) !== $totalPoints) {
                throw new \InvalidArgumentException('指定配分の合計が総額と一致しない');
            }

            return $explicit;
        }

        $n = count($castProfileIds);
        $each = intdiv($totalPoints, $n);
        $remainder = $totalPoints % $n;

        $result = [];
        foreach ($castProfileIds as $i => $id) {
            $result[$id] = $each + ($i < $remainder ? 1 : 0);
        }

        return $result;
    }
}
