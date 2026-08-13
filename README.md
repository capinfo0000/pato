# pato岡山（仮）

ギャラ飲みマッチングアプリ「pato」を参考にした、**岡山エリア限定のエンタメ会食
マッチングサービス**。現在は**設計フェーズ**（要件・アーキ・ER・法務・ハーネスを整備中）。

- バックエンド: **Laravel 11 モノリス（コアサーバ）**
- クライアントMVP: **Web（レスポンシブ / PWA）**
- MVPスコープ: **patoコール（複数名の呼び出し）先行**

> ⚠️ 風営法・職業安定法・資金決済法などの論点を含む。実装・運用は
> `docs/04_legal_compliance.md` の前提に沿い、本番前に弁護士レビューを受けること。
> 性的サービスの斡旋・売買春を想起させる機能・文言は一切実装しない。

## ドキュメント

| ファイル | 内容 |
|---|---|
| [CLAUDE.md](./CLAUDE.md) | Claude Code / 開発者向けプロジェクトガイド |
| [docs/00_requirements.md](./docs/00_requirements.md) | 要件定義書 |
| [docs/01_architecture_mvc.md](./docs/01_architecture_mvc.md) | MVC・レイヤー・ディレクトリ設計 |
| [docs/02_er_diagram.md](./docs/02_er_diagram.md) | ER図（Mermaid）とテーブル定義 |
| [docs/03_decision_log.md](./docs/03_decision_log.md) | 意思決定ログ / 調査サマリ |
| [docs/04_legal_compliance.md](./docs/04_legal_compliance.md) | 法務チェックリスト |
| [docs/05_dev_harness.md](./docs/05_dev_harness.md) | 開発ハーネス（テスト/CI/フック） |
| [docs/06_pricing_model.md](./docs/06_pricing_model.md) | 料金・テイクレート・キャスト報酬モデル |
| [docs/07_release_gate.md](./docs/07_release_gate.md) | リリースゲート（公開前提条件） |

## セットアップ（bootstrap 後）

```bash
make setup   # 依存インストール + .env + migrate + seed
make serve   # 開発サーバ起動
make ci      # lint + test
```

Laravel 本体は未 scaffold。導入手順は [docs/05_dev_harness.md](./docs/05_dev_harness.md)。
