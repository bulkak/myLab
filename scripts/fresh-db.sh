#!/usr/bin/env bash
# Полный сброс данных: тома PostgreSQL, Redis, RabbitMQ + накат миграций.
# Использование: из корня репозитория — ./scripts/fresh-db.sh
#                с очисткой загруженных файлов — ./scripts/fresh-db.sh --with-uploads

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

WITH_UPLOADS=false
for arg in "$@"; do
  case "$arg" in
    --with-uploads) WITH_UPLOADS=true ;;
    -h|--help)
      echo "Usage: $0 [--with-uploads]"
      echo "  --with-uploads  удалить файлы в ./uploads (кроме .gitkeep)"
      exit 0
      ;;
  esac
done

echo "==> Останавливаем контейнеры и удаляем тома (postgres_data, redis_data, rabbitmq_data)"
docker-compose down -v

if [[ "$WITH_UPLOADS" == true ]]; then
  echo "==> Очищаем ./uploads"
  find "$ROOT/uploads" -mindepth 1 ! -name '.gitkeep' -delete 2>/dev/null || true
fi

echo "==> Запускаем стек"
docker-compose up -d

echo "==> Ждём готовности PostgreSQL"
for _ in $(seq 1 60); do
  if docker-compose exec -T postgres pg_isready -U postgres -q 2>/dev/null; then
    break
  fi
  sleep 1
done

echo "==> Composer install"
docker-compose exec -T app composer install --no-interaction --prefer-dist --optimize-autoloader

echo "==> Doctrine migrations (чистая схема)"
docker-compose exec -T app php bin/console doctrine:migrations:migrate --no-interaction

echo "==> Готово: http://localhost:8090 — регистрация с нуля."
echo "    RabbitMQ UI: http://localhost:15673"
