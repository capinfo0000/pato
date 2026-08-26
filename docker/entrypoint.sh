#!/bin/sh
set -e

# =====================================================================
# コンテナ起動時の共通処理。
#
# マイグレーションはここでは実行しない。複数のコンテナ（web / queue /
# scheduler）が同時に起動して同じマイグレーションを走らせると壊れるため、
# デプロイ手順の中で一度だけ実行する（compose.prod.yaml の migrate サービス）。
# =====================================================================

# APP_KEY はセッションと暗号化カラム（生年月日など）の鍵。
# 未設定のまま起動させない。ここで自動生成すると、再起動のたびに鍵が変わり
# 既存の暗号化データが読めなくなる。
if [ -z "${APP_KEY:-}" ]; then
    echo "FATAL: APP_KEY が未設定です。php artisan key:generate --show で生成し、環境変数に設定してください。" >&2
    exit 1
fi

# 書き込み先が無いと起動直後に落ちる（ボリュームを新規に張ったとき）
mkdir -p \
    storage/framework/cache \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache

# 設定・ルート・ビューをキャッシュして起動を速くする。
# 環境変数が変わったらコンテナを作り直す前提（本番で .env を書き換えない）
php artisan config:cache
php artisan route:cache
php artisan view:cache

exec "$@"
