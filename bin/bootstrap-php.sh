#!/usr/bin/env bash
# ============================================================================
# 无 root 环境引导 PHP 8.2 + Composer（Debian/Ubuntu）。
# 已安装 php 时直接跳过。需要网络（apt 下载 .deb 到用户目录解压，无需 sudo）。
# ============================================================================
set -euo pipefail

if command -v php >/dev/null 2>&1 && php -m | grep -q pdo_sqlite; then
  echo "✓ PHP 已可用：$(php -v | head -1)"
  exit 0
fi

if [ -x "$HOME/.local/bin/php" ]; then
  echo "✓ 已存在本地 PHP：$HOME/.local/bin/php"
  exit 0
fi

echo "→ 在用户目录引导 PHP（无需 root）…"
APT="$HOME/.local/apt"
mkdir -p "$APT/lists/partial" "$APT/cache/archives/partial"
TMP="$(mktemp -d)"; trap 'rm -rf "$TMP"' EXIT
touch "$TMP/dpkg-status"

apt-get \
  -o Dir::State::Lists="$APT/lists" \
  -o Dir::Cache="$APT/cache" \
  -o Dir::State::status="$TMP/dpkg-status" update -qq

cd "$TMP"
apt-get \
  -o Dir::State::Lists="$APT/lists" \
  -o Dir::Cache="$APT/cache" \
  -o Dir::State::status="$TMP/dpkg-status" \
  download \
  php8.2-cli php8.2-common php8.2-sqlite3 php8.2-opcache \
  php8.2-mbstring php8.2-xml php8.2-curl libonig5 libargon2-1 libsodium23

PREFIX="$HOME/.local/phpdeb"
mkdir -p "$PREFIX"
for f in *.deb; do dpkg-deb -x "$f" "$PREFIX"; done

EXT="$PREFIX/usr/lib/php/20220829"
mkdir -p "$HOME/.config/php"
cat > "$HOME/.config/php/php.ini" <<INI
extension_dir=$EXT
extension=pdo.so
extension=pdo_sqlite.so
extension=sqlite3.so
extension=iconv.so
extension=phar.so
extension=mbstring.so
extension=dom.so
extension=xml.so
extension=xmlwriter.so
extension=tokenizer.so
extension=ctype.so
extension=fileinfo.so
extension=curl.so
zend_extension=opcache.so
INI

mkdir -p "$HOME/.local/bin"
cat > "$HOME/.local/bin/php" <<EOF
#!/bin/bash
export LD_LIBRARY_PATH=$PREFIX/usr/lib/x86_64-linux-gnu:\$LD_LIBRARY_PATH
export PHPRC=$HOME/.config/php
exec $PREFIX/usr/bin/php8.2 -c $HOME/.config/php/php.ini "\$@"
EOF
chmod +x "$HOME/.local/bin/php"

if ! command -v composer >/dev/null 2>&1 && [ ! -x "$HOME/.local/bin/composer" ]; then
  echo "→ 安装 Composer…"
  curl -sS https://getcomposer.org/installer -o "$TMP/composer-setup.php"
  "$HOME/.local/bin/php" "$TMP/composer-setup.php" --install-dir="$HOME/.local/bin" --filename=composer --quiet
fi

echo "✓ PHP 引导完成：$HOME/.local/bin/php"
