#!/bin/sh
set -e  # Exit if error

if [ "$(id -u)" -eq 0 ]; then
  echo "❌ Tidak boleh jalan sebagai root"
  exit 1
fi

# Change file supervisord
envsubst < /etc/supervisord.conf.template > /etc/supervisord.conf

# Run execute supervisord command
exec /usr/bin/supervisord -c /etc/supervisord.conf