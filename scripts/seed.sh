#!/bin/sh
set -eu
docker compose exec -T php php /workspace/scripts/seed.php
