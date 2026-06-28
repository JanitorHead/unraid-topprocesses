# Top Processes — Unraid Dashboard Widget

A system-monitor tile for the **Unraid 7.x** Dashboard that shows the processes
consuming the most CPU/RAM — like `htop`, but in Unraid's native style. It fills the
gap left by the official Processor and System tiles, which show *how much* is used but
never *which process* is responsible.

![concept](docs/concept.txt)

- **Top‑N processes** with name, user and PID
- **CPU / MEM sort toggle** right in the tile header
- **Grey‑track / green‑fill bars** that match Unraid's look (all four themes)
- **Configurable refresh interval** (2 / 5 / 10 s / off) — and it really changes the cadence
- **Accurate %CPU** measured from `/proc` (htop/Irix semantics), not the misleading
  dashboard average
- Pauses polling when the browser tab is hidden

## Install

### Option A — Standalone (no hosting, try it now)

`topprocesses-standalone.plg` embeds everything inline.

1. Copy `topprocesses-standalone.plg` somewhere your Unraid box can read it, or push it
   to a GitHub raw URL.
2. Unraid → **Plugins → Install Plugin** → paste the URL (or a local path like
   `/boot/config/plugins/topprocesses-standalone.plg`) → **Install**.
3. Open the **Dashboard** — the *Top Processes* tile appears in the left column.

This is the fastest way to iterate: it writes straight into RAM and is recreated on
every boot, so no `.txz` build is needed.

### Option B — Released package (`.plg` + `.txz`)

For a proper, auto-updating GitHub release:

```bash
build/makepkg.sh 2026.06.28          # builds archive/topprocesses-<ver>-x86_64-1.txz + prints md5
# put the printed MD5 into topprocesses.plg (<!ENTITY md5 ...>)
```

Commit `topprocesses.plg` and the `.txz`, then install via the raw URL of
`topprocesses.plg`. `pluginURL` drives "update available" in the Plugin Manager.

## Development loop (fastest)

Skip packaging entirely while iterating: copy the staging tree straight onto a running
server and refresh the Dashboard.

```bash
scp -r source/topprocesses/usr/local/emhttp/plugins/topprocesses \
       root@TOWER:/usr/local/emhttp/plugins/
```

Verify: tile appears, gear opens Settings, CPU/MEM toggle works, interval select changes
cadence, bars animate, names/users/PIDs are correct, and the JSON endpoint
`http://TOWER/plugins/topprocesses/include/getprocs.php` returns sane numbers (compare
against `top -bn1 -o %CPU`). Switch the white/black/gray/azure themes to confirm colours.

## Layout

```
topprocesses.plg               Released-package manifest (.txz via GitHub)
topprocesses-standalone.plg    Self-contained inline manifest (generated)
source/topprocesses/...         Staging tree (the actual plugin files)
build/makepkg.sh                Builds the .txz
build/make-standalone-plg.sh    Regenerates topprocesses-standalone.plg from source
docs/superpowers/specs/         Design spec
```

After editing anything under `source/`, regenerate the standalone manifest:

```bash
build/make-standalone-plg.sh 2026.06.28
```

## Status

Authored on Windows; **not yet verified on a live Unraid box.** See the "Open questions"
section of the design spec for the handful of things to confirm on first install
(settings write path, %CPU accuracy vs htop, `autov()` availability, 7.2 column layout).

## License

GPL-2.0 (matches the Dynamix/webGui ecosystem this builds on).
