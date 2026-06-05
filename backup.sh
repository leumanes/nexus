#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
BACKUP_DIR="$SCRIPT_DIR/backups"
TIMESTAMP=$(date +%Y%m%d-%H%M%S)
KEEP_DAYS=7

mkdir -p "$BACKUP_DIR"
cd "$SCRIPT_DIR"

echo "[$(date)] Starting backup..."

# Database — consistent snapshot without table locks
TMP_SQL="$BACKUP_DIR/db-$TIMESTAMP.sql.gz.tmp"
trap 'rm -f "$TMP_SQL"' ERR
docker compose exec -T db sh -c \
  'MYSQL_PWD="$MYSQL_PASSWORD" mariadb-dump -u wordpress --single-transaction wordpress' \
  | gzip > "$TMP_SQL"
mv "$TMP_SQL" "$BACKUP_DIR/db-$TIMESTAMP.sql.gz"
echo "  db-$TIMESTAMP.sql.gz"

# wp-content (uploads, themes, plugins) via Caddy — alpine has tar
docker compose exec -T caddy \
  tar czf - -C /var/www/html wp-content \
  > "$BACKUP_DIR/wp-files-$TIMESTAMP.tar.gz"
echo "  wp-files-$TIMESTAMP.tar.gz"

# mu-plugins from host bind-mount (not visible to Caddy container)
tar czf "$BACKUP_DIR/mu-plugins-$TIMESTAMP.tar.gz" -C "$SCRIPT_DIR" mu-plugins
echo "  mu-plugins-$TIMESTAMP.tar.gz"

# Prune backups older than KEEP_DAYS
find "$BACKUP_DIR" -name "*.gz" -mtime +"$KEEP_DAYS" -delete

echo "[$(date)] Done. Backups in $BACKUP_DIR"
