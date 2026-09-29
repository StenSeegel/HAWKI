#!/bin/sh
# One relayed connection: socat hands us the client's socket on stdin/stdout
# and we splice it to `nc <host> <port>` running on the staging machine.
#
# The session multiplexes over one of the entrypoint's master connections, so
# there is no SSH handshake per connection. Connections are spread over the
# pool because a single sshd connection allows only MaxSessions (10) channels.
# If the chosen master is gone, ssh falls back to opening its own connection
# rather than dropping the request.
set -eu

host=$1
port=$2

masters=${BRIDGE_SSH_MASTERS:-4}
idx=$(( $$ % masters ))

remote_cmd=$(echo "$BRIDGE_RELAY_CMD" | sed -e "s/%h/$host/g" -e "s/%p/$port/g")

# shellcheck disable=SC2086
exec ssh -o ControlMaster=no -o ControlPath="${BRIDGE_RUN_DIR:-/run/bridge}/cm-$idx" \
    $BRIDGE_SSH_OPTS_RESOLVED "$BRIDGE_SSH_TARGET" "$remote_cmd"
