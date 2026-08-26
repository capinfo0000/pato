<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            OkayamaMasterSeeder::class, // 岡山エリア・クラス・料金・ポイント商品
            DemoSeeder::class,          // デモゲスト（本人確認済・ポイント）と在席キャスト
        ]);
    }
}
