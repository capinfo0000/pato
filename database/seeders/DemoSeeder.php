<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Area;
use App\Models\Badge;
use App\Models\BadgeGrant;
use App\Models\CastKpi;
use App\Models\CastProfile;
use App\Models\ClassTier;
use App\Models\IdentityVerification;
use App\Models\PointTransaction;
use App\Models\PointWallet;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * ローカル/デモ用データ。岡山マスタ(OkayamaMasterSeeder)投入後に実行する。
 * 本人確認済み・ポイント保有のゲストと、在席キャストを作る。
 */
final class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $area = Area::where('serviceable', true)->firstOrFail();

        // 本人確認済み・10,000P 保有のデモゲスト
        $guest = User::updateOrCreate(
            ['email' => 'guest@example.com'],
            ['password' => Hash::make('password'), 'role' => 'guest', 'status' => 'active', 'nickname' => 'デモゲスト'],
        );
        IdentityVerification::updateOrCreate(
            ['user_id' => $guest->id],
            ['method' => 'ekyc', 'status' => 'verified', 'is_adult' => true, 'verified_at' => now()],
        );
        $wallet = PointWallet::firstOrCreate(['user_id' => $guest->id]);
        if (PointTransaction::where('wallet_id', $wallet->id)->doesntExist()) {
            PointTransaction::create([
                'wallet_id' => $wallet->id,
                'type' => 'purchase',
                'kind' => 'paid',
                'points' => 30000,
                'idempotency_key' => 'demo-seed-purchase',
                'expires_on' => now()->addDays(180),
            ]);
        }

        // 運営（管理画面の確認用）
        $admin = User::updateOrCreate(
            ['email' => 'admin@example.com'],
            ['password' => Hash::make('password'), 'role' => 'admin', 'status' => 'active', 'nickname' => '運営'],
        );
        PointWallet::firstOrCreate(['user_id' => $admin->id]);

        // 在席キャスト（クラスごと）
        $tiers = ClassTier::pluck('id', 'code');
        $casts = [
            ['premium', 'あおい', 24, 'now', false],
            ['premium', 'みなと', 26, 'now', false],
            ['vip', 'れい', 28, 'now', false],
            ['vip', 'さき', 25, 'today', false],
            ['royal_vip', 'ひなの', 27, 'now', false],
            ['royal_vip', 'かえで', 29, 'today', true], // 合流中
        ];
        foreach ($casts as $i => [$class, $name, $age, $availability, $inSession]) {
            $u = User::updateOrCreate(
                ['email' => "cast{$i}@example.com"],
                ['password' => Hash::make('password'), 'role' => 'cast', 'status' => 'active', 'nickname' => $name],
            );
            IdentityVerification::updateOrCreate(
                ['user_id' => $u->id],
                ['method' => 'ekyc', 'status' => 'verified', 'is_adult' => true, 'verified_at' => now()],
            );
            $profile = CastProfile::updateOrCreate(
                ['user_id' => $u->id],
                [
                    'display_name' => $name,
                    'class_tier_id' => $tiers[$class],
                    'home_area_id' => $area->id,
                    'screening_status' => 'approved',
                    'is_active' => true,
                    'availability' => $availability,
                    'in_session' => $inSession,
                    'age' => $age,
                    'bio' => 'よろしくお願いします',
                ],
            );

            // 評価指標（デモ値）
            CastKpi::updateOrCreate(
                ['cast_profile_id' => $profile->id],
                [
                    'extend_rate_x10' => 40 + $i,
                    'repeat_rate_x10' => 42 + $i,
                    'remeet_rate_x10' => 46 + $i,
                    'fan_points_total' => (6 - $i) * 120,
                    'recalculated_at' => now(),
                ],
            );

            // ゲストから受け取ったバッジ（デモ）
            $badges = Badge::pluck('id')->all();
            if ($badges !== []) {
                BadgeGrant::firstOrCreate([
                    'badge_id' => $badges[$i % count($badges)],
                    'from_user_id' => $guest->id,
                    'cast_profile_id' => $profile->id,
                ]);
            }
        }
    }
}
