#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

ENV_FILE="${1:-$ROOT/.env.vps}"
BACKUP_DIR="${2:-$ROOT/backups}"
RETENTION_DAYS="${RETENTION_DAYS:-14}"
STAMP="$(date +%Y%m%d_%H%M%S)"
TARGET_DIR="$BACKUP_DIR/$STAMP"

if [[ ! -f "$ENV_FILE" ]]; then
  echo "Env file not found: $ENV_FILE"
  exit 1
fi

# shellcheck source=deploy/lib-compose.sh
source "$ROOT/deploy/lib-compose.sh"
load_vps_env "$ENV_FILE"

mkdir -p "$TARGET_DIR"

echo "==> Backup directory: $TARGET_DIR"

COMPOSE_FILES=( -f docker-compose.yml -f docker-compose.prod.yml )

echo "==> Dumping PostgreSQL"
docker_compose "${COMPOSE_FILES[@]}" exec -T postgres pg_dump -U postgres -d mylab | gzip -9 > "$TARGET_DIR/mylab.sql.gz"

echo "==> Archiving uploads"
tar -C "$ROOT" -czf "$TARGET_DIR/uploads.tar.gz" uploads

echo "==> Saving compose and env snapshot"
cp "$ROOT/docker-compose.yml" "$TARGET_DIR/"
cp "$ROOT/docker-compose.prod.yml" "$TARGET_DIR/"
if [[ -f "$ENV_FILE" ]]; then
  cp "$ENV_FILE" "$TARGET_DIR/.env.vps.snapshot"
fi

echo "==> Applying retention: keep last $RETENTION_DAYS days"
find "$BACKUP_DIR" -mindepth 1 -maxdepth 1 -type d -mtime +"$RETENTION_DAYS" -exec rm -rf {} +

echo "==> Backup finished"
