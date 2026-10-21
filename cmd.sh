#!/bin/bash

trap killall SIGINT INT SIGHUP HUP SIGTERM TERM SIGQUIT QUIT

killall() {
	kill $HTTPDPID
	exit
}

(
	. /etc/apache2/envvars
	mkdir -p "$APACHE_RUN_DIR" "$APACHE_LOCK_DIR" "$APACHE_LOG_DIR"
	rm -f "$APACHE_PID_FILE"
	exec apache2 -DFOREGROUND
)&
HTTPDPID=$!

LAST_CLEANUP=$(date +%s)
while true; do
	if [ $(date +%H%M) == "0900" ] && [ $(date -d "-1hour" +%s) -ge $LAST_CLEANUP ]; then
		LAST_CLEANUP=$(date +%s)
		su -c "find /var/cache/resize -delete -mtime +${IMAGEHANDLERCLEANUPDAYS-60}" www-data
		su -c "find /var/cache/resize -delete -empty -type d" www-data
	fi
	sleep 1
done
