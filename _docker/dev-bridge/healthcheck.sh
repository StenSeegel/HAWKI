#!/bin/sh
# Healthy means: at least one ssh master connection is alive and every
# configured listen port accepts connections. A dead master alone is not fatal
# (relay.sh falls back to a direct ssh), but it is what usually breaks first,
# so the container reports unhealthy and restart policies can act on it.
set -eu

RUN_DIR=${BRIDGE_RUN_DIR:-/run/bridge}
ROUTES_FILE=$RUN_DIR/routes

[ -s "$ROUTES_FILE" ] || exit 1

alive=0
for cp in "$RUN_DIR"/cm-*; do
    [ -S "$cp" ] || continue
    if ssh -O check -o ControlPath="$cp" "$BRIDGE_SSH_TARGET" >/dev/null 2>&1; then
        alive=1
        break
    fi
done
[ "$alive" -eq 1 ] || exit 1

# Listening sockets are read from the kernel rather than dialled: connecting
# to a listen port would start a real relay session upstream on every check.
listening=$(netstat -ltn 2>/dev/null | awk '{print $4}' | sed 's/.*://')
for listen in $(awk '{print $3}' "$ROUTES_FILE" | sort -un); do
    echo "$listening" | grep -qx "$listen" || exit 1
done

exit 0
