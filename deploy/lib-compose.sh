#!/usr/bin/env bash
# Shared helpers for deploy scripts. Source from repo root after cd.
# shellcheck shell=bash

load_vps_env() {
  local env_file=$1
  if [[ ! -f "$env_file" ]]; then
    echo "Env file not found: $env_file" >&2
    return 1
  fi
  set -a
  # shellcheck disable=SC1090
  source "$env_file"
  set +a
}

# Use after load_vps_env: substitutes ${APP_SECRET} etc. like docker compose --env-file.
docker_compose() {
  if docker compose version >/dev/null 2>&1; then
    docker compose "$@"
  elif command -v docker-compose >/dev/null 2>&1; then
    docker-compose "$@"
  else
    echo "Need Docker Compose: install plugin (docker compose) or package docker-compose." >&2
    return 1
  fi
}
