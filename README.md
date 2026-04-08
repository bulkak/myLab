# myLab

Веб-приложение для хранения истории медицинских анализов: загрузка PDF и изображений, распознавание показателей через облачные модели, графики динамики и объединение названий показателей из разных лабораторий.

## Возможности

- **TOTP** — вход без пароля (логин + код из приложения-аутентификатора)
- **Загрузка** — PDF, JPEG, PNG и др.
- **OCR / извлечение показателей** — несколько движков (см. ниже), очередь через RabbitMQ
- **Редактирование названий** — до и после сохранения анализа, подсказки из уже сохранённых показателей
- **История и поиск** — по показателям, графики динамики (Chart.js)
- **Логи API** — запросы к внешним провайдерам для отладки

## Стек

- **Backend:** PHP 8.3, Symfony 7.x  
- **БД:** PostgreSQL 16 (имя БД по умолчанию: `mylab`)  
- **Кэш / сессии:** Redis 7  
- **Очередь:** RabbitMQ 3.13 (контейнер `mylab-ocr-worker`)  
- **PDF → изображения:** Poppler (`pdftoppm`), по умолчанию PNG, 250 DPI, `-cropbox`  
- **Frontend:** Twig, HTMX, Chart.js  

## Ссылки на показатели в URL

Имена показателей могут содержать `/`, `%` и другие символы. В пути используется **токен** (base64url от UTF-8 строки), а не сырое название:

- динамика: `/metric/dynamics/{token}`
- история показателя: `/history/metric/{token}`

В шаблонах: фильтры-функции Twig `metric_dynamics_path(имя)` и `metric_history_path(имя)`.

## Распознавание (OCR)

Центральная точка — `OcrManager`. Доступные модели задаются в UI и в `AnalysisController`; движок подбирается по модели.

| Провайдер        | Класс                 | Примечание |
|-----------------|------------------------|------------|
| Yandex Cloud    | `YandexCloudOcrEngine` | Мультимодальная модель (например `qwen3.5-35b-a3b-fp8/latest`), два шага: CSV + дата |
| GigaChat        | `GigaChatOcrEngine`    | По страницам PDF: CSV + дата |
| OpenAI-совместимый | `OpenAiOcrEngine`   | Через шлюз (например APIYI), `gpt-4o`, та же схема CSV + дата |

Модель по умолчанию для новых задач задаётся в `config/services.yaml` → аргумент `$defaultModel` у `App\Service\OcrManager`.

Переменные окружения (файл `backend/.env`, для секретов лучше `backend/.env.local`):

- **GigaChat:** `GIGACHAT_AUTH_KEY`, `GIGACHAT_MODEL`
- **OpenAI-совместимый API:** `OPENAI_API_KEY`, `OPENAI_API_URL`
- **Yandex Cloud:** `YANDEX_CLOUD_API_KEY`, `YANDEX_CLOUD_FOLDER`

## Быстрый старт

Требования: Docker (WSL2 на Windows или Linux), Git.

```bash
cd mylab   # или имя вашей папки клона
docker compose up -d
docker compose exec app composer install --no-interaction --prefer-dist --optimize-autoloader
docker compose exec app php bin/console doctrine:migrations:migrate --no-interaction
```

Приложение: **http://localhost:8090**

Порты Postgres / Redis / RabbitMQ **не** пробрасываются на хост по умолчанию (безопаснее). Подключаться к БД: `docker compose exec postgres psql -U postgres -d mylab`. Если нужны клиенты с хоста (DBeaver и т.д.), поднимите с дополнительным файлом:  
`docker compose -f docker-compose.yml -f docker-compose.dev-ports.yml up -d`

Первая регистрация: `/auth/register` — QR для TOTP, затем вход. Эмитент в приложении-аутентификаторе: `TOTP_ISSUER` (по умолчанию **myLab**).

## Прод-деплой на VPS (docker compose, без потери данных)

Ниже сценарий для VPS, где уже запущен другой сервис (например, AmneziaVPN): наружу публикуем только приложение, а БД/кэш/очередь оставляем внутри docker-сети.

### 1) Подготовка сервера

- Установите проект в постоянный путь, например `/opt/mylab/myLab`.
- Проверьте, что внешний порт приложения не конфликтует с уже запущенными сервисами.
- Создайте DNS `A`-запись домена на IP VPS.

