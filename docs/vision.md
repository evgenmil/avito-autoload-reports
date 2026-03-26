# Vision: avito-autoload-reports

Этот документ фиксирует наше техническое видение проекта и служит отправной точкой для дальнейшей разработки.

## Технологии

- Язык: PHP `8.4` (единственный target-runtime в проекте).
- Управление зависимостями и автозагрузка: `Composer` (`psr-4`), команды `composer install` / `composer dump-autoload`.
- Работа с MySQL (обязательное требование): `doctrine/dbal` (без ORM, только прямой доступ через DBAL-соединение/запросы).
- Внешние HTTP-вызовы (OAuth и API): `guzzlehttp/guzzle`.
- OAuth 2.0: grant type `client_credentials`; `client_id` и `client_secret` хранятся в `avito_accounts`; `refresh_token` в схеме присутствует как nullable-поле, но в текущей реализации не используется.
- Docker (единственная среда запуска — и для разработки, и для продакшна): `docker compose` с сервисом `app` (PHP-cli). Образ для `app` собирается (билдится), исходники не монтируются в контейнер.
- Make (обязательное требование):
  - `make install` — установить PHP-зависимости (`composer install`).
  - `make build` — подготовить autoload (`composer dump-autoload`).
  - `make worker` — собрать Docker-образ и запустить воркер внутри контейнера (`docker compose build && docker compose run --rm app`).
  - MySQL считается уже доступной на хосте (схема создана заранее вручную); контейнер достучивается до неё через `host.docker.internal`.

## Принципы разработки

- KISS: минимально возможное решение, только необходимые абстракции и зависимости; никаких "на будущее".
- ООП: каждый класс делает одну понятную вещь; строгий принцип "1 класс = 1 файл".
- Без фреймворков: только plain PHP + библиотеки через Composer (никаких MVC/DI контейнеров/ORM-фреймворков).
- Явные зависимости: зависимости передаются через конструктор, без синглтонов и скрытого глобального состояния.
- Изоляция инфраструктуры: HTTP/OAuth и БД реализованы в отдельных классах/слоях, а доменная логика не зависит от конкретных библиотек.
- Идемпотентность воркера: повторный запуск должен быть безопасным и не создавать дубликаты (решаем это на уровне схемы/уникальных ключей и "upsert"-подхода в запросах).
- Деградация при ошибках: ошибка при обработке одной учетной записи/отчета не должна валить весь процесс; воркер продолжает остальные и завершает с ненулевым кодом только если были фатальные/массовые проблемы.
- Ясная граница ответственности: "получение данных" (HTTP), "преобразование" (парсинг/маппинг), "сохранение" (репозиторий/запросы), "оркестрация" (воркер) — разделены.

## Структура проекта

- Корень проекта:
  - `composer.json` — зависимости и autoload.
  - `Makefile` — команды `make install/build/worker/...`.
  - `.env*` — локальные настройки (секреты не коммитим).
- `bin/`:
  - `worker.php` — единственная точка входа CLI-воркера.
- `src/`:
  - `src/Model/` — простые DTO/модели (данные для передачи между слоями).
  - `src/Worker/` — оркестрация синхронизации (цикл: учетная запись -> отчет -> ошибочные объявления -> сохранение).
  - `src/Avito/` — интеграция с Avito: HTTP-клиент, OAuth-получение токена, парсинг ответов отчёта и ошибочных объявлений.
  - `src/Persistence/` — доступ к MySQL через `doctrine/dbal` (репозитории/запросы для сохранения и выборок).
- `docker/`:
  - `Dockerfile` — сборка образа `app` (PHP-cli).
  - `docker-compose.yml` — локальная инфраструктура (`app`).
- Принцип организации:
  - каждый класс живет в своем файле, имя файла = имя класса (например, `src/Worker/Worker.php` содержит класс `Worker`).

## Архитектура проекта

- Общая идея: один CLI-воркер выполняет синхронизацию; код разделен на слои `Worker` (оркестрация), `Avito` (интеграция) и `Persistence` (хранение), а `Model` описывает данные.

- Слой `Worker`:
  - `Worker` читает список учетных записей из `avito_accounts`, для каждой запускает последовательность обработки.
  - Оркестрация: получить токен → запросить отчёт → сравнить с БД → если новый: сохранить отчёт + запросить и сохранить ошибочные объявления.

