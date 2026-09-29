# Dev Bridge

Some services HAWKI depends on accept connections from the staging host only —
the firewall rules are per source IP, so the identical request that works on
ki-test times out on a developer machine:

| Service | Address | Used for |
|---|---|---|
| Diarization (pyannote) | `https://hrz-spark-03.hrz.uni-giessen.de/diarization/v1` | speaker analysis, phase 1 + 2 of batch transcription |
| S3 bucket store (MinIO) | `https://bucket-store.hrz.uni-giessen.de:9001` | audio uploads, chunks, manifests |
| Staging realtime bridge | `ki-test.hrz.uni-giessen.de:8089` | live transcription signaling (optional, see below) |

The `dev-bridge` service relays TCP to those hosts through an SSH connection to
the staging machine, so the dev stack reaches them the way staging does.

## How it works

```
app / queue / audio-ingest / nginx
        │  https://hrz-spark-03.hrz.uni-giessen.de/…
        │  (docker DNS: the hostname is an alias of dev-bridge)
        ▼
   dev-bridge ──ssh session──▶ ki-test ──nc──▶ real service
        │
        └─ several TLS hosts on :443 are split by SNI (nginx stream)
```

Two properties make it transparent:

- **The upstream hostnames are network aliases of the container**, so every
  service in the stack resolves them to the bridge without any URL change.
- **Nothing is terminated.** TLS runs end to end between the dev container and
  the real server, so certificates, SNI and signed S3 URLs all stay valid.

`ssh -L` is not used: staging's sshd carries JLU's hardening drop-in
(`/etc/ssh/sshd_config.d/50-jlustd.conf`) with `AllowTcpForwarding no`, and any
forward is refused with *administratively prohibited*. Running a command is
allowed, so each connection is spliced to `nc` on staging. A pool of SSH master
connections stays open and every relay multiplexes over one of them, because
sshd's `MaxSessions` (10) caps the channels of a single connection.

## Setup

1. You need an SSH login on the staging host with key authentication (a
   passphrase-free key, or your agent — see below).

2. In `_docker/env/.env`:

   ```dotenv
   COMPOSE_PROFILES=bridge
   BRIDGE_SSH_TARGET=your-account@ki-test.hrz.uni-giessen.de
   BRIDGE_SSH_KEY=/Users/you/.ssh/id_rsa       # the key that logs in there

   # use the same services staging uses
   S3_ENDPOINT=https://bucket-store.hrz.uni-giessen.de:9001
   S3_ACCESS_KEY=…                              # staging's S3 credentials
   S3_SECRET_KEY=…
   ```

3. Set the diarization server in the admin UI (**Extensions → Transcription
   Service**) to `https://hrz-spark-03.hrz.uni-giessen.de/diarization/v1` and
   enter its API key.

4. `./deploy-dev.sh` — `COMPOSE_PROFILES=bridge` brings the bridge up with the
   rest of the stack, and the nginx config is regenerated so the browser's
   `/s3` uploads go to the same bucket store.

Check it:

```bash
docker compose -f _docker/compose/docker-compose.dev.yml logs dev-bridge
docker exec hawki-dev-app curl -s -o /dev/null -w '%{http_code}\n' \
    https://bucket-store.hrz.uni-giessen.de:9001/minio/health/live      # 200
docker exec hawki-dev-app curl -s -o /dev/null -w '%{http_code}\n' \
    https://hrz-spark-03.hrz.uni-giessen.de/diarization/v1/models       # 403 without a key, 200 with one
```

## Live transcription

On Docker Desktop the local `realtime-bridge` cannot work: it needs host
networking for WebRTC media, and its ICE candidates then carry addresses of the
Linux VM that the browser on macOS cannot reach. Two ways out, both using
staging:

- **Relay the signaling** (closest to staging behaviour): set
  `REALTIME_BRIDGE_URL=http://dev-bridge:8089`. Laravel sends the browser's SDP
  offer to staging's realtime bridge — with *your* gateway credentials, which
  travel per request — and the answer carries relay candidates from staging's
  coturn, which the browser reaches on `ki-test:6443`.
- **Borrow only the TURN server** and keep testing the local bridge: leave
  `REALTIME_BRIDGE_URL` unset and set `TURN_URLS`, `TURN_USERNAME` and
  `TURN_PASSWORD` to staging's coturn.

The realtime gateway itself (`api.hrz.uni-giessen.de`) is reachable without the
bridge, so batch transcription via the gateway needs no route.

## Adding a route

`BRIDGE_ROUTES` is a list of `host:port[=listen_port]`:

- `example.hrz.uni-giessen.de:443` — the bridge listens on 443 and relays
  there. Add the hostname to the `aliases` of the `dev-bridge` service in
  `docker-compose.dev.yml`, otherwise nothing resolves to the bridge.
- `example.hrz.uni-giessen.de:8089=8089` — no alias; address it as
  `dev-bridge:8089`. Use this when the hostname must keep resolving normally
  (`ki-test.hrz.uni-giessen.de` also serves coturn and the web UI).

Several TLS hosts may share a listen port; they are told apart by SNI.

## Settings

| Variable | Default | Meaning |
|---|---|---|
| `BRIDGE_SSH_TARGET` | — (required) | `user@host` of the staging machine |
| `BRIDGE_SSH_PORT` | `22` | SSH port there |
| `BRIDGE_SSH_KEY` | `$HOME/.ssh/id_rsa` | key mounted read-only at `/ssh/id` |
| `BRIDGE_KNOWN_HOSTS` | `$HOME/.ssh/known_hosts` | mounted at `/ssh/known_hosts`; without it host keys are accepted on first use |
| `BRIDGE_ROUTES` | the three routes above | route list |
| `BRIDGE_SSH_MASTERS` | `4` | master connections; concurrent relays are capped at masters × 10 |
| `BRIDGE_RELAY_CMD` | `nc -N %h %p` | remote command per connection |
| `BRIDGE_SSH_OPTS` | — | extra `ssh` options |

An agent works instead of a key file: mount the agent socket into the container
and set `SSH_AUTH_SOCK` (on Docker Desktop,
`/run/host-services/ssh-auth.sock`). If no key is found at `/ssh/id` the bridge
uses the agent.

## Limits and troubleshooting

- **Throughput** is that of one SSH channel, ~25 MB/s to ki-test — fine for
  audio, not a substitute for the real network path when measuring performance.
- **Concurrency** is `BRIDGE_SSH_MASTERS × 10`. Raise the master count before
  raising transcription concurrency.
- **`administratively prohibited`** in the log means someone switched the
  bridge back to port forwarding; it relays through `nc`, not `-L`.
- **`permissions are too open`** cannot happen — the key is copied and chmodded
  inside the container — but a *passphrase-protected* key fails silently in
  `BatchMode`. Use the agent instead.
- **Unhealthy container**: the healthcheck fails when no master connection is
  alive or a listen port is gone. `docker logs` shows the reconnect attempts.
- The bridge only changes where connections come from. A 403 from diarization
  is a key problem, not a network one.

## Security

The bridge gives every container in the dev stack the network reach of your
staging account for the configured routes, and your private key sits in the
container (read-only, mode 600). Keep it a dev-only profile: never enable it in
a staging or production stack, and never bake credentials into an image.
