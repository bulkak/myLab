# Восстановление из бэкапа

Работайте из **корня репозитория** (где лежат `docker-compose.yml` и `.env.vps`).

Перед командами подгрузите переменные (так же, как делают `deploy/first-deploy.sh` и `deploy/backup.sh` — без флага `--env-file`, совместимо со старым Docker):

```bash
cd /opt/mylab/myLab   # ваш путь к проекту
set -a && source .env.vps && set +a
```

Если команда `docker compose` недоступна, замените её на `docker-compose` во всех примерах ниже.

Ниже пример для восстановления из каталога `backups/YYYYMMDD_HHMMSS`.

## 1. Остановить приложение

```bash
docker compose -f docker-compose.yml -f docker-compose.prod.yml down
```

Не используйте `-v`, если не хотите удалять named volumes.

## 2. Поднять только PostgreSQL

```bash
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d postgres
docker compose -f docker-compose.yml -f docker-compose.prod.yml exec -T postgres pg_isready -U postgres
```

## 3. Восстановить БД

```bash
gunzip -c backups/YYYYMMDD_HHMMSS/mylab.sql.gz | docker compose -f docker-compose.yml -f docker-compose.prod.yml exec -T postgres psql -U postgres -d mylab
```

## 4. Восстановить загруженные файлы

```bash
tar -xzf backups/YYYYMMDD_HHMMSS/uploads.tar.gz -C .
```

## 5. Поднять весь стек

```bash
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d
```

## 6. Проверки после восстановления

- `docker compose -f docker-compose.yml -f docker-compose.prod.yml ps`
- открыть приложение в браузере
- проверить загрузку/просмотр анализов

## Пример cron для ежедневного бэкапа

```cron
0 3 * * * cd /opt/mylab && /opt/mylab/deploy/backup.sh /opt/mylab/.env.vps /opt/mylab/backups >> /var/log/mylab-backup.log 2>&1
```
