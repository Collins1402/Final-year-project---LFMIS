#!/usr/bin/env bash
export LFMIS_DB_HOST=127.0.0.1
export LFMIS_DB_PORT=4321
export LFMIS_DB_NAME=lfmis
export LFMIS_DB_USER=flouri_admin
export LFMIS_DB_PASSWORD=Flouri_Farm_2026
php -S localhost:8000 -t "$(dirname "$0")/api"
