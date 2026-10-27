# NiceTryBot edge for self-hosting (deploy/README.md). Two images from one file, for whatever platform
# Docker builds on (amd64, arm64). Needs BuildKit (Docker's default builder):
#
#   docker build -t nicetry/edge .                    Caddy with the NiceTryBot modules (the default)
#   docker build --target vector -t nicetry/vector .  Vector with deploy/vector, shipping the edge's log
#
# The production edges build the same binary with caddy/build.sh.

ARG CADDY_VERSION=2.11.7
ARG VECTOR_VERSION=0.58.0

# Caddy plus Redis cert storage, JA3/JA4 fingerprinting and the nicetry-capture module: the modules and
# versions in caddy/build.sh. The module's tests run first.
FROM caddy:${CADDY_VERSION}-builder AS builder
ARG CADDY_VERSION
WORKDIR /src/nicetry-capture
COPY caddy/nicetry-capture/go.mod caddy/nicetry-capture/go.sum ./
RUN --mount=type=cache,target=/go/pkg/mod go mod download
COPY caddy/nicetry-capture/ ./
RUN --mount=type=cache,target=/go/pkg/mod --mount=type=cache,target=/root/.cache/go-build \
    go test ./...
RUN --mount=type=cache,target=/go/pkg/mod --mount=type=cache,target=/root/.cache/go-build \
    xcaddy build "v${CADDY_VERSION}" \
      --with github.com/pberkel/caddy-storage-redis@v1.8.2 \
      --with github.com/josuebrunel/caddy-ja3ja4@v0.2.0 \
      --with nicetrybot.com/caddy/capture=/src/nicetry-capture \
      --output /usr/bin/caddy

# Vector, configured by deploy/vector; its entrypoint picks the log mode from EDGE_LOGS_BUCKET.
FROM timberio/vector:${VECTOR_VERSION}-alpine AS vector
COPY --chmod=0755 deploy/vector/entrypoint.sh deploy/vector/secret.sh /etc/vector/nicetry/
COPY deploy/vector/vector.yaml deploy/vector/direct.yaml deploy/vector/bucket.yaml /etc/vector/nicetry/
RUN mkdir -p /var/lib/vector /run/nicetry/dbip
ENTRYPOINT ["/etc/vector/nicetry/entrypoint.sh"]

# The edge. Caddy runs as caddy (uid 1000); the entrypoint starts as root only to hand it the volumes.
FROM caddy:${CADDY_VERSION} AS edge
RUN apk add --no-cache ca-certificates curl su-exec \
    && addgroup -S -g 1000 caddy \
    && adduser -S -D -H -u 1000 -G caddy -h /data -s /sbin/nologin caddy
# Installed and given the low-ports capability in one step, so the image holds the binary once.
RUN --mount=type=bind,from=builder,source=/usr/bin/caddy,target=/tmp/caddy \
    install -m 0755 /tmp/caddy /usr/bin/caddy \
    && setcap cap_net_bind_service=+ep /usr/bin/caddy \
    && caddy list-modules | grep -q '^http.handlers.nicetry_origins$' \
    && caddy list-modules | grep -q '^caddy.storage.redis$' \
    && caddy list-modules | grep -q '^http.handlers.ja3_ja4$'
COPY deploy/Caddyfile /etc/caddy/Caddyfile
COPY deploy/Caddyfile.d/ /etc/caddy/Caddyfile.d/
COPY --chmod=0755 deploy/edge-entrypoint.sh /usr/local/bin/nicetry-edge
COPY --chmod=0755 deploy/dbip-fetch /usr/local/bin/dbip-fetch
RUN mkdir -p /data/caddy /data/nicetry /config/caddy /var/log/caddy /opt/dbip \
    && chown -R caddy:caddy /data /config /var/log/caddy /opt/dbip
EXPOSE 80 443
# Healthy once Caddy answers on port 80 (its nginx-style redirect, which isn't logged); the first start
# waits up to 5 minutes for DB-IP before that.
HEALTHCHECK --interval=10s --timeout=3s --start-period=6m --retries=3 \
    CMD ["curl", "-fsS", "-o", "/dev/null", "http://127.0.0.1/"]
ENTRYPOINT ["/usr/local/bin/nicetry-edge"]
CMD ["run"]
