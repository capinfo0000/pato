# pato岡山（仮） 開発ハーネス
# Laravel モノリス（コアサーバ）用の常用タスク。
# Laravel 本体の bootstrap は docs/05_dev_harness.md を参照。

.PHONY: setup serve test lint ci fresh help

help:
	@echo "make setup   - 依存インストール + .env + migrate + seed"
	@echo "make serve   - 開発サーバ起動 (artisan serve + vite)"
	@echo "make test    - テスト実行 (pest/phpunit)"
	@echo "make lint    - Pint(整形チェック) + PHPStan(静的解析)"
	@echo "make ci      - lint + test (CIと同一)"
	@echo "make fresh   - DBリセット + seed"

setup:
	@test -f composer.json || (echo ">> Laravel 未導入。docs/05_dev_harness.md の bootstrap を先に実行" && exit 1)
	composer install
	@test -f .env || cp .env.example .env
	@test -f .env && grep -q '^APP_KEY=.\+' .env || php artisan key:generate
	[ -f package.json ] && npm install || true
	php artisan migrate --seed

serve:
	php artisan serve & \
	([ -f package.json ] && npm run dev || true)

test:
	@if [ -f vendor/bin/pest ]; then vendor/bin/pest; \
	elif [ -f vendor/bin/phpunit ]; then vendor/bin/phpunit; \
	else echo ">> テストランナー未導入 (docs/05_dev_harness.md)"; fi

lint:
	@if [ -f vendor/bin/pint ]; then vendor/bin/pint --test; else echo ">> Pint 未導入"; fi
	@if [ -f vendor/bin/phpstan ]; then vendor/bin/phpstan analyse --no-progress; else echo ">> PHPStan 未導入"; fi

ci: lint test

fresh:
	php artisan migrate:fresh --seed
