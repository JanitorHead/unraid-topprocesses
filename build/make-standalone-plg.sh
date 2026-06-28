#!/bin/bash
# Assemble a self-contained INLINE .plg from the source tree.
# Every web file is embedded as an INLINE CDATA <FILE> that writes straight into
# /usr/local/emhttp/plugins/topprocesses/ (RAM, recreated on every boot, so the
# plugin survives reboots without any hosted .txz). No GitHub/.txz needed.
set -e

NAME=topprocesses
VER="${1:-2026.06.28}"
AUTHOR=rafablues94
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
BASE="$ROOT/source/$NAME"
EMHTTP="usr/local/emhttp/plugins/$NAME"
OUT="$ROOT/${NAME}-standalone.plg"

[ -d "$BASE/$EMHTTP" ] || { echo "missing staging tree: $BASE/$EMHTTP" >&2; exit 1; }

emit_file() {
  # $1 = path on disk, $2 = install path
  local disk="$1" dest="$2"
  printf '  <FILE Name="%s">\n' "$dest"
  printf '    <INLINE><![CDATA[\n'
  sed 's/\r$//' "$disk"
  printf ']]></INLINE>\n'
  printf '  </FILE>\n\n'
}

{
  cat <<XML
<?xml version='1.0' standalone='yes'?>
<!DOCTYPE PLUGIN [
  <!ENTITY name    "$NAME">
  <!ENTITY author  "$AUTHOR">
  <!ENTITY version "$VER">
  <!ENTITY plugin  "/boot/config/plugins/&name;">
  <!ENTITY emhttp  "/usr/local/emhttp/plugins/&name;">
]>

<PLUGIN name="&name;"
        author="&author;"
        version="&version;"
        launch="Settings/TopProcessesSettings"
        icon="tasks"
        min="6.12.0">

  <CHANGES>
###$VER
- Standalone build: Top Processes dashboard widget embedded inline (no hosting required).
  </CHANGES>

  <FILE Run="/usr/bin/php">
    <INLINE><![CDATA[<?php
      \$v = parse_ini_file('/etc/unraid-version')['version'] ?? '0';
      if (version_compare(\$v, '6.12.0', '<')) {
        echo "\n*** Top Processes requires Unraid 6.12.0 or newer (found \$v). ***\n";
        exit(1);
      }
    ?>]]></INLINE>
  </FILE>

XML

  # Embed every staged web file, sorted for stable output.
  while IFS= read -r disk; do
    rel="${disk#$BASE/}"            # usr/local/emhttp/plugins/topprocesses/...
    emit_file "$disk" "/$rel"
  done < <(find "$BASE/$EMHTTP" -type f | LC_ALL=C sort)

  cat <<'XML'
  <!-- Seed default config on first install only -->
  <FILE Run="/bin/bash">
    <INLINE>
      mkdir -p &plugin;
      [ -f &plugin;/&name;.cfg ] || cp &emhttp;/default.cfg &plugin;/&name;.cfg
      echo ""
      echo "Top Processes installed. Open the Dashboard to see the tile."
    </INLINE>
  </FILE>

  <!-- Uninstall -->
  <FILE Run="/bin/bash" Method="remove">
    <INLINE>
      rm -rf &emhttp; &plugin;
    </INLINE>
  </FILE>

</PLUGIN>
XML
} > "$OUT"

echo "Wrote $OUT"
grep -c '<FILE ' "$OUT" | sed 's/^/FILE blocks: /'
