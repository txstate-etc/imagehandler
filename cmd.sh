#!/bin/bash

# the php apache base image sets STOPSIGNAL SIGWINCH, which is apache's graceful stop
trap killall SIGINT INT SIGHUP HUP SIGTERM TERM SIGQUIT QUIT SIGWINCH WINCH

killall() {
	kill -WINCH $HTTPDPID
	wait $HTTPDPID
	exit
}

(apache2-foreground)&
HTTPDPID=$!

LAST_CLEANUP=$(date +%s)
while true; do
	# exit when apache does, so kubernetes restarts the container instead of leaving it up serving nothing
	if ! kill -0 $HTTPDPID 2>/dev/null; then
		wait $HTTPDPID
		exit $?
	fi
	if [ $(date +%H%M) == "0900" ] && [ $(date -d "-1hour" +%s) -ge $LAST_CLEANUP ]; then
		LAST_CLEANUP=$(date +%s)
		find /var/cache/resize -mindepth 1 -mtime +${IMAGEHANDLERCLEANUPDAYS-60} -delete
		find /var/cache/resize -mindepth 1 -empty -type d -delete
	fi
	sleep 1
done
