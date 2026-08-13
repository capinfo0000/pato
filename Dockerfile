# syntax=docker/dockerfile:1

# =====================================================================
# pato岡山（仮） 本番イメージ
#
# 方針:
# - 依存の解決とランタイムを分け、最終イメージに composer やビルド道具を残さない
# - **root で動かさない**。アプリは www-data として実行する
# - **秘密情報をイメージに焼かない**。.env は実行時に渡す（docs/08_security.md）
# - opcache を有効化し、本番ではファイル変更チェックを切る（性能と改竄耐性）
# =====================================================================

# ---------- 依存解決 ----------
FROM composer:2 AS vendor

WORKDIR /app
COPY composer.json composer.lock ./
# 本番に開発用パッケージを持ち込まない。スクリプトも走らせない（artisan がまだ無いため）
RUN composer install \
      --no-dev \
      --no-scripts \
      --no-interaction \
      --prefer-dist \
      --optimize-autoloader

# ---------- ランタイム ----------
FROM php:8.3-fpm-bookworm AS app

# gmp/bcmath: Web Push の VAPID 署名（無いと極端に遅くなる）
# pdo_mysql: 本番DB / redis: キュー・セッション・キャッシュ
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        libzip-dev libgmp-dev unzip; \
    docker-php-ext-install -j"$(nproc)" \
        pdo_mysql bcmath gmp zip pcntl opcache; \
    pecl install redis; \
    docker-php-ext-enable redis; \
    apt-get purge -y --auto-remove libzip-dev libgmp-dev; \
    rm -rf /var/lib/apt/lists/*

COPY docker/php/php.ini /usr/local/etc/php/conf.d/pato.ini
COPY docker/php/www.conf /usr/local/etc/php-fpm.d/zz-pato.conf

WORKDIR /var/www/html

COPY --from=vendor /app/vendor ./vendor
COPY . .

# storage と bootstrap/cache だけが書き込み可能。それ以外はアプリから書けない
RUN set -eux; \
    rm -rf .env .env.example .env.production.example tests; \
    mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache; \
    chown -R www-data:www-data storage bootstrap/cache; \
    chmod -R 775 storage bootstrap/cache

COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

USER www-data

EXPOSE 9000
ENTRYPOINT ["entrypoint"]
CMD ["php-fpm"]

# ---------- 静的配信（nginx） ----------
# public/ をボリュームで共有すると、再デプロイしても古いファイルが残る
# （名前付きボリュームはイメージ側の更新を取り込まない）。イメージに焼いて避ける。
FROM nginx:1.27-alpine AS web

COPY docker/nginx/app.conf /etc/nginx/conf.d/default.conf
COPY --from=app /var/www/html/public /var/www/html/public

EXPOSE 80
