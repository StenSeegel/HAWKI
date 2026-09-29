#!/bin/sh
# =====================================================
# HAWKI Dev Bridge - entrypoint
# =====================================================
# Relays TCP connections from the local dev stack to hosts that only accept
# traffic coming from the staging machine (the firewall rules are per source
# IP, so the same request fails from a laptop and succeeds from ki-test).
#
# Why not `ssh -L`: the staging host's sshd carries JLU's hardening drop-in
# (/etc/ssh/sshd_config.d/50-jlustd.conf) with `AllowTcpForwarding no`, so
# every port forward is refused with "administratively prohibited". Running a
# command is allowed, so each connection is relayed through
# `ssh <staging> nc <host> <port>` instead.
#
# To avoid an SSH handshake per connection, a pool of master connections stays
# open and every relay multiplexes over one of them (ControlMaster). The pool
# has more than one member because sshd's MaxSessions (default 10) caps the
# channels of a *single* connection - parallel chunk transcription alone opens
# several at once, so BRIDGE_SSH_MASTERS x 10 is the real ceiling.
#
# Nothing is terminated here: TLS runs end to end between the dev container
# and the real server, so certificates, SNI and signed S3 URLs stay valid and
# no URL in HAWKI's configuration has to change. Dev containers find the
# bridge because it carries the upstream hostnames as docker network aliases
# (see the dev-bridge service in docker-compose.dev.yml).
# =====================================================
set -eu

log() { echo "[dev-bridge] $*"; }

: "${BRIDGE_SSH_TARGET:?BRIDGE_SSH_TARGET is required, e.g. user@ki-test.hrz.uni-giessen.de}"
BRIDGE_SSH_PORT=${BRIDGE_SSH_PORT:-22}
BRIDGE_SSH_MASTERS=${BRIDGE_SSH_MASTERS:-4}
BRIDGE_ROUTES=${BRIDGE_ROUTES:-}
BRIDGE_SSH_KEY_PATH=${BRIDGE_SSH_KEY_PATH:-/ssh/id}
BRIDGE_KNOWN_HOSTS_PATH=${BRIDGE_KNOWN_HOSTS_PATH:-/ssh/known_hosts}
BRIDGE_HOST_KEY_CHECK=${BRIDGE_HOST_KEY_CHECK:-accept-new}
# Remote relay command; %h / %p are replaced with the target host and port.
# `-N` half-closes the remote socket once the client is done sending, which is
# what lets a plain HTTP request finish instead of hanging on the response.
BRIDGE_RELAY_CMD=${BRIDGE_RELAY_CMD:-nc -N %h %p}

RUN_DIR=/run/bridge
mkdir -p "$RUN_DIR"

# ------------------------------------------------ ssh identity
# The key is bind-mounted read-only and arrives with the host's ownership,
# which ssh rejects ("permissions are too open"). Copy it in and fix the mode.
SSH_DIR=$RUN_DIR/ssh
mkdir -p "$SSH_DIR"
chmod 700 "$SSH_DIR"

IDENTITY_OPT=""
if [ -f "$BRIDGE_SSH_KEY_PATH" ]; then
    cp "$BRIDGE_SSH_KEY_PATH" "$SSH_DIR/id"
    chmod 600 "$SSH_DIR/id"
    IDENTITY_OPT="-i $SSH_DIR/id -o IdentitiesOnly=yes"
elif [ -n "${SSH_AUTH_SOCK:-}" ]; then
    log "no key at $BRIDGE_SSH_KEY_PATH, using the forwarded ssh agent"
else
    log "ERROR: no key at $BRIDGE_SSH_KEY_PATH and no ssh agent socket"
    exit 1
fi

# known_hosts: a real file is used as is. Anything else (missing, or the empty
# directory docker creates for a mount source that does not exist) falls back
# to trust on first use.
KNOWN_HOSTS=$SSH_DIR/known_hosts
if [ -f "$BRIDGE_KNOWN_HOSTS_PATH" ]; then
    cp "$BRIDGE_KNOWN_HOSTS_PATH" "$KNOWN_HOSTS"
else
    : > "$KNOWN_HOSTS"
    log "no known_hosts mounted, host key checking is '$BRIDGE_HOST_KEY_CHECK'"
fi
chmod 600 "$KNOWN_HOSTS"

SSH_OPTS="-p $BRIDGE_SSH_PORT $IDENTITY_OPT -o UserKnownHostsFile=$KNOWN_HOSTS -o StrictHostKeyChecking=$BRIDGE_HOST_KEY_CHECK -o BatchMode=yes -o ServerAliveInterval=30 -o ServerAliveCountMax=3 -o TCPKeepAlive=yes -o Compression=no ${BRIDGE_SSH_OPTS:-}"

# relay.sh runs per connection and reads its configuration from here.
export BRIDGE_SSH_TARGET BRIDGE_SSH_MASTERS BRIDGE_RELAY_CMD
export BRIDGE_SSH_OPTS_RESOLVED="$SSH_OPTS"
export BRIDGE_RUN_DIR="$RUN_DIR"

# ------------------------------------------------ routes
# Syntax: host:port[=listen_port], separated by commas, spaces or newlines.
ROUTES_FILE=$RUN_DIR/routes
: > "$ROUTES_FILE"

