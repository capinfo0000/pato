# 09. デプロイ手順

pato岡山（仮）を公開サーバーへ載せる手順。**Docker Compose** で
`caddy → nginx → php-fpm → MySQL / Redis` を一式起動する。

> ⚠️ このドキュメントは「動く状態にする」ための手順です。**公開してよいか**は別問題で、
> `docs/07_release_gate.md` のリリースゲート（弁護士レビュー・異性紹介事業の届出）を
> 満たすまで一般公開してはいけません。検証環境は Basic 認証等でクローズドにしてください。

---

## 1. 構成

```
インターネット
   │ 443 (TLS)
   ▼
 caddy        Let's Encrypt の証明書を自動取得・自動更新
   │ 80
   ▼
 web (nginx)  静的ファイルを直接返す。public はイメージに焼いてある
   │ 9000
   ▼
 app (php-fpm)  ── mysql   (永続ボリューム)
                 └ redis   (セッション・キュー・キャッシュ)

 queue      キュー消化（与信解放・プッシュ送信・ランキング集計）
 scheduler  定期実行（5分ごとの与信解放、日次のランキング）
 migrate    デプロイ時に1回だけ動くワンショット
```

すべて同じイメージ（`pato-app`）から起動する。ビルドは1回。

**なぜ migrate を分けているか**: web / queue / scheduler が同時に起動して
同じマイグレーションを走らせると壊れる。デプロイ手順の中で一度だけ実行する。

---

## 2. サーバー側の前提

| 項目 | 要件 |
|---|---|
| OS | Docker が動く Linux（Ubuntu 22.04 LTS 以降を想定） |
| Docker | Engine 24 以降 + Compose v2 |
| メモリ | 2GB 以上（MySQL と Redis を同居させるため） |
| ポート | 80 / 443 を開放。**3306 と 6379 は開けない**（コンテナ間だけで通る） |
| DNS | 公開ドメインの A レコードをこのサーバーの IP に向けておく |

TLS 証明書の取得には、**80番ポートがインターネットから到達できること**が必要。
先に DNS を通しておかないと Caddy が証明書を取れず、HTTPS が上がらない。

---

## 3. 初回セットアップ

```bash
git clone <リポジトリ> /srv/pato
cd /srv/pato

cp .env.production.example .env.production
chmod 600 .env.production      # DBパスワードと Stripe 秘密鍵が入る。必須
```

### 3.1 .env.production を埋める

**必ず埋める**（空だと起動しない、または公開できない）:

| 変数 | 値 |
|---|---|
| `APP_KEY` | `php -r 'echo "base64:".base64_encode(random_bytes(32)),PHP_EOL;'` で生成 |
| `APP_URL` | `https://<ドメイン>` |
| `DOMAIN` | 証明書を取るドメイン |
| `LETSENCRYPT_EMAIL` | 失効通知の宛先 |
| `DB_HOST` | `mysql`（compose のサービス名） |
| `DB_USERNAME` / `DB_PASSWORD` | 任意。**推測されない値にする** |
| `REDIS_HOST` | `redis` |
| `REDIS_PASSWORD` | 任意。空だと Redis が起動しない |

> `APP_KEY` はセッションと暗号化カラム（生年月日など）の鍵。
> **後から変えると既存の暗号化データが読めなくなる**ので、決めたら変えない。
> 起動時に未設定なら entrypoint がその場で落とす（黙って自動生成させない）。

**外部サービス**（未設定のうちは Fake にフォールバックし、`pato:release-check` が公開を止める）:

| 変数 | 取得元 |
|---|---|
| `STRIPE_PUBLISHABLE_KEY` | `pk_...` ブラウザに出る。未設定だとカード入力欄が出ない |
| `STRIPE_SECRET` | `sk_...` 漏れると任意の課金・返金ができる |
| `STRIPE_WEBHOOK_SECRET` | `whsec_...` §5 で取得。未設定だと返金通知を全拒否 |
| `EKYC_*` | eKYC ベンダ確定後 |
| `VAPID_*` | `php artisan pato:vapid-keys` で生成 |

**事業者情報**（特商法表記。空だと `pato:release-check` が落ちる）:
`PATO_OPERATOR_NAME` / `PATO_OPERATOR_REP` / `PATO_OPERATOR_ADDRESS` / `PATO_CONTACT_EMAIL`

