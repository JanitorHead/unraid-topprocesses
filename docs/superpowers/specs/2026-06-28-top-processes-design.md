# Top Processes — Unraid 7.x Dashboard Widget (Design Spec)

**Date:** 2026-06-28
**Plugin id:** `topprocesses`
**Author:** rafablues94
**Target:** Unraid 7.x (min 6.12.0)

## 1. Goal

A native-looking Dashboard tile, **Top Processes**, that lists the top‑N processes
by CPU (with a CPU/MEM sort toggle) — like `htop` but matching Unraid's aesthetic.
Each row shows process name, user, PID, and two inline mini bars (grey track + green
fill) for %CPU and %MEM.

It deliberately does **not** render an aggregate CPU/RAM bar — the official Processor
and System tiles already do that. This widget fills the gap they leave: *which process
is actually consuming the resources.*

## 2. Architecture decisions

| Decision | Why |
|---|---|
| Register tile via `$mytiles[...]['column1']` in a separate `.page` (`Menu="Dashboard:0"`, `Cond` version guard) | Supported mechanism; never edit `DashStats.page` (overwritten on update). |
| Compute %CPU from two `/proc` samples (~300 ms) ourselves | Avoids `top`/`ps` column fragility; htop/Irix semantics; sidesteps the known Unraid-dashboard-vs-htop %CPU discrepancy. `ps` %CPU is a lifetime average — wrong for "busiest right now". |
| Plain JS polling of a PHP endpoint, configurable interval (default 5 s) | nchan would need a supervised daemon + `Nchan=` wiring for one tile. Polling mirrors `gpustat-unraid`. nchan is a documented future upgrade. |
| One endpoint returns **both** cpu- and mem-sorted lists | Sort toggle is instant/client-side, no extra round trip. |
| Per-process bars = grey track + green gradient fill | Matches "grey+green" ask, looks native, intentionally distinct from the global grey aggregate bars. |
| Reuse stock dynamix classes + CSS variables with hex fallbacks | Tracks all four themes without per-theme CSS. |
| Persist settings in `/boot/config/plugins/topprocesses/topprocesses.cfg` (flash) | Everything under `/usr/local/emhttp` is disposable/regenerated each boot. |
| Target PHP 8.3/8.4 + FA4 icons + vanilla JS | Survive Unraid 7.x's compatibility purge (no `each()`, no curly-brace offsets, no jQuery Mobile). |

## 3. File layout

Install path (RAM, recreated each boot from the package):

```
/usr/local/emhttp/plugins/topprocesses/
  TopProcessesDashboard.page     # registers the Dashboard tile + loads JS/CSS
  TopProcessesSettings.page      # Settings page (Top N, default sort, interval)
  include/TopProcesses.php        # builds the $mytiles tile HTML
  include/getprocs.php            # JSON endpoint: /proc sampling -> top-N cpu & mem
  javascript/topprocesses.js      # polling, rendering, sort toggle, interval selector
  styles/topprocesses.css         # per-row layout + grey/green mini bars
  default.cfg                     # shipped defaults (seeded to flash on first install)
  README.md
```

Persistent config (USB flash):

```
/boot/config/plugins/topprocesses/topprocesses.cfg   # KEY="value" ini, written via /update.php
```

Repo (build side):

```
unraid-topprocesses/
  topprocesses.plg                 # GitHub/.txz distribution manifest
  topprocesses-standalone.plg      # self-contained INLINE manifest (no hosting needed)
  source/topprocesses/usr/local/emhttp/plugins/topprocesses/...   # staging tree
  build/makepkg.sh                 # builds the .txz + prints md5/sha256
  build/make-standalone-plg.sh     # assembles the INLINE .plg from source files
  docs/superpowers/specs/...        # this spec
  README.md
```

## 4. Data collection (`include/getprocs.php`)

1. Read `/proc/stat` line 1 → sum fields = `total0` jiffies.
2. For each numeric `/proc/<pid>/`: read `stat` → `utime+stime` (`j0`), cache `comm`.
3. `usleep(300000)` (300 ms).
4. Read `/proc/stat` again → `total1`; re-read each pid's `stat` → `j1`.
5. `ncpu` = count of `cpu\d` lines in `/proc/stat`.
6. `cpu% = 100 * (j1-j0) / max(1,(total1-total0)) * ncpu` (Irix: 100% = one core).
7. `mem%` from `/proc/<pid>/statm` resident pages (×4 KiB) over `MemTotal`.
8. User via `fileowner('/proc/<pid>')` → `posix_getpwuid`.
9. Return `{ nproc, cpu:[{pid,user,cmd,cpu,mem}], mem:[…] }` (top-N each).

## 5. Realtime (`javascript/topprocesses.js`)

Vanilla JS module: polls `getprocs.php` on the selected interval (`busy` guard to
prevent overlap; pause on hidden tab). Renders rows with grey-track/green-fill bars.
CPU/MEM toggle and interval `<select>` live in the native `.tile-header-right-controls`
area. %-text colour escalates green → orange → red by threshold (mirrors Unraid load
colouring). Collapse/close chevrons are auto-injected by DashStats — we do not add them.

## 6. Settings (`TopProcessesSettings.page`)

Standard Dynamix settings form (`Menu="Utilities"`) posting to `/update.php` with a
hidden `#file=topprocesses/topprocesses.cfg`, writing `TOPN`, `SORT`, `INTERVAL`.
Shipped defaults: `TOPN="8"`, `SORT="cpu"`, `INTERVAL="5"`.

## 7. Packaging

- **`topprocesses-standalone.plg`** — every web file embedded as INLINE CDATA writing
  straight into `/usr/local/emhttp/...` (RAM, recreated each boot). Installable
  immediately via Plugins → Install Plugin with no GitHub/.txz hosting. Best for trying
  it now and iterating on the real box.
- **`topprocesses.plg` + `.txz`** — versioned Slackware package installed via
  `upgradepkg --install-new --reinstall`; `pluginURL` drives auto-update. For proper
  GitHub releases. `.txz` built with `build/makepkg.sh` on the Unraid box or CI.

## 8. Open questions (verify on a real Unraid 7.x box)

1. `/update.php` + `#file` writes the cfg correctly? (fallback: inline `file_put_contents`).
2. Does our `/proc`-delta %CPU match `htop`/`top`? Is 300 ms stable or too noisy?
3. Is `autov()` callable in the Dashboard `.page` body? (fallback: hardcoded `?v=`).
4. Unraid 7.2 two-column `.grid` bug — does it affect `column1` placement?
5. Does `posix_getpwuid()` resolve all uids (container/host mappings)?
6. Is `/plugins/topprocesses/include/getprocs.php` web-accessible + auth-gated as expected?

## 9. Non-duplication / native-feel guarantees

- No aggregate CPU/RAM bar — only a per-process list.
- Header uses stock `tile-header`/`.control`/`.section` markup + a `fa fa-cog` gear.
- Per-process bars use native green-gradient + grey-track tokens.
- CPU/MEM toggle and a real refresh-interval selector mirror Unraid's selector aesthetic
  (and, unlike the stock chart selectors, ours genuinely changes the data cadence).