for route in $(echo "$BRIDGE_ROUTES" | tr ',\n\t' '   '); do
    [ -n "$route" ] || continue

    spec=${route%%=*}
    listen=${route#*=}
    [ "$listen" = "$route" ] && listen=${spec##*:}

    host=${spec%%:*}
    port=${spec##*:}

    if [ -z "$host" ] || [ -z "$port" ] || [ "$host" = "$spec" ]; then
        log "ERROR: cannot parse route '$route' (expected host:port[=listen_port])"
        exit 1
    fi

    echo "$host $port $listen" >> "$ROUTES_FILE"
done

if [ ! -s "$ROUTES_FILE" ]; then
    log "ERROR: BRIDGE_ROUTES is empty - nothing to bridge"
    exit 1
fi

log "target: $BRIDGE_SSH_TARGET (ssh port $BRIDGE_SSH_PORT), masters: $BRIDGE_SSH_MASTERS"
while read -r host port listen; do
    log "route:  :$listen -> $host:$port"
done < "$ROUTES_FILE"

# ------------------------------------------------ ssh master pool
i=0
while [ "$i" -lt "$BRIDGE_SSH_MASTERS" ]; do
    (
        idx=$i
        cp_path="$RUN_DIR/cm-$idx"
        while true; do
            # shellcheck disable=SC2086
            ssh -M -N -o ControlMaster=yes -o ControlPath="$cp_path" \
                $SSH_OPTS "$BRIDGE_SSH_TARGET" 2>&1 | sed "s/^/[master-$idx] /" || true
            log "master $idx disconnected, reconnecting in 5s"
            sleep 5
        done
    ) &
    i=$((i + 1))
done

# Wait for the first master so the first request does not race the handshake.
waited=0
until ssh -O check -o ControlPath="$RUN_DIR/cm-0" "$BRIDGE_SSH_TARGET" >/dev/null 2>&1; do
    waited=$((waited + 1))
    if [ "$waited" -gt 60 ]; then
        log "ERROR: no ssh master connection after 60s - check key, target and network"
        exit 1
    fi
    sleep 1
done
log "ssh master pool up"

# ------------------------------------------------ listeners
# A listen port with a single upstream is served by socat directly. Several
# TLS upstreams sharing a listen port (typically 443) are told apart by their
# SNI name in nginx's stream module and handed to per-route loopback sockets.
LISTEN_PORTS=$(awk '{print $3}' "$ROUTES_FILE" | sort -un)
NGINX_STREAMS=""
NGINX_MAPS=""
loopback_port=14000

for listen in $LISTEN_PORTS; do
    count=$(awk -v l="$listen" '$3 == l' "$ROUTES_FILE" | wc -l | tr -d ' ')

    if [ "$count" -eq 1 ]; then
        host=$(awk -v l="$listen" '$3 == l {print $1}' "$ROUTES_FILE")
        port=$(awk -v l="$listen" '$3 == l {print $2}' "$ROUTES_FILE")
        socat TCP-LISTEN:"$listen",fork,reuseaddr,bind=0.0.0.0 \
              EXEC:"/usr/local/bin/relay.sh $host $port" 2>&1 | sed "s/^/[relay:$listen] /" &
        continue
    fi

    map_entries=""
    default_upstream=""
    for host in $(awk -v l="$listen" '$3 == l {print $1}' "$ROUTES_FILE"); do
        port=$(awk -v l="$listen" -v h="$host" '$3 == l && $1 == h {print $2}' "$ROUTES_FILE")
        loopback_port=$((loopback_port + 1))
        socat TCP-LISTEN:"$loopback_port",fork,reuseaddr,bind=127.0.0.1 \
              EXEC:"/usr/local/bin/relay.sh $host $port" 2>&1 | sed "s/^/[relay:$host:$port] /" &
        map_entries="$map_entries        $host 127.0.0.1:$loopback_port;
"
        [ -n "$default_upstream" ] || default_upstream="127.0.0.1:$loopback_port"
    done

    NGINX_MAPS="$NGINX_MAPS
    map \$ssl_preread_server_name \$upstream_$listen {
        hostnames;
$map_entries        default $default_upstream;
    }
"
    NGINX_STREAMS="$NGINX_STREAMS
    server {
        listen $listen;
        ssl_preread on;
        proxy_pass \$upstream_$listen;
        proxy_timeout 1h;
        proxy_connect_timeout 30s;
    }
"
done

if [ -n "$NGINX_STREAMS" ]; then
    cat > /etc/nginx/nginx.conf <<NGINX
# Generated by the dev bridge entrypoint - do not edit.
daemon off;
error_log /dev/stdout info;
pid /run/bridge/nginx.pid;
events { worker_connections 1024; }
stream {
$NGINX_MAPS
$NGINX_STREAMS
}
NGINX
    log "SNI routing on port(s):$(echo "$NGINX_STREAMS" | awk '/listen/ {printf " %s", $2}' | tr -d ';')"
    nginx 2>&1 | sed 's/^/[sni] /' &
fi

trap 'log "shutting down"; kill 0' TERM INT

log "bridge ready"
wait
