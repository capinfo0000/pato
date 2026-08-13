# 開発ハーネス — pato岡山（仮）

「ハーネス」= 開発・テスト・CIを回すための足回り。設計フェーズの現状は Laravel 本体を
scaffold する前なので、**bootstrap 手順 → 常用コマンド → CI → フック**の順で整える。

---

## 1. 前提ツール

- PHP 8.3+ / Composer
- Node.js 20+（Vite / PWA アセット）
- MySQL 8（ローカルは sqlite でも可）/ Redis

---

## 2. 初回 bootstrap（Laravel を入れる）

リポジトリは現在ドキュメントのみ。以下で Laravel を導入する（初回だけ）。

```bash
# ルートに Laravel を展開（既存 docs/ は保持）
composer create-project laravel/laravel:^11.0 tmp-laravel
rsync -a --exclude=.git tmp-laravel/ ./ && rm -rf tmp-laravel

# 開発ツール
composer require --dev pestphp/pest larastan/larastan laravel/pint
php artisan key:generate
```

導入後、`docs/01_architecture_mvc.md` のディレクトリ規約に沿って
`app/Domain/*`, `app/Support/Contracts` 等を作成する。

---

## 3. 常用コマンド（Makefile）

```bash
make setup   # composer/npm install + .env 準備 + migrate + seed
make serve   # php artisan serve + vite
make test    # pest/phpunit
make lint    # pint（整形チェック）+ phpstan（静的解析）
make ci      # lint + test（CIと同一）
make fresh   # migrate:fresh --seed
```

`Makefile` は導入済み。中身は Laravel 導入後にそのまま機能する。

---

## 4. テスト方針

- `tests/Feature/` … ユースケース単位。最初に書くべき代表ケース:
  - `CreateCallTest` … 呼び出し作成でポイントが正しく hold される
  - `MatchingTest` … 定員成立で matched、集合情報が通知される
  - `CompleteCallTest` … 完了で hold が capture され、精算 item が計上される
  - `CancelCallTest` … 成立前キャンセルで hold が release される
  - `PointExpiryTest` … 180日で expire が計上される
  - `IdentityGateTest` … 未確認/18歳未満は作成・参加不可
  - `AreaGateTest` … 岡山エリア外は作成不可
- `tests/Unit/` … `PointBalance`（台帳合算・利用可能残高）、`ContentFilter`（NG検知）。
- 外部依存は Fake: `FakePaymentGateway`, `FakeEkycProvider`, `FakePushSender`。

`PointBalance` は最優先でテストを固める（金銭系のバグは致命的）。

---

## 5. CI

`.github/workflows/ci.yml` を用意。push / PR で以下を実行:
1. PHP セットアップ + Composer install（キャッシュ）
2. `make lint`（Pint --test + PHPStan）
3. `make test`（Pest）

Laravel 導入前でもワークフローは壊れないよう、`vendor/` 不在時はスキップする
ガードを入れてある（導入後に本稼働）。

---

## 6. Claude Code 用フック（任意）

Web セッションで毎回テスト環境を整えたい場合、`.claude/settings.json` に SessionStart
フックを置ける（`session-start-hook` スキル参照）。例: 依存インストールと `.env` 準備を
自動化。導入は Laravel scaffold 後に行う。

---

## 7. 実装済みのドメインコア（フレームワーク非依存）

Laravel 本体の前に、金額クリティカルな**純粋ドメイン層**を先行実装済み。composer + PHPUnit で
すぐ動く（`composer install` → `vendor/bin/phpunit`）。

- `app/Domain/Point/`  … ポイント台帳（`PointBalance` = settled/hold/available 算出、`PointTransaction`）
- `app/Domain/Pricing/` … エリア別・クラス別料金と報酬/取り分（`PricingCalculator`、岡山既定表）
- `app/Domain/Call/`   … 状態遷移（`CallStateMachine`、許可遷移のみ通す）
- `app/Domain/Trust/`  … 入口ゲート（`AccessGate` = 年齢/本人確認/エリア）
- `app/Domain/Call/Support/TipDistributor` … おひねり配分（指定/均等・端数寄せ）
- `app/Domain/Messaging/ContentFilter` … NG検知（連絡先交換/外部誘導/密室/現金/性的）
- `app/Application/Call/CallBillingService` … 作成→与信→完了(精算)/解放/おひねりの整合点
- `app/Support/Contracts` + `Adapters/Fake` … 決済/eKYC/Push の抽象と Fake 実装
- テスト: `tests/Unit/`（39 ケース。台帳、料金実額、状態遷移、ゲート、全体フロー、
  おひねり、NG検知、Fake の冪等性）

### DB マイグレーション / seeder（Laravel 形式で作成済み・要 bootstrap 後に実行）
- `database/migrations/2026_08_12_0000{01..08}_*` … users / profiles・eKYC / 料金マスタ /
  ポイント台帳 / call・明細・参加 / payout / messaging / trust
- `database/seeders/OkayamaMasterSeeder` … 岡山エリア・クラス・エリア別料金・ポイント商品

### Laravel 本体（bootstrap 済み）

Laravel 11 を導入済み。sqlite で即動く。

```bash
composer install
cp .env.example .env && php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed        # 8マイグレーション + 岡山マスタ
vendor/bin/phpunit                # Unit 39 + Feature 3 = 42 緑
```

