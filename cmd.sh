#!/bin/bash

trap killall SIGINT INT SIGHUP HUP SIGTERM TERM SIGQUIT QUIT

killall() {
	kill $HTTPDPID
	exit
}

(apache2-foreground)&
HTTPDPID=$!

LAST_CLEANUP=$(date +%s)
while true; do
	if [ $(date +%H%M) == "0900" ] && [ $(date -d "-1hour" +%s) -ge LAST_CLEANUP ]; then
		LAST_CLEANUP=$(date +%s)
		find /var/cache/resize -name data -delete -mtime +${IMAGEHANDLERCLEANUPDAYS-60}
	fi
	sleep 1
done
