# Two /proc/stat snapshots, separated by a line "--": the busiest CPU's busy and softirq shares,
# and the whole machine's busy share, over the interval
/^--/ { second = 1; next }
/^cpu[0-9]/ {
    busy = $2 + $3 + $4 + $7 + $8 + $9; total = busy + $5 + $6
    if (!second) { b[$1] = busy; t[$1] = total; s[$1] = $8 }
    else {
        dt = total - t[$1]; if (dt <= 0) next
        bp = 100 * (busy - b[$1]) / dt; sp = 100 * ($8 - s[$1]) / dt
        if (bp > maxb) maxb = bp; if (sp > maxs) { maxs = sp; maxscpu = $1 }
        sumb += busy - b[$1]; sumt += dt
    }
}
END { printf "busy=%.0f%% max_cpu_busy=%.0f%% max_softirq=%.0f%%(%s)\n", 100 * sumb / sumt, maxb, maxs, maxscpu }
