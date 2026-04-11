#!/usr/bin/env bash
set -euo pipefail

BACKUP_DIR="/backups"
RETENTION_DAYS=7
TIMESTAMP=$(date +%Y-%m-%d)
BACKUP_FILE="${BACKUP_DIR}/programel_${TIMESTAMP}.sql.gz"

echo "[$(date -Iseconds)] Starting backup..."

docker compose -f /opt/programel/docker-compose.prod.yml exec -T postgres \
    pg_dump -U "${POSTGRES_USER:-programel}" "${POSTGRES_DB:-programel}" \
    | gzip > "${BACKUP_FILE}"

echo "[$(date -Iseconds)] Backup saved: ${BACKUP_FILE} ($(du -h "${BACKUP_FILE}" | cut -f1))"

# Remove backups older than retention period
find "${BACKUP_DIR}" -name "programel_*.sql.gz" -mtime +${RETENTION_DAYS} -delete

echo "[$(date -Iseconds)] Cleanup complete. Remaining backups:"
ls -lh "${BACKUP_DIR}"/programel_*.sql.gz 2>/dev/null || echo "  (none)"
