#!/bin/bash
# Build the Slackware .txz package for the Top Processes plugin.
# Run on the Unraid box (has makepkg) or any Linux/CI host.
set -e

NAME=topprocesses
VER="${1:-$(date +%Y.%m.%d)}"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SRC="$ROOT/source/$NAME"
OUT="$ROOT/archive"

[ -d "$SRC" ] || { echo "missing staging tree: $SRC" >&2; exit 1; }

# Normalise line endings (authored on Windows) and permissions.
find "$SRC" -type f \( -name '*.php'  -o -name '*.page' -o -name '*.js' \
                    -o -name '*.css'  -o -name '*.cfg'  -o -name '*.md' \) \
  -exec sed -i 's/\r$//' {} +
find "$SRC" -type d -exec chmod 755 {} +
find "$SRC" -type f -exec chmod 644 {} +

mkdir -p "$OUT"
PKG="$OUT/${NAME}-${VER}-x86_64-1.txz"

if command -v makepkg >/dev/null 2>&1; then
  ( cd "$SRC" && makepkg -l y -c n "$PKG" )
else
  echo "makepkg not found — falling back to tar (fine for testing, not ideal for release)."
  ( cd "$SRC" && tar -cJf "$PKG" . )
fi

echo
echo "Built: $PKG"
echo -n "MD5    "; md5sum    "$PKG"
echo -n "SHA256 "; sha256sum "$PKG"
echo
echo "Put the MD5 into topprocesses.plg (<!ENTITY md5 ...>) before releasing."
