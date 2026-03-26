#!/bin/bash
# Inject environment variables ke Apache envvars agar tersedia di PHP via web
printenv | grep -E '^(DB_|APP_|ONLYOFFICE_|MAX_FILE_SIZE)' | sed 's/^/export /' >> /etc/apache2/envvars
exec "$@"
