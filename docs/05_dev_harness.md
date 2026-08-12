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

## 7. まだ無いもの（TODO）

- [ ] Laravel 本体（bootstrap 未実施）
- [ ] `.env.example` の確定（DB/Redis/PSP/eKYC のキー）
- [ ] Fake アダプタ実装
- [ ] seeders（岡山エリア・クラス・ポイント商品）
- [ ] PWA（manifest / service worker）
