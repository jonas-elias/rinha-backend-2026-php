# syntax=docker/dockerfile:1.7

# ============================================================================
# Stage 0 — Extrai o índice pré-construído da imagem publicada no Docker Hub.
#   Evita o build de 70 min do k-means; apenas reutiliza o index_q.bin.
# ============================================================================
FROM jonaselias/rinha-2026-php:latest AS data-source
# (nada a fazer; apenas serve como origem do COPY abaixo)

# ============================================================================
# Stage 1 — Compila knn.c em knn.so (Debian bookworm = mesma ABI do runtime).
# ============================================================================
FROM --platform=linux/amd64 debian:bookworm-slim AS c-builder
RUN apt-get update && apt-get install -y --no-install-recommends gcc libc6-dev && \
    rm -rf /var/lib/apt/lists/*
COPY lib/knn.c /build/knn.c
# -march=x86-64-v2: SSE4.2 — disponível em todos os servidores modernos;
# auto-vectoriza o inner loop de distâncias int16.
RUN gcc -O3 -march=x86-64-v2 -mtune=generic \
        -shared -fPIC -o /build/knn.so /build/knn.c -lm

# ============================================================================
# Stage 2 — Runtime: PHP 8.3 + Swoole + FFI (sem código de build do índice).
# ============================================================================
FROM --platform=linux/amd64 php:8.3-cli-bookworm AS runtime

COPY certs/ /usr/local/share/ca-certificates/
RUN apt-get update && apt-get install -y --no-install-recommends \
        ca-certificates \
        autoconf gcc g++ make pkg-config \
        libssl-dev libcurl4-openssl-dev libc-ares-dev libbrotli-dev zlib1g-dev \
        libffi-dev && \
    update-ca-certificates 2>/dev/null || true

# FFI é extensão bundled — compilada do source PHP disponível na imagem base.
RUN docker-php-ext-install ffi

# Swoole (PECL) — extensão pública padrão.
RUN pecl channel-update pecl.php.net && \
    pecl install --configureoptions 'enable-openssl="yes" enable-swoole-curl="no" enable-cares="no" enable-brotli="no"' swoole-6.2.0 && \
    docker-php-ext-enable swoole opcache ffi

# Limpeza
RUN apt-get purge -y --auto-remove \
        autoconf gcc g++ make pkg-config \
        libssl-dev libcurl4-openssl-dev libc-ares-dev libbrotli-dev zlib1g-dev && \
    rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/* /tmp/pear

WORKDIR /app

# Lib + server + php.ini + preload
COPY lib /app/lib
COPY php /app
# knn.so compilado no stage 1
COPY --from=c-builder /build/knn.so /app/lib/knn.so
# Índice pré-construído do stage 0 (evita rebuild de 70 min)
COPY --from=data-source /app/data/index_q.bin /app/data/index_q.bin

# Sanity check: valida extensões e carregamento do índice via FFI
RUN php -c /app/php.ini -m | sort && \
    INDEX_PATH=/app/data/index_q.bin \
    php -d opcache.preload= -d ffi.enable=true \
        -c /app/php.ini /app/sanity.php

ENV INDEX_PATH=/app/data/index_q.bin
ENV SOCK=/run/sock/api1.sock

CMD ["php", "-c", "/app/php.ini", "/app/server.php"]