### 2) Прод-переменные окружения

```bash
cd /opt/mylab/myLab
cp deploy/.env.vps.example .env.vps
```

Заполните `.env.vps` реальными значениями (`APP_SECRET`, OCR-ключи, домен).  
`APP_HTTP_PORT` — порт на хосте, на который смотрит nginx (по умолчанию в прод-override **80**, сайт открывается как `http://домен` без `:порта`). Для нестандартного порта укажите, например, `8090`.  
`DEFAULT_URI` задайте без порта, если используете 80 (например `http://med.example.ru`).  
`docker-compose.prod.yml` переводит приложение в `APP_ENV=prod` и задаёт `restart` для сервисов. Порты БД/кэша/очереди **не публикуются** в базовом `docker-compose.yml` (на VPS не должно быть `0.0.0.0:5432` после `ss -tlnp`). Для локального доступа к портам используйте `docker-compose.dev-ports.yml`.

### 3) Первый запуск

После `git clone` файла `backend/.env` нет (он в `.gitignore`). Один раз создайте его:

```bash
cd /opt/mylab/myLab
cp backend/.env.example backend/.env
```

Секреты провайдеров OCR задайте в `backend/.env.local` или через переменные в `.env.vps` / compose.

```bash
./deploy/first-deploy.sh ./.env.vps
```

Скрипт поднимет стек, дождется PostgreSQL, выполнит `composer install` и миграции.

### 4) Обновление без потери данных

Короткий вариант (бэкап → pull → образа → миграции):

```bash
cd /opt/mylab/myLab   # ваш каталог с репозиторием
./deploy/backup.sh ./.env.vps ./backups
git pull
docker compose --env-file .env.vps -f docker-compose.yml -f docker-compose.prod.yml up -d --build
docker compose --env-file .env.vps -f docker-compose.yml -f docker-compose.prod.yml exec -T app php bin/console doctrine:migrations:migrate --no-interaction
```

#### Полное обновление на VPS после `git pull`

Чтобы подтянуть и код, и зависимости PHP, и схему БД, и кэш Symfony (удобно после крупных обновлений):

```bash
cd /opt/mylab/myLab

# по желанию — снимок перед обновлением
./deploy/backup.sh ./.env.vps ./backups

git pull

# Symfony ожидает backend/.env (не в git); если файла нет — один раз:
test -f backend/.env || cp backend/.env.example backend/.env

# пересобрать образы и поднять контейнеры
docker compose --env-file .env.vps -f docker-compose.yml -f docker-compose.prod.yml up -d --build

# зависимости и автоскрипты Composer
docker compose --env-file .env.vps -f docker-compose.yml -f docker-compose.prod.yml exec -T app \
  composer install --no-interaction --prefer-dist --optimize-autoloader

# миграции БД
docker compose --env-file .env.vps -f docker-compose.yml -f docker-compose.prod.yml exec -T app \
  php bin/console doctrine:migrations:migrate --no-interaction

# сброс prod-кэша Symfony
docker compose --env-file .env.vps -f docker-compose.yml -f docker-compose.prod.yml exec -T app \
  php bin/console cache:clear --env=prod --no-warmup

docker compose --env-file .env.vps -f docker-compose.yml -f docker-compose.prod.yml exec -T app \
  php bin/console cache:warmup --env=prod

# воркер OCR перезапустить (подхватит код и env)
docker compose --env-file .env.vps -f docker-compose.yml -f docker-compose.prod.yml restart ocr-worker
```

Если используете только **`docker-compose`** (v1), замените везде `docker compose` на `docker-compose` с тем же **`--env-file .env.vps`**.

Подстановка `${APP_SECRET:?…}` в `docker-compose.prod.yml` берётся из **`.env.vps`**: используйте **`--env-file .env.vps`** (как выше) или заранее `set -a && source .env.vps && set +a`. По умолчанию Compose подставляет переменные только из файла **`.env`** в корне проекта, а не из `.env.vps`.

Скрипты в `deploy/` передают `--env-file` автоматически.

Критично: **не выполняйте** `docker compose down -v` в проде — флаг `-v` удалит named volumes с БД/очередями.

### 5) Перезапуск VPS и автоподъем контейнеров

