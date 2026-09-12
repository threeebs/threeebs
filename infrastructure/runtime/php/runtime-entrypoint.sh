#!/bin/sh
set -eu
umask 077
install -d -m 0700 "$APACHE_RUN_DIR" "$APACHE_LOCK_DIR" "$APACHE_LOG_DIR"
exec "$@"
