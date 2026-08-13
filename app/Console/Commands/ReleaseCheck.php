<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Adapters\Fake\FakeEkycProvider;
use App\Support\Adapters\Fake\FakePaymentGateway;
use App\Support\Adapters\Fake\FakePushSender;
use App\Support\Contracts\EkycProvider;
use App\Support\Contracts\PaymentGateway;
use App\Support\Contracts\PushSender;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;

/**
 * 本番公開の前提条件（リリースゲート）を機械的に検証する。
 *
 * docs/04_legal_compliance.md のゲートのうち、コードで確認できるものを自動化した。
 * ここが緑でも「弁護士レビュー」と「異性紹介事業の届出」は人がやる手続きであり、
 * 代替にはならない（docs/07_release_gate.md）。
 */
final class ReleaseCheck extends Command
{
    protected $signature = 'pato:release-check';

    protected $description = '本番公開の前提条件（法定表示・届出番号・実Adapter・ポイント要件）を検証する';

    public function handle(): int
    {
        $failures = [];
        $warnings = [];

        $this->line('');
        $this->info('== pato岡山 リリースゲート ==');

        // 1. 事業者情報（特商法表記）
        foreach (['name' => '販売業者名', 'representative' => '運営責任者', 'address' => '所在地', 'contact_email' => '連絡先'] as $key => $label) {
            $this->check(
                "特商法表記: {$label}",
                filled(config("pato.operator.{$key}")),
                $failures,
                "config/pato.php（PATO_OPERATOR_*）に {$label} を設定してください",
            );
        }

        // 2. インターネット異性紹介事業の届出番号
        //    他社の番号は使えない。自社で岡山県公安委員会へ届け出て取得すること。
        $this->check(
            'インターネット異性紹介事業の届出番号',
            filled(config('pato.internet_dating_registration')),
            $failures,
            '事業開始前日までに、事務所所在地を管轄する警察署経由で岡山県公安委員会へ届出し、'
                .'取得した番号を PATO_IDS_REGISTRATION に設定してください（他社の番号は使用不可）',
        );

        // 3. 法定表示ページ
        foreach (['legal.terms' => '利用規約', 'legal.privacy' => 'プライバシーポリシー', 'legal.commerce' => '特商法表記'] as $name => $label) {
            $this->check("法定表示ページ: {$label}", Route::has($name), $failures, "{$label} のページを用意してください");
        }

        // 4. ポイントの法定要件（資金決済法の適用除外＝有効期限6ヶ月以内）
        $expiry = (int) config('pato.point.expiry_days');
        $this->check(
            "ポイント有効期限が6ヶ月以内（現在 {$expiry} 日）",
            $expiry > 0 && $expiry <= 180,
            $failures,
            '有効期限を180日以内にするか、前払式支払手段としての届出・供託等の対応を行ってください',
        );
        $this->check(
            'ポイントは払戻し・換金不可',
            config('pato.point.refundable') === false,
            $failures,
            '払戻しを行う場合は資金決済法上の整理を弁護士に確認してください',
        );

        // 5. 利用場所の制限（密室禁止）
        $venues = (array) config('pato.allowed_venue_kinds');
        $this->check(
            '利用場所が公共の場に限定されている（密室禁止）',
            $venues !== [] && ! array_intersect($venues, ['hotel', 'home', 'private_room']),
            $failures,
            'ホテル・自宅・鍵付き個室を許可しないでください',
        );

        // 6. 年齢制限
        $this->check(
            '18歳未満を排除する設定',
            (int) config('pato.min_age') >= 18,
            $failures,
            'min_age を18以上にしてください',
        );

        // 7. Web Push に必要な PHP 拡張（無いと署名計算が極端に遅くなる）
        if (filled(config('services.webpush.public_key'))) {
            $hasFastMath = extension_loaded('gmp') || extension_loaded('bcmath');
            $mathBucket = $hasFastMath ? $failures : $warnings;
            $this->check(
                'Web Push の署名計算用拡張（gmp または bcmath）',
                $hasFastMath,
                $mathBucket,
                'php-gmp または php-bcmath をインストールしてください（無いと VAPID 署名が極端に遅くなる）',
            );
            $hasFastMath ? $failures = $mathBucket : $warnings = $mathBucket;
        }

        // 8. 外部アダプタが Fake のままでないか（本番のみ致命）
        $adapters = [
            [PaymentGateway::class, FakePaymentGateway::class, '決済（PSP）'],
            [EkycProvider::class, FakeEkycProvider::class, '本人確認（eKYC）'],
            [PushSender::class, FakePushSender::class, 'プッシュ通知'],
        ];
        // 本番では致命、それ以外は警告として扱う
        $adapterBucket = app()->environment('production') ? 'failures' : 'warnings';
        foreach ($adapters as [$contract, $fake, $label]) {
            $isFake = app($contract) instanceof $fake;
            $bucket = $adapterBucket === 'failures' ? $failures : $warnings;
            $this->check(
                "{$label} が実アダプタ",
                ! $isFake,
                $bucket,
                "{$label} の実装を実ベンダのアダプタに差し替えてください（現在は Fake）",
            );
            $adapterBucket === 'failures' ? $failures = $bucket : $warnings = $bucket;
        }

        $this->line('');

        foreach ($warnings as $warning) {
            $this->warn("[警告] {$warning}");
        }

        if ($failures !== []) {
            $this->line('');
            $this->error('公開前提条件を満たしていません:');
            foreach ($failures as $failure) {
                $this->error("  - {$failure}");
            }
            $this->line('');
            $this->warn('※ このチェックが全て緑でも、弁護士レビューと異性紹介事業の届出は別途必要です。');

            return self::FAILURE;
        }

        $this->info('コードで確認できる前提条件はすべて満たしています。');
        $this->warn('※ 弁護士レビュー（風営法・職安法・資金決済法）と異性紹介事業の届出は');
        $this->warn('  人が行う手続きです。docs/07_release_gate.md のチェックリストで確認してください。');

        return self::SUCCESS;
    }

    /** @param  list<string>  $bucket */
    private function check(string $label, bool $ok, array &$bucket, string $remedy): void
    {
        $this->line(sprintf('  %s %s', $ok ? '<info>OK  </info>' : '<comment>NG  </comment>', $label));

        if (! $ok) {
            $bucket[] = $remedy;
        }
    }
}
