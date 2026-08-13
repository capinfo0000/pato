<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Point\Contracts\WalletRepository;
use App\Models\PointWallet;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\TestCase;

/**
 * ロック付き読み込みの誤用ガード。
 *
 * RefreshDatabase は各テストをトランザクションで包んでしまうため、
 * 「トランザクション外」を再現できるこのクラスだけ DatabaseMigrations を使う。
 */
final class LockedReadGuardTest extends TestCase
{
    use DatabaseMigrations;

    public function test_locked_read_outside_a_transaction_is_rejected(): void
    {
        $user = User::create([
            'email' => 'g@example.com', 'password' => 'secret-password',
            'role' => 'guest', 'nickname' => 'ゲスト',
        ]);
        $wallet = PointWallet::create(['user_id' => $user->id]);

        // トランザクション外ではロックが即解放され、残高チェックの意味が無くなる。
        // 静かに壊れるより、誤用として落とす。
        $this->expectException(\LogicException::class);
        app(WalletRepository::class)->loadForUpdate($wallet->id);
    }
}
