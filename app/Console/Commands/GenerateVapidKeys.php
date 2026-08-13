<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

/**
 * Web Push の VAPID 鍵ペアを生成する。
 *
 * 生成した鍵は .env に設定する。秘密鍵は決してリポジトリに入れないこと。
 * 鍵を変えると既存の購読はすべて無効になる（購読し直しが必要）。
 */
final class GenerateVapidKeys extends Command
{
    protected $signature = 'pato:vapid-keys';

    protected $description = 'Web Push 用の VAPID 鍵ペアを生成する';

    public function handle(): int
    {
        $keys = VAPID::createVapidKeys();

        $this->info('生成しました。.env に設定してください（秘密鍵はコミットしないこと）:');
        $this->line('');
        $this->line('VAPID_PUBLIC_KEY='.$keys['publicKey']);
        $this->line('VAPID_PRIVATE_KEY='.$keys['privateKey']);
        $this->line('VAPID_SUBJECT=mailto:support@example.com');
        $this->line('');
        $this->warn('※ 鍵を変更すると既存の購読は無効になり、利用者は購読し直しになります。');

        return self::SUCCESS;
    }
}