```bash
sudo systemctl enable docker
sudo systemctl restart docker
```

Все сервисы имеют `restart: unless-stopped`, поэтому после reboot должны подняться автоматически.

### 6) Бэкапы и восстановление

- Ежедневный бэкап: `./deploy/backup.sh ./.env.vps ./backups`
- Инструкция восстановления: `deploy/restore.md`
- Пример cron:

```cron
0 3 * * * cd /opt/mylab/myLab && /opt/mylab/myLab/deploy/backup.sh /opt/mylab/myLab/.env.vps /opt/mylab/myLab/backups >> /var/log/mylab-backup.log 2>&1
```

Путь `cd` и к скрипту замените на свой, если клон лежит не в `/opt/mylab/myLab`.

### Чистая установка БД с нуля

Удаляются **тома** Docker (`postgres_data`, `redis_data`, `rabbitmq_data`) — все пользователи, анализы и очереди. Затем контейнеры поднимаются заново и выполняются миграции.

Из корня репозитория (Linux / WSL / macOS):

```bash
chmod +x scripts/fresh-db.sh
./scripts/fresh-db.sh
```

С дополнительной очисткой каталога загрузок `./uploads` (кроме `.gitkeep`):

```bash
./scripts/fresh-db.sh --with-uploads
```

Вручную то же самое:

```bash
docker-compose down -v
docker-compose up -d
# дождаться healthcheck PostgreSQL
docker-compose exec app composer install --no-interaction --prefer-dist --optimize-autoloader
docker-compose exec app php bin/console doctrine:migrations:migrate --no-interaction
```

**Важно:** без флага `--with-uploads` файлы на диске в `./uploads` остаются; при необходимости удалите их сами или используйте скрипт с `--with-uploads`.

## Структура репозитория

```
mylab/   # корень проекта (раньше мог называться medical-analyzer)
├── docker-compose.yml
├── docker-compose.prod.yml
├── docker/
├── backend/
│   ├── src/
│   ├── templates/
│   └── migrations/
├── uploads/
└── deploy/
    ├── lib-compose.sh
    ├── first-deploy.sh
    ├── backup.sh
    └── restore.md
```

## Сервисы (Docker)

| Сервис        | Контейнер        | URL / порт с хоста   |
|---------------|------------------|----------------------|
| Приложение    | `mylab-nginx`    | локально: http://localhost:8090; VPS + `docker-compose.prod.yml`: порт из `APP_HTTP_PORT` (по умолчанию **80**) |
| PHP-FPM       | `mylab-app`      | —                    |
| PostgreSQL    | `mylab-postgres` | с хоста **без** `docker-compose.dev-ports.yml` — только `docker compose exec postgres …`; с dev-файлом: localhost:**5432** |
| Redis         | `mylab-redis`    | без dev-файла — нет порта; с `dev-ports`: localhost:**6380** → 6379 в контейнере |
| RabbitMQ UI   | `mylab-rabbitmq` | без dev-файла — нет; с `dev-ports`: http://localhost:15673 |
| OCR worker    | `mylab-ocr-worker` | —                  |

В production при запуске с `docker-compose.prod.yml` наружу публикуется только **nginx** (БД/Redis/RabbitMQ остаются во внутренней сети Docker).

## Полезные команды

```bash
docker-compose logs -f ocr-worker
docker-compose exec app php bin/console cache:clear
docker-compose restart ocr-worker
```

Пример SQL (БД `mylab`):

```bash
docker-compose exec postgres psql -U postgres mylab -c "SELECT id, status FROM analyses LIMIT 5;"
```

## Тесты

```bash
docker-compose exec app php vendor/bin/phpunit tests/Unit/
```

Тестовое окружение: `backend/.env.test`, БД `mylab_test` (настройте отдельно в CI при необходимости).

Дополнительно: [backend/TESTING_REPORT.md](backend/TESTING_REPORT.md).

## Безопасность

- Секреты не коммитить: используйте `backend/.env.local` или переменные окружения в проде.
- Смените `APP_SECRET` для production.
- Для VPS используйте отдельный `.env.vps` (см. `deploy/.env.vps.example`) и не храните его в git.
- Если секреты ранее попадали в репозиторий, перевыпустите API-ключи у провайдеров.

## Лицензия

MIT
