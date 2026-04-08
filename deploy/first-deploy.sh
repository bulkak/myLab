#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

ENV_FILE="${1:-$ROOT/.env.vps}"

if [[ ! -f "$ENV_FILE" ]]; then
  echo "Env file not found: $ENV_FILE"
  echo "Create it from deploy/.env.vps.example"
  exit 1
fi

if [[ ! -f "$ROOT/backend/.env" ]]; then
  if [[ -f "$ROOT/backend/.env.example" ]]; then
    echo "==> Creating backend/.env from backend/.env.example (not in git on server)"
    cp "$ROOT/backend/.env.example" "$ROOT/backend/.env"
  else
    echo "Missing $ROOT/backend/.env and backend/.env.example" >&2
    exit 1
  fi
fi

# shellcheck source=deploy/lib-compose.sh
source "$ROOT/deploy/lib-compose.sh"
load_vps_env "$ENV_FILE"

COMPOSE_FILES=( -f docker-compose.yml -f docker-compose.prod.yml )

echo "==> Starting stack in production mode"
docker_compose "${COMPOSE_FILES[@]}" up -d --build

echo "==> Waiting for PostgreSQL to become ready"
for _ in $(seq 1 90); do
  if docker_compose "${COMPOSE_FILES[@]}" exec -T postgres sh -c 'pg_isready -U "${POSTGRES_USER:-postgres}" -q' 2>/dev/null; then
    break
  fi
  sleep 1
done

echo "==> Installing PHP dependencies"
docker_compose "${COMPOSE_FILES[@]}" exec -T app composer install --no-interaction --prefer-dist --optimize-autoloader

echo "==> Running database migrations"
docker_compose "${COMPOSE_FILES[@]}" exec -T app php bin/console doctrine:migrations:migrate --no-interaction

echo "==> Done"
echo "Check: (from this directory, after: set -a && source \"$ENV_FILE\" && set +a)"
echo "  docker compose -f docker-compose.yml -f docker-compose.prod.yml ps"
echo "  or: docker-compose -f docker-compose.yml -f docker-compose.prod.yml ps"
