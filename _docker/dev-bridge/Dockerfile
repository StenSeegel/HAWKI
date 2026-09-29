# =====================================================
# HAWKI Dev Bridge
# =====================================================
# Makes services that only accept connections from the staging host
# (ki-test) reachable from a developer's local stack, by relaying TCP
# through an SSH connection to that host.
#
# The official nginx image is the base because it ships the stream module
# with ssl_preread, which is what routes several TLS hosts that share one
# listen port (443) apart by SNI. Everything else is socat + ssh.
# =====================================================
FROM nginx:1.27-alpine

ARG HTTP_PROXY
ARG HTTPS_PROXY
ARG NO_PROXY

RUN apk add --no-cache openssh-client socat

COPY entrypoint.sh relay.sh healthcheck.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/entrypoint.sh /usr/local/bin/relay.sh /usr/local/bin/healthcheck.sh

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
