# rinha-backend-2026-php

Pure-PHP submission for [Rinha de Backend 2026](https://github.com/zanfranceschi/rinha-de-backend-2026).
Twin sibling of [`rinha-backend-2026-php-rust`](https://github.com/jonas-elias/rinha-backend-2026-php-rust)
(score **5817.66**), but **without** any custom C/Rust extensions or FFI calls.
Same algorithm, **PHP-only** end-to-end.

## Stack

* **PHP 8.3** + **OPcache JIT** (tracing) + **Swoole 6.2** (HTTP, UDS)
* **HAProxy 2.9** as load balancer (TCP balance over Unix sockets)
* **k-NN fraud score** com **IVF6** (int16 AoS, scale `1e-4`)
* Index gerado em **PHP puro** (`scripts/build_index.php` — kmeans++ + Lloyd
  com `pcntl_fork` para paralelismo)

## Layout

```
lib/                 código de produção
  Data.php           loader streaming do index IVF6 (mmap-style fread)
  Knn.php            hot path: top-N centroids → block scan int16
  Vector.php         encoding 14-dim de payloads JSON → vetor f32
php/
  server.php         Swoole HTTP server (UDS), 6 corpos estáticos
  preload.php        OPcache preload + warm-up dummy queries
  php.ini            JIT tracing, opcache reduzido (140 MB total budget)
scripts/
  preprocess.php     references.json.gz → refs.bin (AoS f32)
  build_index.php    kmeans++ + Lloyd 20 iter → IVF6 (sem Rust!)
  smoke.sh           E2E test: 50 payloads vs brute force
.specs/features/fraud-score-php/   spec / design / tasks (TLC SDD)
```

## Build

```bash
docker compose build
docker compose up -d
curl -fsS http://localhost:9999/ready
```

O `docker build` faz:

1. **Stage idx-build** (`php:8.3-cli`): roda `preprocess.php` → `refs.bin`,
   depois `build_index.php` → `index.bin` (~84 MB IVF6). Com PHP+JIT em CI
   linux/amd64 nativo, leva ~2-4 h. Cacheado pelo BuildKit; só re-roda se
   `references.json.gz` ou os scripts mudarem.
2. **Stage runtime** (`php:8.3-cli`): instala Swoole via PECL, copia o index,
   sobe o server.

> 💡 Se você for só rodar local e quer pular o build do índice de 4 h, copie
> o `index_q.bin` já buildado de `rinha-backend-2026-php-rust` (mesmo formato).

## Smoke test

```bash
./scripts/smoke.sh
# espera: 0 / 50 mismatches
```

## Performance budget

| componente              | RAM      | CPU |
|-------------------------|----------|-----|
| `submission-lb-1`       | 20 MB    | 0.2 |
| `submission-api{1,2}-1` | 165 MB   | 0.4 |
| **total**               | 350 MB   | 1.0 |

In-memory:

* index IVF6 ~84 MB
* OPcache 16 MB + JIT 16 MB
* Swoole runtime + request buffers ~30 MB
* PHP `memory_limit` = 140 MB

## Why no FFI / no Rust extension?

The sibling project (`rinha-backend-2026-php-rust`) already explores that path.
This repo is the *purist* alternative: extract the maximum throughput from PHP
alone using OPcache JIT + Swoole. No custom `.so`. No `dl()`. The Swoole
extension is the only non-stdlib runtime dependency — it ships via PECL and
is idiomatic for PHP HTTP servers.
