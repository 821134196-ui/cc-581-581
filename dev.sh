#!/usr/bin/env bash
# =============================================================================
# 社区低温天气取暖补贴申领与核验系统 —— 一条命令启动
#
#   ./dev.sh           安装依赖 + 迁移种子 + 构建前端 + 启动服务（API/模拟短信/模拟支付/页面）
#   ./dev.sh dev       前后端开发模式（Vite 5173 热更新 + Laravel 8000）
#   ./dev.sh build     仅构建前端到 backend/public/spa
#   ./dev.sh migrate   重建数据库并写入演示数据
#   ./dev.sh test      运行后端场景测试
#   ./dev.sh reset     重新迁移 + 填充演示数据
#
# 启动后访问： http://127.0.0.1:8000/spa/
# =============================================================================
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKEND="$ROOT/backend"
FRONTEND="$ROOT/frontend"

# 优先使用项目内置静态 PHP（无 root 环境安装在 ~/.local）
export PATH="$HOME/.local/bin:$PATH"
cd "$BACKEND"

ensure_php() {
  if ! command -v php >/dev/null 2>&1; then
    echo "!! 未找到 php。请先安装 PHP 8.2+（需 pdo_sqlite/mbstring/curl/dom）。" >&2
    exit 1
  fi
}

install_deps() {
  echo "==> [1/4] 检查 PHP / Composer 依赖"
  if [ ! -d "$BACKEND/vendor" ]; then
    composer install --no-interaction --prefer-dist
  fi
  if [ ! -d "$FRONTEND/node_modules" ]; then
    echo "==> 安装前端依赖"
    (cd "$FRONTEND" && npm install --no-audit --no-fund)
  fi
}

migrate_seed() {
  echo "==> [2/4] 准备 SQLite 数据库与演示数据"
  touch "$BACKEND/database/database.sqlite"
  php artisan migrate --force
  # seeder 使用 updateOrCreate，重复执行幂等
  php artisan db:seed --force
}

build_frontend() {
  echo "==> [3/4] 构建 React 前端到 backend/public/spa"
  (cd "$FRONTEND" && npm run build)
}

serve_all() {
  ensure_php
  install_deps
  migrate_seed
  build_frontend
  echo "==> [4/4] 启动服务"
  echo "    页面:       http://127.0.0.1:8000/spa/"
  echo "    健康检查:   http://127.0.0.1:8000/up"
  echo "    演示账号:   居民 13800000001 / 社区 13900000001 / 街道 13700000001（密码 password）"
  echo "    短信/支付均为本地模拟（短信箱页面 + /api/bank/receipts 回调），无需外部服务。"
  exec php artisan serve --host=127.0.0.1 --port=8000
}

case "${1:-up}" in
  up|"")
    serve_all
    ;;
  dev)
    ensure_php
    install_deps
    migrate_seed
    touch "$BACKEND/database/database.sqlite"
    php artisan serve --host=127.0.0.1 --port=8000 &
    (cd "$FRONTEND" && npm run dev)
    ;;
  build)
    build_frontend
    ;;
  migrate|reset)
    ensure_php
    touch "$BACKEND/database/database.sqlite"
    php artisan migrate:fresh --seed --force
    ;;
  seed)
    php artisan db:seed --force
    ;;
  test)
    ensure_php
    php artisan test
    ;;
  sweep)
    # 手动触发一次材料过期扫描（生产由 schedule 每日 08:00 执行）
    php artisan subsidy:sweep-expired
    ;;
  *)
    echo "用法: $0 {up|dev|build|migrate|reset|seed|test|sweep}" >&2
    exit 1
    ;;
esac
