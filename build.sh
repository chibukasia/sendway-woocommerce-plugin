#!/bin/sh
# Package the plugin for download.
#
# WordPress unzips into wp-content/plugins/, so the archive must contain a
# single top-level `sendway-woocommerce/` directory. Zipping the files bare
# scatters them into the plugins folder and the install looks corrupt.
#
#   ./build.sh            → dist/sendway-woocommerce.zip
#   ./build.sh ../sendway-web/public   → also copies it there for the site
set -e

SLUG="sendway-woocommerce"
ROOT="$(cd "$(dirname "$0")" && pwd)"
VERSION=$(grep -m1 "^ \* Version:" "$ROOT/sendway.php" | sed 's/.*Version: *//' | tr -d ' \r')
STAGE="$ROOT/.build/$SLUG"
OUT="$ROOT/dist/$SLUG.zip"

# Refuse to ship a plugin that will white-screen a merchant's site.
if command -v php >/dev/null 2>&1; then
  for f in "$ROOT"/sendway.php "$ROOT"/includes/*.php; do
    php -l "$f" >/dev/null || { echo "Syntax error in $f — not packaging."; exit 1; }
  done
  echo "Lint passed."
else
  echo "WARNING: php not found, packaging without a syntax check."
fi

rm -rf "$ROOT/.build" "$ROOT/dist"
mkdir -p "$STAGE/includes" "$ROOT/dist"

cp "$ROOT/sendway.php" "$ROOT/readme.txt" "$STAGE/"
cp "$ROOT"/includes/*.php "$STAGE/includes/"

( cd "$ROOT/.build" && zip -qr "$OUT" "$SLUG" -x '*.DS_Store' )
rm -rf "$ROOT/.build"

echo "Built $OUT (version $VERSION)"

# Publish a manifest alongside the zip so the plugin can check for updates
# later without us standing up an update server first.
if [ -n "$1" ]; then
  mkdir -p "$1"
  cp "$OUT" "$1/$SLUG.zip"
  cat > "$1/$SLUG.json" <<JSON
{
  "name": "SendWay for WooCommerce",
  "slug": "$SLUG",
  "version": "$VERSION",
  "requires": "6.0",
  "requires_php": "7.4",
  "tested": "6.7",
  "download_url": "https://sendway.co.ke/$SLUG.zip",
  "homepage": "https://sendway.co.ke/docs/woocommerce",
  "last_updated": "$(date -u +%Y-%m-%d)"
}
JSON
  echo "Copied to $1 with $SLUG.json"
fi
