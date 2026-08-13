# 引き継ぎ — pato岡山（仮）

Web セッションからローカルセッションへの引き継ぎメモ。
**まずこれと `CLAUDE.md`、`docs/` を読んでください。**

- リポジトリ: `capinfo0000/pato`
- 作業ブランチ: `claude/pato-service-inquiry-hmw8zp`
- 最終状態: テスト **213 passed**（Pint 通過）

---

## 1. ローカルで動かす

```bash
composer install                       # PHP 8.4+ が必要（下記）
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed
php artisan serve                      # http://127.0.0.1:8000
```

デモアカウント（`DemoSeeder`）:

| ロール | ログイン |
|---|---|
| ゲスト | `guest@example.com` / `password` |
| キャスト | `cast0@example.com` / `password` |
| 運営 | `admin@example.com` / `password` |

### PHP は 8.4 以上が必須

`composer.lock` が symfony 8.1 系で解決されており、**PHP 8.3 では `composer install` が通りません**
（CI で実際に落ちて発覚）。8.3 で動かしたい場合は symfony を 7 系へ落とす必要があります。

---

## 2. この ZIP に入っていないもの

### `.env`（意図的に除外）

秘密鍵が入るファイルを ZIP に同梱しない方針です（`docs/08_security.md`）。
上の手順で作り直したうえで、Stripe のキーを追記してください。**キーはチャットで
お渡ししたものがそのまま使えます**（いずれもテストモード）。

```
STRIPE_PUBLISHABLE_KEY=pk_test_...     # 未設定だとカード入力欄が出ずデモ画面になる
STRIPE_SECRET=sk_test_...              # 未設定だと Fake ゲートウェイになる
STRIPE_WEBHOOK_SECRET=                 # 未取得。下記 §4
```

### `vendor/`

`composer install` で復元してください。

---

## 3. 実装の現状

### 動くもの

**ゲスト**: 会員登録 → 本人確認(eKYC) → ホーム → 条件入力 → 確認(見積+初回注意喚起) →
作成(与信ホールド) → 成立待ち → 合流開始 / おひねり / 完了 / キャンセル。
探す(絞り込み検索・キャスト詳細)、注文履歴、ポイント購入(カード入力+3Dセキュア)、ランキング、メッセージ。

**キャスト**: 募集一覧 → 参加表明、在席ステータス切替、審査申込、出金申請。

**運営**: ダッシュボード(GMV/テイクレート/需給)、審査キュー、通報・制裁、SOS、精算承認、料金マスタ編集。

**バッチ**: `pato:expire-calls`（5分毎）/ `pato:rankings`（日次）。いずれもキュー経由。

### 金銭まわりの要点（触るときは必ず読む）

| 決まりごと | 場所 |
|---|---|
| 残高は台帳(`point_transactions`)の合算。残高カラムを持たない | `app/Domain/Point/` |
| 「残高確認 → 引き落とし」は必ず `loadForUpdate()`。トランザクション外だと例外 | `EloquentWalletRepository` |
| カード番号は DOM にもサーバにも入らない（Stripe Elements の iframe） | `resources/views/points/index.blade.php` |
| 3Dセキュアの確定は決済IDをクライアントに送らせない（セッションが持つ） | `PointController::confirm()` |
| 確定前に PSP へ問い合わせ、状態と金額の両方を突き合わせる | `PurchasePointService::finalize()` |
| Webhook の門番は署名検証のみ。シークレット未設定なら全拒否 | `StripeSignatureVerifier` |
| 返金は累計額との差分だけ回収（分割返金を取りこぼさない） | `RefundPointService` |
| テストは実キーを見ない（`$_SERVER`/`$_ENV`/`getenv` を空にする） | `tests/bootstrap.php` |

### 実 Stripe（テストモード）で確認済み

```
成功カード     => succeeded
3DS要求カード  => requires_action + client_secret
拒否カード     => RuntimeException

¥6,000 購入 → 5,000P 付与
¥3,000 返金 → 2,500P 回収（ちょうど半分）
再送         → 二重回収しない
```

---

## 4. 次にやること

### すぐ

1. **`STRIPE_WEBHOOK_SECRET` の取得**
   - ローカル: `stripe listen --forward-to localhost:8000/webhooks/stripe` で即発行される
   - 本番: デプロイ後に `https://<ドメイン>/webhooks/stripe` を登録して発行
   - イベントは `payment_intent.succeeded` / `charge.refunded` / `charge.dispute.created`、
     送信元は **お客様のアカウント**（連結アカウントではない）、ペイロードは **スナップショット**

2. **デプロイ**（`docs/09_deployment.md`）
   - サーバー側: DNS の A レコードを向ける、80/443 を開放、3306・6379 は開けない
   - `cp .env.production.example .env.production && chmod 600 .env.production` → 値を埋める
   - `./docker/deploy.sh`
   - ⚠️ 既存の nginx/Apache が 80 番を使っていると Caddy と衝突する。その場合は
     compose から caddy を外し、既存プロキシの背後に web を置く

### 残タスク

| # | 内容 |
|---|---|
| 16 | eKYC のレスポンスマッピングを設定化（`HttpEkycProvider::$map` → config） |
| 18 | **Laravel 12 へ更新**。11.x に未修正の高危険度勧告（CRLF インジェクション、CVE-2026-48019）。修正は 12.60.0 以降のみ |
| 22 | PHPStan(Larastan) 導入。Makefile と docs は使う前提だが未導入 |
| 23 | デプロイ実行（Docker 一式は作成済み、実機未検証） |

### 公開前に必須（人の手続き。コードでは代替できない）

- **インターネット異性紹介事業の届出**（岡山県公安委員会）。他社の番号は使用不可
- **弁護士レビュー**（風営法・職業安定法・資金決済法）
- 特商法表記の事業者情報を `.env` に設定

`php artisan pato:release-check` が、コードで確認できる分を機械的に検証します。

---

## 5. 未検証・既知の弱点

- **Docker イメージは実機でビルドしていない**（Web セッションに Docker デーモンが無かった）。
  CI でビルドは通ったが、`docker compose up` 一式の起動は未確認
- 無停止デプロイではない（入れ替え時に数秒落ちる）
- CSP に `'unsafe-inline'` が残っている（nonce 方式へ移行すべき）
- 管理者アカウントの二要素認証が無い
- 第三者ペネトレーションテスト未実施
- 不正検知（短時間の大量チャージ、同一カードの多数アカウント利用）が無い

詳細は `docs/08_security.md` §7 と `docs/09_deployment.md` §7。
