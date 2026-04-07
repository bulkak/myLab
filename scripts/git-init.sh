#!/usr/bin/env bash
# Инициализация git и первый коммит (запускать из WSL в корне проекта):
#   bash scripts/git-init.sh

set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

if [[ -d .git ]]; then
  echo "Репозиторий уже инициализирован (.git существует)."
  git status -sb
  exit 0
fi

git init -b main

if ! git config user.email >/dev/null 2>&1; then
  echo "Задайте git user (один раз):"
  echo "  git config --global user.email 'you@example.com'"
  echo "  git config --global user.name 'Your Name'"
  exit 1
fi

git add -A
git status
if git diff --cached --quiet; then
  echo "Нечего коммитить."
  exit 1
fi

git commit -m "chore: initial commit (medical-analyzer)"

echo ""
echo "Подключение GitHub (создайте пустой репозиторий на github.com):"
echo "  git remote add origin git@github.com:USER/REPO.git"
echo "  git push -u origin main"
