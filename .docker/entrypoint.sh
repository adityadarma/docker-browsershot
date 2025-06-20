#!/bin/sh
set -e  # Exit if error

# Change file supervisord
envsubst < /etc/supervisord.conf.template > /etc/supervisord.conf

# Run execute supervisord command
exec /usr/bin/supervisord -c /etc/supervisord.conf