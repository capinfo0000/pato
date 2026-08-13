#!/usr/bin/env bash
#
# pato岡山（仮） デプロイ。サーバー上で実行する。
#
#   ./docker/deploy.sh            現在のブランチをそのままデプロイ
#   ./docker/deploy.sh --pull     git pull してからデプロイ
#
# やっていること: ビルド → マイグレーション → 入れ替え → 健全性確認。
# 途中で失敗したら止まる（set -e）。マイグレーションが失敗した状態で
# 新しいコードに切り替わると、金銭まわりが壊れたまま公開されるため。

set -euo pipefail

cd "$(dirname "$0")/.."

ENV_FILE=".env.production"
COMPOSE=(docker compose --env-file "$ENV_FILE" -f compose.prod.yaml)

if [ ! -f "$ENV_FILE" ]; then
    echo "FATAL: $ENV_FILE がありません。.env.production.example をコピーして値を埋めてください。" >&2
    exit 1
fi

# .env.production が他のユーザーから読めてはいけない（秘密鍵・DBパスワードが入る）
perms="$(stat -c '%a' "$ENV_FILE")"
if [ "${perms: -2}" != "00" ]; then
    echo "FATAL: $ENV_FILE の権限が $perms です。chmod 600 $ENV_FILE を実行してください。" >&2
    exit 1
fi

if [ "${1:-}" = "--pull" ]; then
    echo "==> 最新を取得"
    git pull --ff-only
fi

# イメージのタグにコミットハッシュを使い、切り戻せるようにする
APP_VERSION="$(git rev-parse --short HEAD)"
export APP_VERSION
echo "==> バージョン: $APP_VERSION"

echo "==> ビルド"
"${COMPOSE[@]}" build --pull

echo "==> マイグレーション（1回だけ実行）"
"${COMPOSE[@]}" --profile deploy run --rm migrate

echo "==> 入れ替え"
"${COMPOSE[@]}" up -d --remove-orphans

echo "==> 公開前提条件の確認"
# 失敗しても止めない（届出番号など、人の手続き待ちの項目があるため）
"${COMPOSE[@]}" exec -T app php artisan pato:release-check || true

echo "==> 健全性確認"
for i in $(seq 1 30); do
    if "${COMPOSE[@]}" exec -T web wget -qO- http://localhost/healthz >/dev/null 2>&1; then
        echo "OK: 応答を確認しました"
        echo
        echo "デプロイ完了: $APP_VERSION"
        exit 0
    fi
    sleep 2
done

echo "FATAL: 起動後に /healthz が応答しません。ログを確認してください:" >&2
echo "  ${COMPOSE[*]} logs --tail=100 app web" >&2
exit 1
