# Top Processes — Unraid Dashboard Widget

A system-monitor tile for the **Unraid 7.x** Dashboard that shows the processes
consuming the most CPU/RAM — like `htop`, but in Unraid's native style. It fills the
gap left by the official Processor and System tiles, which show *how much* is used but
never *which process* is responsible.

![screenshot](docs/screenshot.png)

- **Top‑N processes** with name, user and PID
- **CPU / MEM toggle** in the header that doubles as the sort key — click the active
  metric again to **reverse** direction (htop's `r`)
- **Threshold‑coloured bars** (green → orange → red) that match Unraid's look on all
  four themes, plus **absolute memory** (e.g. `1.2 GiB`) next to MEM%
- **Busiest process highlighted**, **honest empty/stale states**, basic a11y
- **Configurable refresh interval** (2 / 5 / 10 s / off) — and it really changes cadence
- **Accurate %CPU** measured from `/proc` (htop/Irix semantics, 100 % = one core),
  not the misleading dashboard average
- Pauses polling when the browser tab is hidden

## Install

Unraid → **Plugins → Install Plugin** → paste the raw URL of `topprocesses.plg`:

```
https://raw.githubusercontent.com/JanitorHead/unraid-topprocesses/master/topprocesses.plg
```

Then open the **Dashboard** — the *Top Processes* tile appears in the left column.
The `.plg` is self-contained (every file embedded inline), so there is no separate
`.txz` to host; auto-update works by bumping `<!ENTITY version>` in the `.plg`.

## Development loop

`topprocesses.plg` is generated from `source/` — never hand-edit it. After changing
anything under `source/topprocesses/...`, regenerate:

```bash
build/make-standalone-plg.sh 2026.06.28     # writes topprocesses.plg from source/
```

Fastest iteration on a live box (skip packaging): copy the staging tree straight onto
the server and refresh the Dashboard.

```bash
scp -r source/topprocesses/usr/local/emhttp/plugins/topprocesses \
       root@TOWER:/usr/local/emhttp/plugins/
```

Verify the data endpoint at `http://TOWER/plugins/topprocesses/include/getprocs.php`
(compare against `top -bn1 -o %CPU`), and switch the white/black/gray/azure themes.

## Publishing to Community Applications

1. Push this repo to GitHub as `JanitorHead/unraid-topprocesses` (public), add the
   topics `unraid` and `unraid-plugin`.
2. Ensure `LICENSE` (MIT), `ca_profile.xml`, `plugins/topprocesses.xml` and
   `images/topprocesses.png` are present (they are). `PluginURL` in
   `plugins/topprocesses.xml` must match `pluginURL` in `topprocesses.plg` byte-for-byte.
3. (Recommended) Create a `[Plugin] Top Processes` support thread on the Unraid forum
   and put its URL in `support=` (the `.plg`), `<Support>` and `ca_profile.xml`'s `<Forum>`.
4. Submit at **https://ca.unraid.net/submit** → Validate → Scan → submit. Checklist:
   https://ca.unraid.net/submit/help
5. Each release: bump `<!ENTITY version>` (date `YYYY.MM.DD`), regenerate `topprocesses.plg`,
   commit. Installed users and the CA listing auto-update via `pluginURL`.

## Layout

```
topprocesses.plg                Canonical inline installer (generated — do not edit)
ca_profile.xml                  Community Applications developer/repo profile
plugins/topprocesses.xml        Community Applications plugin listing
images/topprocesses.png         Icon (CA listing)
source/topprocesses/...          The actual plugin files (edit here)
build/make-standalone-plg.sh    Regenerates topprocesses.plg from source/
build/makepkg.sh                Optional Slackware .txz build (not required)
docs/superpowers/specs/         Design spec
```

## License

[MIT](LICENSE).
