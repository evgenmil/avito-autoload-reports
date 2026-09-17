#!/usr/bin/env bash
# Запуск воркера на проде: pull свежего образа + контроль одного экземпляра.
#
# Cron (каждый час):
# 0 * * * * /opt/avito-autoload-reports/deploy/run.sh >> /var/log/avito-cron.log 2>&1
#
# Перед первым запуском:
#   1. Скопируйте .env.example в /opt/avito-autoload-reports/.env и заполните значения.
#   2. Убедитесь что docker доступен пользователю, от которого запускается cron.

set -euo pipefail

IMAGE="ghcr.io/evgenmil/avito-autoload-reports:latest"
CONTAINER_NAME="avito-autoload-reports-worker"
ENV_FILE="/opt/avito-autoload-reports/.env"
LOG_DIR="/opt/avito-autoload-reports/logs"

# Контроль одного экземпляра: живой прогон пропускаем,
# зависший (>50 мин при часовом cron) снимаем и запускаем заново.
MAX_RUNTIME_SEC=$((50 * 60))

if docker ps \
     --filter "name=${CONTAINER_NAME}" \
     --filter "status=running" \
     --format '{{.Names}}' | grep -q "${CONTAINER_NAME}"; then
  started_at=$(docker inspect -f '{{.State.StartedAt}}' "${CONTAINER_NAME}")
  started_epoch=$(date -d "${started_at}" +%s)
  age_sec=$(( $(date +%s) - started_epoch ))
  if [ "${age_sec}" -lt "${MAX_RUNTIME_SEC}" ]; then
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] Worker is already running (${age_sec}s), skipping."
    exit 0
  fi
  echo "[$(date '+%Y-%m-%d %H:%M:%S')] Worker stuck for ${age_sec}s, removing stale container."
  docker rm -f "${CONTAINER_NAME}"
fi

echo "[$(date '+%Y-%m-%d %H:%M:%S')] Pulling ${IMAGE}..."
docker pull "${IMAGE}"

mkdir -p "${LOG_DIR}"

echo "[$(date '+%Y-%m-%d %H:%M:%S')] Starting worker..."
docker run --rm \
  --name "${CONTAINER_NAME}" \
  --env-file "${ENV_FILE}" \
  -v "${LOG_DIR}:/app/var/log" \
  "${IMAGE}" \
  php bin/worker.php

echo "[$(date '+%Y-%m-%d %H:%M:%S')] Worker finished."
