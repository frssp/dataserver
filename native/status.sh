#!/bin/bash
# Check status of all Zotero DataServer services
set -euo pipefail

echo "=== Zotero DataServer Service Status ==="
echo ""

for svc in mysql mysqld memcached redis-server redis php7.4-fpm php-fpm nginx; do
    if systemctl list-units --type=service --all 2>/dev/null | grep -q "$svc.service"; then
        status=$(systemctl is-active "$svc" 2>/dev/null || echo "inactive")
        printf "  %-16s : %s\n" "$svc" "$status"
    fi
done

echo ""

# Test MySQL
if command -v mysql &>/dev/null; then
    if mysql -u zotero -pzotero_app_pw -e "SELECT 1" &>/dev/null; then
        echo "MySQL connection   : OK"
        db_count=$(mysql -u zotero -pzotero_app_pw -N -e "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME LIKE 'zotero%';" 2>/dev/null)
        echo "Zotero databases   : $db_count"
    else
        echo "MySQL connection   : FAILED"
    fi
fi

# Test Memcached
if command -v nc &>/dev/null || command -v ncat &>/dev/null; then
    if echo "stats" | nc -w1 127.0.0.1 11211 &>/dev/null; then
        echo "Memcached          : OK (port 11211)"
    else
        echo "Memcached          : NOT responding"
    fi
fi

# Test Redis
if command -v redis-cli &>/dev/null; then
    if redis-cli ping &>/dev/null; then
        echo "Redis              : OK ($(redis-cli ping))"
    else
        echo "Redis              : NOT responding"
    fi
fi

# Test Nginx + PHP
SERVER_PORT="${SERVER_PORT:-8080}"
if command -v curl &>/dev/null; then
    http_code=$(curl -s -o /dev/null -w "%{http_code}" "http://localhost:$SERVER_PORT/" 2>/dev/null || echo "000")
    if [ "$http_code" != "000" ]; then
        echo "Nginx (port $SERVER_PORT) : HTTP $http_code"
    else
        echo "Nginx (port $SERVER_PORT) : NOT responding"
    fi

    # Test API with key
    api_code=$(curl -s -o /dev/null -w "%{http_code}" \
        -H "Zotero-API-Key: GmYMvkzxnJFeCKfDhBBD4ONv" \
        "http://localhost:$SERVER_PORT/keys/current" 2>/dev/null || echo "000")
    echo "API /keys/current  : HTTP $api_code"
fi

echo ""
