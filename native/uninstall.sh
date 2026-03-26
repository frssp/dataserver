#!/bin/bash
# Remove Zotero DataServer native setup (databases + nginx config)
# Does NOT remove system packages (mysql, php, etc.)
set -euo pipefail

echo "=== Zotero DataServer Cleanup ==="
echo "This will DROP all Zotero databases and remove config files."
read -p "Continue? [y/N] " confirm
if [ "$confirm" != "y" ] && [ "$confirm" != "Y" ]; then
    echo "Aborted."
    exit 0
fi

DATASERVER_DIR="$(cd "$(dirname "$0")/.." && pwd)"

# Drop databases
echo ">>> Dropping databases..."
mysql -u root -e "DROP DATABASE IF EXISTS zotero_master;"
mysql -u root -e "DROP DATABASE IF EXISTS zotero_shard1;"
mysql -u root -e "DROP DATABASE IF EXISTS zotero_ids;"
mysql -u root -e "DROP DATABASE IF EXISTS zotero_www_dev;"
mysql -u root -e "DROP USER IF EXISTS 'zotero'@'localhost';"

# Remove nginx config
echo ">>> Removing nginx config..."
rm -f /etc/nginx/sites-enabled/zotero
rm -f /etc/nginx/sites-available/zotero
rm -f /etc/nginx/conf.d/zotero.conf
systemctl reload nginx 2>/dev/null || true

# Remove generated config files
echo ">>> Removing generated config..."
rm -f "$DATASERVER_DIR/include/config/config.inc.php"
rm -f "$DATASERVER_DIR/include/config/dbconnect.inc.php"

echo ""
echo "Done. System packages (mysql, php, nginx, etc.) were NOT removed."
echo "Remove them manually if needed."