- Слой `Avito`:
  - `AvitoApiClient` выполняет HTTP-запросы к Avito (авторизованные и не авторизованные).
  - `OAuthClient` получает новый `access_token` через `POST OAUTH_TOKEN_URL` с `grant_type=client_credentials`.
  - `ReportParser` маппит JSON-ответ `AVITO_LAST_REPORT_PATH` в DTO `Report`.
  - `ErrorAdsParser` маппит JSON-ответ `AVITO_ERROR_ADS_PATH` в список DTO `ErrorAd`, обходя все страницы пагинации.

- Слой `Persistence`:
  - `Db` (тонкий адаптер) создает соединение к MySQL.
  - Репозитории реализуют выборки и сохранение: `AvitoAccountsRepository`, `AvitoReportsRepository`, `AvitoErrorAdsRepository`.
  - Сохранение идемпотентно: уникальные ключи и upsert.

- Модели (`src/Model/`):
  - DTO/простые структуры данных без логики инфраструктуры.
  - Слои общаются через DTO: `Worker` обменивается данными с `Avito` и `Persistence` через `Model`.

## Модель данных

- Основные сущности:
- `avito_accounts` — личные кабинеты и OAuth-токены для них.
- `avito_reports` — полученный "последний отчет" (по одному ключу идет идемпотентно).
- `avito_error_ads` — ошибочные объявления, полученные из конкретного отчета.

#### `avito_accounts`
Поля (минимальный набор):
`id` (PK), `label` (nullable), `client_id`, `client_secret`, `refresh_token` (nullable), `access_token` (nullable), `token_expires_at` (nullable), `created_at`, `updated_at`.

#### `avito_reports`
Поля (минимальный набор):
`id` (PK), `account_id` (FK -> `avito_accounts.id`), `report_external_id`, `fetched_at`.

Ограничения:
`UNIQUE (account_id, report_external_id)` — идемпотентность повторного запуска для одного и того же отчета.

#### `avito_error_ads`
Поля (минимальный набор):
`id` (PK), `report_id` (FK -> `avito_reports.id`), `ad_external_id`, `error_type` (nullable), `fetched_at`.

Ограничения:
`UNIQUE (report_id, ad_external_id)` — идемпотентность повторной записи ошибок из одного отчета.

## Сценарии работы

- Основная сущность сценария: CLI-воркер `bin/worker.php`, выполняющий синхронизацию данных Avito -> MySQL.

### Запуск воркера
- Воркeр стартует.
- Поднимает соединение к MySQL (через `doctrine/dbal`).
- Загружает список аккаунтов из `avito_accounts`.
- Для каждого аккаунта выполняет последовательность из шагов ниже.

### Для одного аккаунта (KISS-поток)
1. **Подготовка OAuth-токена:**
   - Если `access_token` пустой или `token_expires_at` <= NOW() — запрашиваем новый токен через `POST OAUTH_TOKEN_URL` с `grant_type=client_credentials`, `client_id`, `client_secret`.
   - Ответ: `{ "access_token": "...", "expires_in": 86400, "token_type": "Bearer" }`.
   - Сохраняем `access_token` и `token_expires_at = NOW() + expires_in - 60s` в `avito_accounts`.
   - Если Avito API вернул 403 с телом `{"result": {"status": false, "message": "invalid access token"}}` — обновляем токен ещё раз и повторяем запрос однократно; повторный 403 считается ошибкой аккаунта.

2. **Получение последнего отчёта:**
   - `GET AVITO_BASE_URL + AVITO_LAST_REPORT_PATH` с заголовком `Authorization: Bearer <access_token>`.
   - Ответ содержит `report_id` (целое число).
   - `report_id` → `report_external_id` в нашей схеме.
   - Статус отчёта (`status`) игнорируем — берём отчёт независимо от него.

3. **Идемпотентность по `report_external_id`:**
   - Если `avito_reports` уже содержит запись с ключом `UNIQUE(account_id, report_external_id)` — делаем `skip` (не запрашиваем `error_ads`).

