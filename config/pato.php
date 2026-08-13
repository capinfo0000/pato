<?php

declare(strict_types=1);

/**
 * pato岡山（仮）の事業者情報・法定表示。
 *
 * ここは「事業者ごとに固有」の値。他社（pato/株式会社キネカ等）の登録番号や
 * 事業者名を流用してはならない。特に internet_dating_registration は
 * 自社が岡山県公安委員会へ届け出て取得した番号のみを設定すること。
 * 詳細は docs/07_release_gate.md。
 */
return [
    // 特定商取引法に基づく表示
    'operator' => [
        'name' => env('PATO_OPERATOR_NAME', ''),          // 例: 株式会社キャップインフォ
        'representative' => env('PATO_OPERATOR_REP', ''),  // 運営責任者
        'address' => env('PATO_OPERATOR_ADDRESS', ''),     // 所在地
        'contact_email' => env('PATO_CONTACT_EMAIL', ''),
        'contact_phone' => env('PATO_CONTACT_PHONE', ''),  // 表示のみ。問い合わせはメール
    ],

    // インターネット異性紹介事業の届出番号（出会い系サイト規制法）
    // 事業開始前日までに、事務所所在地を管轄する警察署経由で岡山県公安委員会へ届出。
    // 未取得のうちは空のままにする（release-check が公開を止める）。
    'internet_dating_registration' => env('PATO_IDS_REGISTRATION', ''),

    // ポイントの法定要件（資金決済法の適用除外＝有効期限6ヶ月以内）
    'point' => [
        'yen_per_paid_point_x10' => 12, // 1P = ¥1.2
        'expiry_days' => 180,
        'refundable' => false, // 払戻し・換金不可
    ],

    // 利用場所の制限（密室禁止。風営法・安全確保）
    'allowed_venue_kinds' => ['restaurant', 'bar', 'public'],

    // 年齢制限
    'min_age' => 18,
];
