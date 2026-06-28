**Top Processes** — a native-looking Dashboard tile listing the processes that
consume the most CPU or memory, like `htop` but matching Unraid's aesthetic.
Shows process name, user and PID with threshold-coloured %CPU / %MEM bars and
absolute memory. Toggle CPU/MEM (click the active metric again to reverse) and
change the refresh interval right from the tile header.

_Note: do not start this file with a Markdown heading (`#`). Unraid renders it
into the Plugins page and only caps `**bold**` and `####` sizes — an `#` H1
shows the name oversized._

#### How %CPU is measured

CPU usage is computed from two `/proc` samples ~300 ms apart, using htop's Irix
semantics (100% = one full core). This avoids the well-known "100% in the
dashboard, 15% in htop" discrepancy. Cross-check on the console:

    top -bn1 -o %CPU | head -n 20

#### Settings

**Settings → Utilities → Top Processes** sets the defaults (number of rows,
default sort, refresh interval). The tile's own toggle and interval selector
override them for the current view.
