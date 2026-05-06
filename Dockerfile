# syntax=docker/dockerfile:1.7

# ============================================================================
# Stage 1 — Build do índice em PHP puro:
#   1. preprocess.php : references.json.gz (50 MB) -> refs.bin AoS (163 MB)
#   2. build_index.php: kmeans++ + Lloyd + IVF6 -> index.bin (~84 MB)
#
# Sem Rust, sem C, sem extensões custom. Apenas pcntl_fork + JIT do PHP.
# ============================================================================
FROM --platform=linux/amd64 php:8.3-cli-bookworm AS idx-build

COPY certs/ /usr/local/share/ca-certificates/
RUN apt-get update && apt-get install -y --no-install-recommends ca-certificates && \
    update-ca-certificates 2>/dev/null || true

# JIT acelera o k-means em ~3-5x. pcntl é built-in.
RUN echo "opcache.enable_cli=1\nopcache.jit_buffer_size=128M\nopcache.jit=tracing\nmemory_limit=2G" \
    > /usr/local/etc/php/conf.d/zz-build.ini && \
    docker-php-ext-enable opcache

WORKDIR /work
COPY scripts/preprocess.php ./preprocess.php
COPY scripts/build_index.php ./build_index.php
COPY resources/references.json.gz ./resources/references.json.gz

ARG IDX_WORKERS=4
ENV IDX_WORKERS=${IDX_WORKERS}

RUN mkdir -p /out && \
    php preprocess.php resources/references.json.gz /out/refs.bin && \
    ls -la /out/refs.bin

RUN php build_index.php /out/refs.bin /out/index.bin ${IDX_WORKERS} && \
    ls -la /out/index.bin

# ============================================================================
# Stage 2 — runtime PHP puro (Swoole + OPcache JIT).
# ============================================================================
FROM --platform=linux/amd64 php:8.3-cli-bookworm AS runtime

COPY certs/ /usr/local/share/ca-certificates/
RUN apt-get update && apt-get install -y --no-install-recommends \
        ca-certificates \
        autoconf gcc g++ make pkg-config \
        libssl-dev libcurl4-openssl-dev libc-ares-dev libbrotli-dev zlib1g-dev && \
    update-ca-certificates 2>/dev/null || true

# Swoole (PECL) — extensão pública padrão (não é "extensão nova").
RUN pecl channel-update pecl.php.net && \
    pecl install --configureoptions 'enable-openssl="yes" enable-swoole-curl="no" enable-cares="no" enable-brotli="no"' swoole-6.2.0 && \
    docker-php-ext-enable swoole opcache

# Limpeza
RUN apt-get purge -y --auto-remove \
        autoconf gcc g++ make pkg-config \
        libssl-dev libcurl4-openssl-dev libc-ares-dev libbrotli-dev zlib1g-dev && \
    rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/* /tmp/pear

WORKDIR /app

# Lib + server + php.ini + preload
COPY lib /app/lib
COPY php /app
COPY --from=idx-build /out/index.bin /app/data/index_q.bin

# Sanity: confirma que carregamos as extensões certas (sem .so custom).
RUN php -c /app/php.ini -m | sort && \
    INDEX_PATH=/app/data/index_q.bin \
    php -c /app/php.ini -r 'require "/app/lib/Data.php"; \App\Data::init(); echo "OK n=" . \App\Data::$n . "\n";'

ENV INDEX_PATH=/app/data/index_q.bin
ENV SOCK=/run/sock/api1.sock

CMD ["php", "-c", "/app/php.ini", "/app/server.php"]
