#!/bin/bash
# Assemble a self-contained INLINE .plg from the source tree.
# Every web file is embedded as an INLINE CDATA <FILE> that writes straight into
# /usr/local/emhttp/plugins/topprocesses/ (RAM, recreated on every boot, so the
# plugin survives reboots without any hosted .txz). No GitHub/.txz needed.
set -e

NAME=topprocesses
VER="${1:-2026.06.28}"
AUTHOR=JanitorHead
GITHUB=JanitorHead/unraid-topprocesses
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
BASE="$ROOT/source/$NAME"
EMHTTP="usr/local/emhttp/plugins/$NAME"
OUT="$ROOT/${NAME}.plg"

[ -d "$BASE/$EMHTTP" ] || { echo "missing staging tree: $BASE/$EMHTTP" >&2; exit 1; }

emit_file() {
  # $1 = path on disk, $2 = install path
  local disk="$1" dest="$2"
  case "$disk" in
    *.png|*.gif|*.jpg|*.jpeg|*.ico)
      # binary: base64-decode into place via a bash FILE (CDATA-wrapped so < > are safe)
      printf '  <FILE Run="/bin/bash">\n'
      printf '    <INLINE><![CDATA[\n'
      printf 'mkdir -p "%s"\n' "$(dirname "$dest")"
      printf "base64 -d > \"%s\" <<'B64'\n" "$dest"
      base64 "$disk"
      printf 'B64\n'
      printf ']]></INLINE>\n'
      printf '  </FILE>\n\n'
      ;;
    *)
      # text: embed verbatim in CDATA
      printf '  <FILE Name="%s">\n' "$dest"
      printf '    <INLINE><![CDATA[\n'
      sed 's/\r$//' "$disk"
      printf ']]></INLINE>\n'
      printf '  </FILE>\n\n'
      ;;
  esac
}

{
  cat <<XML
<?xml version='1.0' standalone='yes'?>
<!DOCTYPE PLUGIN [
  <!ENTITY name      "$NAME">
  <!ENTITY author    "$AUTHOR">
  <!ENTITY version   "$VER">
  <!ENTITY github    "$GITHUB">
  <!ENTITY plugin    "/boot/config/plugins/&name;">
  <!ENTITY emhttp    "/usr/local/emhttp/plugins/&name;">
  <!ENTITY pluginURL "https://raw.githubusercontent.com/&github;/master/&name;.plg">
]>

<PLUGIN name="&name;"
        author="&author;"
        version="&version;"
        pluginURL="&pluginURL;"
        support="https://github.com/&github;"
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
