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
docker-compose up -d
docker-compose exec app composer install --no-interaction --prefer-dist --optimize-autoloader
docker-compose exec app php bin/console doctrine:migrations:migrate --no-interaction
```

Приложение: **http://localhost:8090**

Первая регистрация: `/auth/register` — QR для TOTP, затем вход. Эмитент в приложении-аутентификаторе: `TOTP_ISSUER` (по умолчанию **myLab**).

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
├── docker/
├── backend/
│   ├── src/
│   ├── templates/
│   └── migrations/
└── uploads/
```

## Сервисы (Docker)

| Сервис        | Контейнер        | URL / порт с хоста   |
|---------------|------------------|----------------------|
| Приложение    | `mylab-nginx`    | http://localhost:8090 |
| PHP-FPM       | `mylab-app`      | —                    |
| PostgreSQL    | `mylab-postgres` | localhost:5432       |
| Redis         | `mylab-redis`    | localhost:6380 → 6379 в контейнере |
| RabbitMQ UI   | `mylab-rabbitmq` | http://localhost:15673 (guest/guest) |
| OCR worker    | `mylab-ocr-worker` | —                  |

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

## Лицензия

MIT
