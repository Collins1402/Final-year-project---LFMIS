#!/usr/bin/env bash
export LFMIS_DB_HOST=127.0.0.1
export LFMIS_DB_PORT=4321
export LFMIS_DB_NAME=lfmis
export LFMIS_DB_USER=flouri_admin
export LFMIS_DB_PASSWORD=Flouri_Farm_2026
php "$(dirname "$0")/database/migrate.php"
if [[ "${LFMIS_MIGRATE_ONLY:-0}" == "1" ]]; then
	exit 0
fi
api_port="${LFMIS_API_PORT:-8001}"
php -S "127.0.0.1:${api_port}" "$(dirname "$0")/api/index.php"
