#!/usr/bin/env bash
set -euo pipefail

THRESHOLD=85
WEBHOOK_URL="${DISK_ALERT_WEBHOOK_URL:-}"

USAGE=$(df / | tail -1 | awk '{print $5}' | sed 's/%//')

if [ "${USAGE}" -ge "${THRESHOLD}" ]; then
    MSG="⚠️ Disk usage on $(hostname) is at ${USAGE}% (threshold: ${THRESHOLD}%)"
    echo "[$(date -Iseconds)] WARNING: ${MSG}"

    if [ -n "${WEBHOOK_URL}" ]; then
        curl -s -X POST "${WEBHOOK_URL}" \
            -H "Content-Type: application/json" \
            -d "{\"text\": \"${MSG}\"}" || true
    fi
else
    echo "[$(date -Iseconds)] Disk usage: ${USAGE}% (OK)"
fi
