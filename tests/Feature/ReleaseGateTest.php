<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

final class ReleaseGateTest extends TestCase
{
    public function test_legal_pages_are_public(): void
    {
        // 未ログインでも法定表示は読めること
        $this->get(route('legal.terms'))->assertOk()->assertSee('利用規約');
        $this->get(route('legal.privacy'))->assertOk()->assertSee('プライバシーポリシー');
        $this->get(route('legal.commerce'))->assertOk()->assertSee('特定商取引法');
    }

    public function test_terms_state_the_intermediary_position_and_prohibitions(): void
    {
        $response = $this->get(route('legal.terms'));

        // 運営が契約当事者にならない建て付け
        $response->assertSee('エンタメスキル提供契約');
        $response->assertSee('当事者とはなりません');
        // 密室禁止・現金直接取引・性的サービスの禁止
        $response->assertSee('密室でのご利用');
        $response->assertSee('現金の直接手渡し');
        $response->assertSee('性的サービス');
        // 年齢制限
        $response->assertSee('18歳未満の方はご利用いただけません');
    }

    public function test_commerce_page_states_the_no_refund_and_expiry_rules(): void
    {
        $this->get(route('legal.commerce'))
            ->assertOk()
            ->assertSee('払戻し・換金ができません')
            ->assertSee('180日');
    }

    public function test_registration_number_is_not_shown_until_it_is_configured(): void
    {
        // 未取得のうちは他社の番号を出さない（空欄のまま）
        config(['pato.internet_dating_registration' => '']);
        $this->get(route('legal.terms'))->assertDontSee('インターネット異性紹介事業届出済み');

        config(['pato.internet_dating_registration' => '岡山00-000000']);
        $this->get(route('legal.terms'))
            ->assertSee('インターネット異性紹介事業届出済み')
            ->assertSee('岡山00-000000');
    }

    public function test_release_check_fails_while_prerequisites_are_missing(): void
    {
        config([
            'pato.operator.name' => '',
            'pato.internet_dating_registration' => '',
        ]);

        $this->artisan('pato:release-check')
            ->assertExitCode(1);
    }

    public function test_release_check_passes_once_everything_is_configured(): void
    {
        config([
            'pato.operator.name' => '株式会社テスト',
            'pato.operator.representative' => '山田太郎',
            'pato.operator.address' => '岡山県岡山市北区1-2-3',
            'pato.operator.contact_email' => 'support@example.com',
            'pato.internet_dating_registration' => '岡山00-000000',
        ]);

        // 非本番では Fake アダプタは警告どまり
        $this->artisan('pato:release-check')->assertExitCode(0);
    }

    public function test_point_expiry_stays_within_the_six_month_exemption(): void
    {
        // 資金決済法の適用除外（6ヶ月以内）を超える設定は通さない
        config([
            'pato.operator.name' => '株式会社テスト',
            'pato.operator.representative' => '山田太郎',
            'pato.operator.address' => '岡山県岡山市北区1-2-3',
            'pato.operator.contact_email' => 'support@example.com',
            'pato.internet_dating_registration' => '岡山00-000000',
            'pato.point.expiry_days' => 365,
        ]);

        $this->artisan('pato:release-check')->assertExitCode(1);
    }

    public function test_private_rooms_are_never_an_allowed_venue(): void
    {
        $this->assertEmpty(
            array_intersect(config('pato.allowed_venue_kinds'), ['hotel', 'home', 'private_room']),
            '密室が利用場所として許可されている',
        );
    }
}
