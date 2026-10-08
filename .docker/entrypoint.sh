#!/bin/sh
set -e

# Allow `docker run image php -v` etc. to bypass the server.
if [ "$#" -gt 0 ]; then
    exec "$@"
fi

mkdir -p /run/nginx

# Fail fast on broken config instead of crash-looping inside multirun.
php-fpm -t >/dev/null 2>&1 || php-fpm -t
nginx -t -q

# tini (PID 1) reaps zombies; the reaper kills orphaned Chromium and removes
# stale temp dirs that the per-request guard could not clean up.
exec multirun "php-fpm -F" "nginx -g 'daemon off;'" "/usr/local/bin/browsershot-reaper"
