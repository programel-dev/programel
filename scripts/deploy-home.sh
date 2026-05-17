#!/bin/bash
set -euo pipefail

cd "$(dirname "$0")/.."

git fetch --quiet origin main

if [ "$(git rev-parse HEAD)" = "$(git rev-parse origin/main)" ]; then
    logger -t programel-deploy "no changes, skipping"
    exit 0
fi

logger -t programel-deploy "pulling $(git rev-parse origin/main)"
git pull --ff-only origin main

docker compose -f docker-compose.home.yml pull
docker compose -f docker-compose.home.yml up -d
logger -t programel-deploy "deployed $(git rev-parse HEAD)"
