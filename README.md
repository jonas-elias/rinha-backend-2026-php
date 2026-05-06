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
   depois `build_index.php` → `index.bin` (~84 MB IVF6). Em PHP+JIT amd64
   nativo (4 fork workers) leva **~70 min**. Cacheado pelo BuildKit; só re-roda
   se `references.json.gz` ou os scripts de build mudarem.
2. **Stage runtime** (`php:8.3-cli`): instala Swoole via PECL, copia o index,
   sobe o server.

> 💡 Se quiser pular o build de 70 min, copie o `index_q.bin` já buildado de
> [`rinha-backend-2026-php-rust`](https://github.com/jonas-elias/rinha-backend-2026-php-rust)
> (mesmo formato IVF6).

## Smoke test

```bash
./scripts/smoke.sh
# espera: 0 / 50 mismatches
```

✅ Validado: **0 / 50 mismatches** vs brute force — paridade total com a
versão Rust.

## Rodando o teste de carga oficial

A submissão é avaliada pelo k6 do repo
[`zanfranceschi/rinha-de-backend-2026`](https://github.com/zanfranceschi/rinha-de-backend-2026)
sob limite estrito de **1 vCPU + 350 MB** distribuído entre `api1`, `api2` e `lb`.

```bash
docker compose up -d
git clone https://github.com/zanfranceschi/rinha-de-backend-2026
cd rinha-de-backend-2026
./run.sh                   # k6 ramping up to 900 RPS por 120 s
cat test/results.json | jq # p99 + score
```

> ⚠️ Em mac com Rancher Desktop emulando amd64 (2 vCPU total), o teste satura
> porque o k6 sozinho consome ~1 vCPU e a submissão precisa de 1 vCPU isolado.
> Resultado representativo só sai em **linux/amd64 nativo** com ≥4 cores físicos
> (qualquer máquina pessoal recente). A versão sibling
> [`rinha-2026-php-rust`](https://github.com/jonas-elias/rinha-backend-2026-php-rust)
> nessas condições fechou **final_score = 5817.66**.

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
