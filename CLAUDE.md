# CLAUDE.md — pato岡山（仮）プロジェクトガイド

このファイルは Claude Code / 開発者が本リポジトリで作業するときの共通ガイドです。
新しくタスクに入るときは、まずこのファイルと `docs/` を読んでください。

---

## 1. プロジェクト概要

**pato岡山（仮）** は、ギャラ飲みマッチングアプリ「pato」を参考にした、**岡山エリア限定のエンタメ会食マッチングサービス**です。

- ゲスト（呼ぶ側）が、審査を通過したキャスト（呼ばれる側）を、会食・飲み会の場に**時間課金のポイント制**で呼べる。
- 運営は契約当事者にならず、**キャストとゲストの間の「エンタメスキル提供契約」の成立機会を提供する仲介**という建て付け。
- MVPは **patoコール（複数名の呼び出し）を Web（レスポンシブ / PWA）で先行リリース**。

> ⚠️ 本サービスは風営法・職業安定法・資金決済法などの論点を含みます。実装は必ず
> `docs/04_legal_compliance.md` の前提に沿って行い、本番運用前に弁護士レビューを受けること。
> 性的サービスの斡旋・売買春を想起させる機能・文言は一切実装しない。

---

## 2. 技術スタック

| レイヤー | 採用 | 備考 |
|---|---|---|
| 言語 | PHP 8.4+ | symfony 8.1 系が 8.4.1 以上を要求 |
| フレームワーク | Laravel 11 (MVC モノリス / コアサーバ) | |
| ビュー | Blade + Vite（サーバレンダリング）| SPAにはしない |
| PWA | Service Worker + Web Push | インストール可能なWebアプリ |
| DB | MySQL 8.0（開発は sqlite 可）| |
| キャッシュ/キュー | Redis（queue, session, cache）| |
| 認証 | Laravel Breeze/Fortify ベース | ゲスト・キャスト・管理の3ロール |
| 決済 | 外部PSP（Stripe等）を Adapter 経由で | 直接依存しない |
| 本人確認 | eKYC ベンダを Adapter 経由で | |
| テスト | Pest / PHPUnit | |
| 静的解析 | PHPStan (Larastan) + Laravel Pint | |

詳細は `docs/01_architecture_mvc.md`。

---

## 3. ディレクトリ規約（重要）

Controller は薄く保ち、業務ロジックは **Service / Action** に置く。詳細は
`docs/01_architecture_mvc.md`。

```
app/
  Http/
    Controllers/     # 薄い。入出力の受け渡しのみ
    Requests/        # バリデーション（FormRequest）
    Resources/       # APIレスポンス整形
    Middleware/
  Domain/            # ドメインごとに凝集（下記）
    Call/            # patoコール（MVPの中心）
    Cast/            # キャスト・審査
    Guest/           # ゲスト
    Point/           # ポイント台帳・課金
    Payout/          # キャスト精算
    Messaging/       # スレッド/メッセージ
    Trust/           # 本人確認・通報・安全
  Models/            # Eloquent モデル
  Policies/          # 認可
  Jobs/ Events/ Listeners/
```

各 `Domain/*` は `Services/`, `Actions/`, `DTO/` を持つ。

---

## 4. よく使うコマンド

`Makefile` にまとめてある。

```
make setup     # 依存インストール + .env + migrate + seed
make serve     # 開発サーバ起動
make test      # テスト（Pest/PHPUnit）
make lint      # Pint + PHPStan
make ci        # lint + test（CIと同じ）
make fresh     # DBリセット + seed
```

※ 現状リポジトリは設計フェーズ。Laravel 本体はまだ scaffold 前なので、
`make setup` の中身は初回 bootstrap 手順に沿って埋めていく（`docs/05_dev_harness.md`）。

---

## 5. 開発上の約束

- **金額・ポイントは必ず整数（最小単位＝1ポイント）で扱う。** 浮動小数点で金額計算しない。
- **ポイント残高は「台帳（point_transactions）の合算」で表現する。** 残高カラムを直接書き換えない（監査可能性のため）。
- **状態遷移（call のステータス等）は Service 経由でのみ変更する。** モデルを直接 `save()` して状態を飛ばさない。
- **PII（本人確認書類・電話番号等）はログに出さない。** マスキング必須。
- **ロールは guest / cast / admin。** 認可は Policy で。Controller に `if ($user->role...)` を散らさない。
- 破壊的操作（本番DB・外部送信）は必ず確認を取る。
- **カード情報は絶対に保存しない。** 決済は PSP のトークンのみ扱う（`docs/08_security.md`）。
- **「残高を確認してから引き落とす」処理は必ず `loadForUpdate()` を使う。**
  `load()` で確認すると同時実行で二重に引き落とせる。

---

## 6. ドキュメント一覧（`docs/`）

| ファイル | 内容 |
|---|---|
| `00_requirements.md` | 要件定義書（機能/非機能/ユースケース） |
| `01_architecture_mvc.md` | MVC・レイヤー・ディレクトリ設計 |
| `02_er_diagram.md` | ER図（Mermaid）とテーブル定義 |
| `03_decision_log.md` | 意思決定ログ（調査サマリ＝いわゆる「チャットログ」） |
| `04_legal_compliance.md` | 法務チェックリスト（出会い系/風営法/職安法/資金決済法） |
| `05_dev_harness.md` | 開発ハーネス（テスト/CI/フック）の使い方 |
| `06_pricing_model.md` | 料金・テイクレート・キャスト報酬モデル（seedの元） |
| `07_release_gate.md` | リリースゲート（届出手続き・弁護士レビュー・自動チェック） |
| `08_security.md` | セキュリティ方針（金銭・PII・Web層・監査） |
| `09_deployment.md` | デプロイ手順（Docker Compose・Webhook登録・運用） |
