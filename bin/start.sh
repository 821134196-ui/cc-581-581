#!/usr/bin/env bash
# ============================================================================
# 暖冬补贴系统 · 一键启动脚本
#
# 做两件事：
#   1) php artisan serve        —— Laravel + React 页面（http://127.0.0.1:8000）
#   2) php artisan sim:worker   —— 本地模拟短信/支付通道（自动发送短信、自动回执）
#
# 首次运行会自动完成：环境检查、数据库迁移、演示数据填充、前端构建。
# 退出：Ctrl+C（会同时停掉两个进程）
# ============================================================================
set -euo pipefail
cd "$(dirname "$0")/.."

PORT="${PORT:-8000}"

# 本机无全局 php 时，使用用户目录下安装的静态/解压 PHP
if ! command -v php >/dev/null 2>&1 && [ -x "$HOME/.local/bin/php" ]; then
  export PATH="$HOME/.local/bin:$PATH"
fi

if ! command -v php >/dev/null 2>&1; then
  echo "✗ 未找到 php，请先安装 PHP 8.2+（需 pdo_sqlite/mbstring/dom/curl 扩展）"
  exit 1
fi

# 首次运行准备
[ -f .env ] || cp .env.example .env
grep -q '^APP_KEY=base64' .env || php artisan key:generate --ansi
touch database/database.sqlite
php artisan migrate --force --ansi
php artisan db:seed --class=DemoSeeder --force --ansi

if [ ! -f public/build/manifest.json ]; then
  if command -v npm >/dev/null 2>&1; then
    [ -d node_modules ] || npm install
    npm run build
  else
    echo "✗ 未找到 npm，无法构建前端资源"
    exit 1
  fi
fi

echo ""
echo "================================================"
echo "  暖冬补贴系统已启动："
echo "  页面地址     http://127.0.0.1:${PORT}"
echo "  演示账号密码均为 password："
echo "    居民       zhang@demo.local / zhang-spouse@demo.local"
echo "    社区初审   community@demo.local"
echo "    街道复核   street@demo.local / street2@demo.local"
echo "  模拟通道     sim:worker 运行中（短信自动发送、支付自动回执）"
echo "  重复回执测试 php artisan sim:receipt --receipt-no=同一编号"
echo "================================================"
echo ""

cleanup() {
  echo ""
  echo "正在停止服务…"
  kill "${SERVER_PID:-}" "${WORKER_PID:-}" 2>/dev/null || true
  wait 2>/dev/null || true
}
trap cleanup INT TERM EXIT

php artisan sim:worker &
WORKER_PID=$!

# 用 PHP 内置服务器（避免 artisan serve 二次派生进程时丢失本地 PHP 的库路径）
php -S 127.0.0.1:"${PORT}" server-local.php > storage/logs/server.log 2>&1 &
SERVER_PID=$!

wait -n "$SERVER_PID" "$WORKER_PID"
