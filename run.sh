#!/usr/bin/env bash
set -e

cd "$(dirname "$0")"

PHP_BIN="../tools/php/php.exe"
if [ ! -x "$PHP_BIN" ]; then
    PHP_BIN="php"
fi

"$PHP_BIN" -S 127.0.0.1:18103 -t integrations/logistics-gateway integrations/logistics-gateway/router.php >/tmp/wenchuan-logistics.log 2>&1 &
GATEWAY_PID=$!

"$PHP_BIN" -S 127.0.0.1:18102 -t public >/tmp/wenchuan-mall.log 2>&1 &
APP_PID=$!

echo "闻川商城管理系统：http://127.0.0.1:18102"
echo "物流跟踪网关：http://127.0.0.1:18103/tracking.txt"
echo "默认账号：admin / Wenchuan@2026"
echo "默认账号：ops / Mall@2026"
echo "按 Ctrl+C 停止服务。"

trap 'kill "$GATEWAY_PID" "$APP_PID" 2>/dev/null || true' EXIT INT TERM
wait
