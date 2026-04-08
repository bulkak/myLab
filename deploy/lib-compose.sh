#!/usr/bin/env bash
# Shared helpers for deploy scripts. Source from repo root after cd.
# shellcheck shell=bash

load_vps_env() {
  local env_file=$1
  if [[ ! -f "$env_file" ]]; then
    echo "Env file not found: $env_file" >&2
    return 1
  fi
  # Absolute path for docker compose / docker-compose --env-file (interpolation of ${APP_SECRET:?…} in YAML)
  export COMPOSE_ENV_FILE
  COMPOSE_ENV_FILE="$(cd "$(dirname "$env_file")" && pwd)/$(basename "$env_file")"
  set -a
  # shellcheck disable=SC1090
  source "$env_file"
  set +a
}

# Passes --env-file when COMPOSE_ENV_FILE is set so ${VAR} in docker-compose*.yml resolves without a root .env file.
docker_compose() {
  local env_args=()
  if [[ -n "${COMPOSE_ENV_FILE:-}" && -f "${COMPOSE_ENV_FILE}" ]]; then
    env_args=( --env-file "$COMPOSE_ENV_FILE" )
  fi
  if docker compose version >/dev/null 2>&1; then
    docker compose "${env_args[@]}" "$@"
  elif command -v docker-compose >/dev/null 2>&1; then
    docker-compose "${env_args[@]}" "$@"
  else
    echo "Need Docker Compose: install plugin (docker compose) or package docker-compose." >&2
    return 1
  fi
}
