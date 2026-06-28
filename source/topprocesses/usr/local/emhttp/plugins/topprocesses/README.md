# Top Processes (Unraid dashboard widget)

A native-looking Dashboard tile listing the top processes by CPU or memory —
like `htop`, but matching Unraid's aesthetic. Shows process name, user, PID and
grey-track / green-fill bars for %CPU and %MEM. Toggle CPU/MEM and change the
refresh interval right from the tile header; defaults live in **Settings →
Utilities → Top Processes**.

## How %CPU is measured

The tile computes CPU usage itself from two `/proc` samples taken ~300 ms apart,
using htop's Irix semantics (100 % = one full core). This avoids the well-known
"100 % in the dashboard, 15 % in htop" discrepancy.

Cross-check on the console:

```
top -bn1 -o %CPU | head -n 20
```

The numbers should track htop closely. If they look noisy, the sampling window
can be widened in `include/getprocs.php` (`usleep(300000)`).
