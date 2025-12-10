#!/bin/sh
set -e  # Exit if error

# Run execute supervisord command
exec multirun "php-fpm84 -F" "nginx -g 'daemon off;'"