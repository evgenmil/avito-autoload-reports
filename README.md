# avito-autoload-reports
## Зачем это нужно
**Периодическое подтягивание для каждого кабинета Авито последний завершённый отчёт и связанные с ним ошибочные объявления (по заданным кодам ошибок) и складывать снимок в MySQL**. Дальше этот снимок можно использовать в отчётах, алертах или своей бизнес-логике без ручного захода в кабинет.
Технически это **один CLI-воркер** (PHP 8.4): OAuth `client_credentials`, HTTP к Avito Autoload API, запись в БД с идемпотентностью и изоляцией сбоев по аккаунтам. Подробности сценариев и слоёв — в [`docs/vision.md`](docs/vision.md).
## Быстрый старт (локально)
**Требования:** Docker с Compose, Make, локальный MySQL с созданной схемой.
1. **Схема БД** — выполните SQL из [`sql/01_create_tables.sql`](sql/01_create_tables.sql) на своей базе.
2. **Учётные записи Avito** — заполните таблицу `avito_accounts` (`client_id`, `client_secret` и при необходимости `label`).
3. **Конфигурация** — скопируйте пример и поправьте значения:
   ```bash
   cp .env.example .env
   ```
   Для запуска воркера внутри Docker к MySQL на хосте обычно указывают `DB_HOST=host.docker.internal` (см. комментарии в `.env.example`).
4. **Зависимости для IDE/локальных инструментов** (опционально):
   ```bash
   make install
   make build
   ```
5. **Запуск воркера** — сборка образа и один прогон:
   ```bash
   make worker
   ```
   Логи по умолчанию: `var/log/worker.log` (каталог монтируется из хоста в `docker-compose.yml`).
Обязательные переменные окружения перечислены в [`.env.example`](.env.example): БД, уровень логов, URL токена, базовый URL Avito, пути к эндпоинтам отчёта и объявлений, фильтр `AVITO_ERROR_CODES`.
## Продакшен (кратко)
Образ публикуется в GHCR при пуше в `main` (см. [`.github/workflows/docker-publish.yml`](.github/workflows/docker-publish.yml)). Пример сценария: `docker pull` + один экземпляр воркера по cron — в [`deploy/run.sh`](deploy/run.sh) (там же подсказка по cron и `--env-file`).
