<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * 岡山エリアのマスタ投入。料金は docs/06_pricing_model.md（地方水準）準拠。
 * テイクレートは作業デフォルト40%。値は本番前にビジネス判断で確定する。
 */
final class OkayamaMasterSeeder extends Seeder
{
    public function run(): void
    {
        $areaId = DB::table('areas')->insertGetId([
            'name' => '岡山市中心部',
            'serviceable' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 岡山近郊は当面サービス外（エリア限定の担保）
        DB::table('areas')->insert([
            ['name' => '岡山県その他', 'serviceable' => false, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $tiers = [
            'premium' => ['name' => 'プレミアム', 'points' => 3000],
            'vip' => ['name' => 'VIP', 'points' => 5500],
            'royal_vip' => ['name' => 'ロイヤルVIP', 'points' => 10000],
        ];

        foreach ($tiers as $code => $t) {
            $tierId = DB::table('class_tiers')->insertGetId([
                'code' => $code,
                'name' => $t['name'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('area_class_prices')->insert([
                'area_id' => $areaId,
                'class_tier_id' => $tierId,
                'points_per_30min' => $t['points'],
                'take_rate_bp' => 4000,          // 40%
                'nomination_surcharge_bp' => 2000, // +20%
                'night_surcharge_bp' => 2000,      // +20%
                'effective_from' => '2026-08-12',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // 称賛バッジ（ゲスト → キャスト）
        foreach ([
            'healing' => '癒し系', 'sparkle' => 'キラキラ',
            'humor' => 'ユーモア', 'diva' => '歌姫', 'smart' => '博識',
        ] as $code => $name) {
            DB::table('badges')->insert([
                'code' => $code, 'name' => $name, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        // ポイント商品（1P=¥1.2 基準の例）
        DB::table('point_products')->insert([
            ['paid_points' => 5000, 'price_yen' => 6000, 'active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['paid_points' => 10000, 'price_yen' => 12000, 'active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['paid_points' => 30000, 'price_yen' => 36000, 'active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }
}