- Eloquent Model: `app/Models/`（User / PointWallet / PointTransaction / Area /
  ClassTier / AreaClassPrice）
- リポジトリ: `app/Infrastructure/Persistence/`（Price 表→Calculator、ウォレット台帳）
  と契約 `app/Domain/*/Contracts/`
- Feature テスト: `tests/Feature/`（DBの料金→見積、台帳の永続化と残高再構成、エリア限定）

### 動かす（呼ぶフロー）

```bash
php artisan migrate:fresh --seed   # 岡山マスタ + デモデータ
php artisan serve                  # http://127.0.0.1:8000
# ログイン: guest@example.com / password
#  → ホーム(今すぐ呼ぶ/今日会えるキャスト) → 条件入力 → 確認(見積+初回注意喚起) → 与信して成立待ち
```

実装済みの画面/機能:

**ゲスト**
- 会員登録 → 本人確認(eKYC, 18歳未満は拒否) → ホーム（今すぐ呼ぶ/待機キャスト数/今日会えるキャスト）
- 呼ぶ導線: 条件入力 → 確認（見積＋初回注意喚起） → 作成（与信ホールド） → 成立待ち
- 呼び出し詳細から 合流開始 / おひねり / 完了（確定消費＋報酬計上） / キャンセル（解放）
- 探す（絞り込み検索・キャスト詳細＝KPI/バッジ/称号）、注文履歴、ポイント購入＋履歴、ランキング

**キャスト**
- 募集一覧（自分のクラス/エリアに合致）→ 参加表明（任意。定員到達で成立）
- 在席ステータス切替（今すぐ可/本日可/オフライン）、受取ポイント確認

**サービス層**
- `CreateCallService`（ゲート→見積→残高→DB TXで作成＋与信）
- `CallLifecycleService`（参加/成立/開始/完了＋精算/キャンセル/おひねり/期限切れ）
- `PurchasePointService`（PSP課金→台帳へ有償P付与、有効期限180日）
- `RankingService`（ファンポイント付与とランキング集計）
- ロール制御は `role` ミドルウェア（guest/cast/admin）

**バッチ**
```bash
php artisan pato:expire-calls   # 時間切れの呼び出しを不成立にして与信解放（5分毎）
php artisan pato:rankings       # ランキング集計（日次）
```

**追加実装（全て Feature テスト済み）**
- メッセージ: 呼び出しグループチャット / コンシェルジュ(公式) / 一覧フィルタ・検索 / NG検知
- キャスト審査: 申込 → 写真審査 → 面談 → 承認(クラス付与)・却下、管理キュー、3か月で再申込
- 精算: 出金申請(下限3,000P・早期振込手数料) → 承認 → 送金完了で payout_debit 計上
- 指名(優先マッチング +20%) / 延長(30分単位の追加与信) / レビュー(星＋タグ→KPI再計算)
- PWA: manifest.json / Service Worker(オフラインシェル・GET のみキャッシュ) / オフラインページ
- 通報・制裁: 通報フォーム → 管理キュー(対応中/対応済/却下) → アカウント停止・解除。
  停止中は呼び出し作成・参加ができない。NG検知メッセージも管理画面で確認できる
- SOS: 合流中の緊急連絡(110番案内を先に提示) → 管理者へ即時通知 → 受信確認/対応完了。
  位置メモは PII として非シリアライズ
- 管理: ダッシュボード(GMV・テイクレート実績・供給/需要・要対応件数)、
  料金マスタ編集(単価/テイクレート/加算率)、エリアの提供可否切替

**実アダプタ（認証情報があれば自動で切り替わる）**
- 決済: `StripePaymentGateway`（PaymentIntents、冪等キーを Stripe にも渡す。カード番号は扱わない）
- 本人確認: `HttpEkycProvider`（結果と年齢要件の充足のみ持ち帰る。生年月日は保持しない）
- 通知: `WebPushSender` ＋ `SendPushJob`（キュー送信、410/404 の購読は自動削除）
- 未設定なら Fake にフォールバック。本番で Fake のままなら `pato:release-check` が止める

**本番構成**
```bash
php artisan queue:work redis      # SendPushJob / ランキング集計 / 期限切れ解放
php artisan schedule:work         # 5分毎: 与信解放 / 日次: ランキング（いずれもJob経由）
curl /healthz                     # DB・キャッシュの疎通（LB・監視用）
```
- 監査ログ `audit_logs`: 制裁・精算承認/送金・料金変更・審査結果を追記のみで記録
- ログは `MaskPii` プロセッサでメール/電話/生年月日/トークンをマスク（daily・single チャンネル）
- 本番の環境変数は `.env.production.example` を参照

**デモアカウント**
```
ゲスト  guest@example.com / password
キャスト cast0@example.com / password
運営    admin@example.com / password
```

## 8. まだ無いもの（TODO）

- [ ] Web Push の VAPID 署名（web-push ライブラリ導入。現在は購読管理と送信経路まで）
- [ ] Stripe Webhook（非同期の決済確定・返金イベントの取り込み）
- [ ] eKYC ベンダ確定後のレスポンスマッピング調整（`HttpEkycProvider` の `$map`）
- [ ] コパト（1対1）、つぶやき、クーポン/リファラル、まとめてギフト
- [ ] **リリースゲート（docs/07）: 弁護士レビューと異性紹介事業の届出**（人の手続き）