### 3.2 起動

```bash
./docker/deploy.sh
```

初回は証明書の取得に十数秒かかる。`docker compose ... logs caddy` で
`certificate obtained successfully` が出れば成功。

---

## 4. 2回目以降のデプロイ

```bash
make deploy        # = ./docker/deploy.sh --pull
```

スクリプトがやること:

1. `git pull --ff-only`
2. イメージをビルド（タグはコミットハッシュ。切り戻せる）
3. **マイグレーションを1回だけ実行**
4. コンテナ入れ替え
5. `pato:release-check`
6. `/healthz` が応答するまで確認（応答しなければ非ゼロで終了）

マイグレーションが失敗したらそこで止まる。壊れたスキーマのまま新しいコードが
公開されないようにするため。

> **短時間のダウンタイムがあります。** 入れ替え中の数秒はリクエストが落ちます。
> 無停止にするなら web を2台にして順に入れ替える構成が要りますが、MVP では過剰です。

### 切り戻し

```bash
APP_VERSION=<前のコミットハッシュ> docker compose --env-file .env.production \
  -f compose.prod.yaml up -d
```

**マイグレーションは自動では戻りません。** カラム削除を含む変更を戻すときは
先にデータを確認すること。

---

## 5. Stripe Webhook の登録

デプロイして HTTPS が通ってから行う。

1. Stripe ダッシュボード > 開発者 > Webhook > エンドポイントを追加
2. イベントの送信元: **お客様のアカウント**（連結アカウントではない）
3. ペイロードのスタイル: **スナップショット**
4. URL: `https://<ドメイン>/webhooks/stripe`
5. イベント: `payment_intent.succeeded` / `charge.refunded` / `charge.dispute.created`
6. 発行された `whsec_...` を `.env.production` の `STRIPE_WEBHOOK_SECRET` に入れ、
   `make deploy` で反映（環境変数の変更はコンテナの作り直しが必要）

確認は Stripe ダッシュボードの「送信されたイベント」で 200 が返っていること。
署名が合わないと 400 が返る。

---

## 6. 運用

### ログ

```bash
make deploy-logs                                  # 全サービス
docker compose --env-file .env.production -f compose.prod.yaml logs -f app
```

アプリのログは `daily` チャンネルで `storage/logs`（ボリューム永続）にも出る。
**PII は `MaskPii` が自動マスク**する（docs/08_security.md §2）。

注視すべきログ:

| キー | 意味 |
|---|---|
| `refund.balance_shortfall` | 返金したがポイントが使用済みで残高がマイナス。運営判断が要る |
| `stripe.webhook.orphan_payment` | 入金があるのに購入記録が無い。取りこぼし |
| `stripe.webhook.invalid_signature` | 署名不正。連続するなら攻撃を疑う |
| `purchase.amount_mismatch` | 決済額と商品価格の不一致。改竄の試み |

### バックアップ

```bash
docker compose --env-file .env.production -f compose.prod.yaml \
  exec -T mysql mysqldump -u"$DB_USERNAME" -p"$DB_PASSWORD" --single-transaction pato \
  | gzip > /backup/pato-$(date +%F).sql.gz
```

**ポイント台帳（`point_transactions`）は追記のみで残高の真実そのもの**なので、
失うと復元できない。cron で日次取得し、別ホストへ退避すること。

### 定期実行の確認

```bash
docker compose --env-file .env.production -f compose.prod.yaml exec -T app php artisan schedule:list
```

`scheduler` コンテナが落ちていると、与信が解放されずゲストの残高が
使えないままになる。監視対象にすること。

---

## 7. まだやっていないこと

- [ ] 無停止デプロイ（現状は入れ替え時に数秒落ちる）
- [ ] DB の自動バックアップ（コマンドは上にあるが cron 未設定）
- [ ] ログの外部集約（現状はサーバー内のみ）
- [ ] 監視・アラート（`/healthz` を外形監視に登録する）
- [ ] WAF / DDoS 対策
- [ ] 秘密情報の管理（現状は `.env.production` の直置き。Secrets Manager 等へ）
- [ ] 検証環境のアクセス制限（公開前は Basic 認証等で閉じること）

`docs/08_security.md` §7 と合わせて確認すること。
