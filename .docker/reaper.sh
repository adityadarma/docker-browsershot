#!/bin/sh
# Safety net for renders that the node guard could not clean up, e.g. when
# node itself was SIGKILLed before it could react.
#
#  - Kills Chromium browser processes whose node driver is gone. Puppeteer
#    launches Chromium detached, so when node dies Chromium is re-parented to
#    the init/subreaper (tini or multirun). Its whole process group goes too.
#  - Removes per-request work dirs older than the max render time.

INTERVAL="${BROWSERSHOT_REAPER_INTERVAL:-30}"
TEMP_DIR="${BROWSERSHOT_TEMP_PATH:-/tmp}"
# Max API timeout is 300s; anything older than this is certainly abandoned.
STALE_MINUTES="${BROWSERSHOT_REAPER_STALE_MINUTES:-10}"

log() {
    echo "[reaper] $*" >&2
}

while :; do
    sleep "$INTERVAL"

    # Note: node shows up as "MainThread" in comm, so match on the parent being
    # an init process rather than on the parent *not* being node.
    ps -o pid=,ppid=,comm=,args= | awk '
        { comm[$1] = $3; ppid[$1] = $2; args[$1] = $0 }
        END {
            for (pid in args) {
                if (comm[pid] != "chromium" || args[pid] ~ / --type=/) continue
                parent = ppid[pid]
                if (parent <= 1 || !(parent in comm) || comm[parent] == "tini" || comm[parent] == "multirun") print pid
            }
        }
    ' | while read -r pid; do
        log "killing orphaned chromium process group $pid"
        kill -9 -- "-$pid" 2>/dev/null || kill -9 "$pid" 2>/dev/null
    done

    find "$TEMP_DIR" -maxdepth 1 -type d -name 'browsershot-*' -mmin +"$STALE_MINUTES" 2>/dev/null \
        | while read -r dir; do
            log "removing stale $dir"
            rm -rf -- "$dir"
        done
done