4. **Если отчет новый:**
   - Стартуем транзакцию.
   - Сохраняем запись в `avito_reports`.
   - Запрашиваем ошибочные объявления: `GET AVITO_BASE_URL + AVITO_ERROR_ADS_PATH` с `{report_id}` подставленным в путь.
     - Пример пути: `/autoload/v2/reports/{report_id}/items?sections=error,publish_with_problems`.
     - Обходим все страницы пагинации (пока `page < pages` по полю `meta`).
   - Из каждого элемента `items[]` берём:
     - `ad_id` → `ad_external_id`
     - первый `messages[].title` где `messages[].type == "error"` → `error_type` (nullable, если `error`-сообщений нет)
   - Сохраняем в `avito_error_ads` с upsert по `UNIQUE(report_id, ad_external_id)`.
   - Фиксируем транзакцию (`COMMIT`).
   - Если на шаге получения/парсинга `error_ads` или сохранения произошла ошибка — откатываем (`ROLLBACK`).
   - Пустой список `error_ads` допустим и не является ошибкой.

### Повторные запуски
- Для каждого аккаунта сначала сравнивается `report_external_id`.
- Если отчет тот же — `skip`, повторной загрузки `error_ads` не происходит.

### Обработка ошибок
- Ошибка одного аккаунта не прерывает обработку остальных.
- Воркeр завершает работу ненулевым кодом только при массовых/фатальных проблемах (например, недоступна БД); при частичных фейлах — завершается с ненулевым кодом и логирует итог.

## Подход к конфигурированию

- Конфигурация через переменные окружения (`.env` для локального запуска).
- Загрузка в момент старта воркера (`bin/worker.php`): `getenv()`/`$_ENV`.
- `fail-fast`: если отсутствуют обязательные переменные — воркер сразу завершает работу с понятной ошибкой.
- Обязательный набор env-переменных:
  - БД: `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD`
  - `LOG_LEVEL` (например `debug|info|warning|error`)
  - OAuth: `OAUTH_TOKEN_URL` (полный URL endpoint для получения токена)
  - Avito: `AVITO_BASE_URL`
  - Avito endpoints:
    - `AVITO_LAST_REPORT_PATH` (путь "последний отчет", например `/autoload/v2/reports/last_report`)
    - `AVITO_ERROR_ADS_PATH` (путь "ошибочные объявления" с `{report_id}`, например `/autoload/v2/reports/{report_id}/items?sections=error,publish_with_problems`)

## Подход к логгированию

- Логи пишем в файл (append), без сложных систем ротации (для KISS).
- Путь к файлу задается env-переменной `LOG_FILE`.
- По умолчанию, если `LOG_FILE` не задан: `var/log/worker.log` (относительно директории запуска приложения, где лежит `.env`).
- Формат строки: `timestamp level message` + контекст где уместно (например `account_id`, `report_external_id`).
- Уровень логов фильтруется по `LOG_LEVEL`.
- Запрещаем логировать секреты: `client_secret`, `refresh_token`, `access_token`.
- При старте воркера гарантируем существование директории под лог-файл (создаем, если ее нет).

## Подход к сборке и деплою

- База данных:
  - в проде и в локальной среде предполагается, что MySQL уже существует;
  - таблицы должны быть созданы заранее вручную (например, по `sql/*.sql`), после чего приложение их читает/пишет.

- Сборка и запуск (Docker — единственная среда):
  - `make install` (установить зависимости локально для IDE/инструментов: `composer install`).
  - `make build` (обновить autoload локально: `composer dump-autoload`).
  - `make worker` (собрать образ и запустить воркер: `docker compose build && docker compose run --rm app`; MySQL доступна с хоста через `host.docker.internal`).

- CI/CD (GitHub Actions):
  - Репозиторий публичный на GitHub.
  - При каждом коммите в `main` срабатывает GitHub Actions workflow (`.github/workflows/docker-publish.yml`).
  - Workflow собирает Docker-образ и публикует его в GitHub Container Registry (`ghcr.io`) под тегом `latest`.
  - Авторизация в GHCR — через встроенный `GITHUB_TOKEN` (дополнительные секреты не нужны).

- Прод-деплой (cron + bash-скрипт):
  - На проде лежит скрипт `deploy/run.sh`, запускаемый по крону.
  - Скрипт: `docker pull ghcr.io/<owner>/<repo>:latest`, затем `docker run --rm --env-file /path/to/.env -v /path/to/logs:/app/var/log`.
  - Контроль одного экземпляра: перед запуском проверяется, не выполняется ли уже экземпляр воркера; если да — пропускаем запуск.
  - Конфигурация на проде — файл `.env` на хосте, передаётся через `--env-file`.
  - Логи пишутся в файл на хосте через `-v /path/to/logs:/app/var/log` (как в локальном docker-compose).
  - Скрипт завершается ненулевым кодом при любой ошибке (`set -e`).
